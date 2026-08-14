<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\QuestionStatus;
use App\Jobs\GenerateStoryQuestions;
use App\Models\AiGeneration;
use App\Models\Competency;
use App\Models\Question;
use App\Models\QuestionBlueprint;
use App\Models\Subject;
use App\Services\AI\AiManager;
use App\Services\AI\StoryIllustrationService;
use App\Services\AuditLogger;
use App\Services\TeacherAiQuota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AiStoryQuestionController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $subjects = Subject::query()
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->whereHas('competencies')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'ai_question_format']);
        $selectedSubjectId = $subjects->contains('id', $request->integer('subject_id'))
            ? $request->integer('subject_id')
            : null;
        $selectedSubject = $subjects->firstWhere('id', $selectedSubjectId);

        if ($selectedSubject && $request->routeIs('story-questions.create') && $selectedSubject->ai_question_format === 'direct') {
            return to_route('ai-questions.create', ['subject_id' => $selectedSubject->id]);
        }

        return Inertia::render('Questions/StoryCreate', [
            'subjects' => $subjects,
            'selectedSubjectId' => $selectedSubjectId,
            'generationFormat' => $selectedSubject?->ai_question_format ?? 'direct',
            'competencies' => Competency::query()
                ->where(fn ($query) => $query
                    ->whereNull('school_id')
                    ->orWhere('school_id', $request->user()->school_id))
                ->whereIn('subject_id', $subjects->pluck('id'))
                ->orderBy('grade_level')
                ->orderByRaw('COALESCE(parent_id, id)')
                ->orderBy('parent_id')
                ->orderBy('code')
                ->get(['id', 'subject_id', 'parent_id', 'code', 'name', 'grade_level']),
            'questionBlueprints' => QuestionBlueprint::query()
                ->where(fn ($query) => $query
                    ->whereNull('school_id')
                    ->orWhere('school_id', $request->user()->school_id))
                ->whereIn('subject_id', $subjects->pluck('id'))
                ->with('competencies:id')
                ->orderBy('name')
                ->get(['id', 'subject_id', 'code', 'name', 'description'])
                ->map(fn (QuestionBlueprint $blueprint): array => [
                    ...$blueprint->only(['id', 'subject_id', 'code', 'name', 'description']),
                    'competency_ids' => $blueprint->competencies->pluck('id'),
                ]),
            'recentGenerations' => AiGeneration::query()
                ->where('school_id', $request->user()->school_id)
                ->where('requested_by', $request->user()->id)
                ->where('type', AiGenerationType::StoryQuestions)
                ->latest()
                ->limit(10)
                ->get(['id', 'status', 'request_payload', 'result_payload', 'created_at']),
        ]);
    }

    public function store(Request $request, AiManager $manager, AuditLogger $auditLogger, TeacherAiQuota $quota): RedirectResponse
    {
        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'root_competency_id' => ['nullable', 'integer'],
            'competency_id' => ['nullable', 'integer'],
            'question_blueprint_ids' => ['array'],
            'question_blueprint_ids.*' => ['integer', 'distinct'],
            'theme' => ['nullable', 'string', 'max:5000'],
            'question_style' => ['nullable', Rule::in(['direct', 'reasoning'])],
            'answer_format' => ['nullable', Rule::in(['single_choice', 'true_false', 'multiple_choice', 'mixed'])],
            'use_illustration' => ['nullable', 'boolean'],
            'paragraph_count' => ['nullable', 'integer', 'between:1,5'],
            'question_count' => ['required', 'integer', 'between:1,9'],
        ]);
        $subject = Subject::query()
            ->whereKey($data['subject_id'])
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->whereHas('competencies')
            ->first();

        if (! $subject) {
            throw ValidationException::withMessages([
                'subject_id' => 'Mata pelajaran belum memiliki kompetensi yang dapat dipakai.',
            ]);
        }
        $selectedCompetency = $this->selectedCompetency($request, $subject, $data);
        $questionBlueprints = QuestionBlueprint::query()
            ->whereIn('id', $data['question_blueprint_ids'] ?? [])
            ->where('subject_id', $subject->id)
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->get(['id', 'code', 'name', 'description']);
        if ($questionBlueprints->count() !== count($data['question_blueprint_ids'] ?? [])) {
            throw ValidationException::withMessages([
                'question_blueprint_ids' => 'Ada tipe soal yang tidak tersedia.',
            ]);
        }
        $generationFormat = $subject->ai_question_format === 'story' ? 'story' : 'direct';
        if ($generationFormat === 'story' && ! in_array((int) $data['question_count'], [2, 3, 4], true)) {
            throw ValidationException::withMessages([
                'question_count' => 'Paket cerita hanya mendukung 2–4 soal.',
            ]);
        }
        $theme = trim($data['theme'] ?? '');
        if ($generationFormat === 'story' && $theme === '') {
            throw ValidationException::withMessages(['theme' => 'Tema cerita wajib diisi.']);
        }
        $questionStyle = $generationFormat === 'direct' ? ($data['question_style'] ?? 'direct') : 'reasoning';
        $answerFormat = $generationFormat === 'direct' ? ($data['answer_format'] ?? 'single_choice') : 'mixed';
        if ($answerFormat === 'mixed' && (int) $data['question_count'] < 2) {
            throw ValidationException::withMessages([
                'answer_format' => 'Format campuran membutuhkan minimal 2 soal.',
            ]);
        }
        $useIllustration = $generationFormat === 'story' || (bool) ($data['use_illustration'] ?? false);

        $quota->ensureAvailable($request->user(), AiGenerationType::StoryQuestions, 'theme');

        $provider = $manager->provider();
        $payload = [
            'subject_id' => $subject->id,
            'subject_name' => $subject->name,
            'format' => $generationFormat,
            'root_competency_id' => $selectedCompetency?->parent_id ?: $selectedCompetency?->id,
            'competency_id' => $selectedCompetency?->id,
            'competency_code' => $selectedCompetency?->code,
            'competency_name' => $selectedCompetency?->name,
            'question_blueprints' => $questionBlueprints->map->only(['id', 'code', 'name', 'description'])->values()->all(),
            'theme' => $theme !== '' ? $theme : ($selectedCompetency?->name ?? $subject->name),
            'example_question' => $generationFormat === 'direct' && $theme !== '' ? $theme : null,
            'question_style' => $questionStyle,
            'answer_format' => $answerFormat,
            'use_illustration' => $useIllustration,
            'paragraph_count' => $generationFormat === 'story' ? (int) ($data['paragraph_count'] ?? 3) : 0,
            'question_count' => (int) $data['question_count'],
        ];
        $generation = AiGeneration::create([
            'school_id' => $request->user()->school_id,
            'requested_by' => $request->user()->id,
            'type' => AiGenerationType::StoryQuestions,
            'status' => AiGenerationStatus::Pending,
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'input_hash' => hash('sha256', json_encode($payload).$request->user()->school_id),
            'request_payload' => $payload,
        ]);

        $auditLogger->log($request, 'story_questions.requested', $generation, $payload);
        GenerateStoryQuestions::dispatch($generation->id);

        return to_route($generationFormat === 'story' ? 'story-questions.show' : 'ai-questions.show', $generation)
            ->with('success', $generationFormat === 'story'
                ? 'Tema diterima. AI sedang membuat cerita dan 2–4 soal draft.'
                : 'Topik diterima. AI sedang membuat soal draft tanpa mewajibkan cerita.');
    }

    private function selectedCompetency(Request $request, Subject $subject, array $data): ?Competency
    {
        $rootId = (int) ($data['root_competency_id'] ?? 0);
        if ($rootId === 0) {
            return null;
        }

        $available = fn ($query) => $query
            ->whereNull('school_id')
            ->orWhere('school_id', $request->user()->school_id);
        $root = Competency::query()
            ->whereKey($rootId)
            ->where('subject_id', $subject->id)
            ->whereNull('parent_id')
            ->where($available)
            ->first();

        if (! $root) {
            throw ValidationException::withMessages([
                'root_competency_id' => 'Kompetensi tidak tersedia untuk mata pelajaran yang dipilih.',
            ]);
        }

        if ($subject->code === 'BIND') {
            return $root;
        }

        $selectedId = (int) ($data['competency_id'] ?? $root->id);
        $selected = Competency::query()
            ->whereKey($selectedId)
            ->where('subject_id', $subject->id)
            ->where($available)
            ->where(fn ($query) => $query
                ->whereKey($root->id)
                ->orWhere('parent_id', $root->id))
            ->first();

        if (! $selected) {
            throw ValidationException::withMessages([
                'competency_id' => 'Subkompetensi tidak berada di bawah kompetensi yang dipilih.',
            ]);
        }

        if ($selected->is($root) && $root->children()->exists()) {
            throw ValidationException::withMessages([
                'competency_id' => 'Pilih subkompetensi yang akan digunakan oleh AI.',
            ]);
        }

        return $selected;
    }

    public function show(
        Request $request,
        AiGeneration $generation,
        StoryIllustrationService $illustrationService,
    ): Response {
        $this->ensureAccessible($request, $generation);
        $this->markStaleGenerationAsFailed($generation);

        $illustration = AiGeneration::query()
            ->where('school_id', $request->user()->school_id)
            ->where('type', AiGenerationType::StoryIllustration)
            ->where('input_hash', hash('sha256', "story-illustration:{$generation->id}"))
            ->latest()
            ->first();

        if ($illustration?->status === AiGenerationStatus::Pending
            && $illustration->updated_at->lt(now()->subMinute())) {
            $illustration->update([
                'status' => AiGenerationStatus::Failed,
                'error' => 'Pengiriman batch ilustrasi terhenti. Silakan coba lagi.',
            ]);
        }

        if ($illustration?->status === AiGenerationStatus::Processing
            && $illustrationService->shouldRefresh($illustration)) {
            $illustrationService->refresh($illustration);
            $illustration->refresh();
        }

        $questionIds = data_get($generation->result_payload, 'question_ids', []);
        $questions = Question::query()
            ->where('school_id', $request->user()->school_id)
            ->whereIn('id', $questionIds)
            ->with([
                'competency:id,code,name,grade_level',
                'questionBlueprint:id,code,name',
                'author:id,name',
                'approver:id,name',
                'options',
            ])
            ->get()
            ->sortBy(fn (Question $question) => array_search($question->id, $questionIds, true))
            ->values();

        return Inertia::render('Questions/StoryShow', [
            'generation' => [
                ...$generation->only(['id', 'status', 'request_payload', 'result_payload', 'created_at']),
                'error' => $generation->status === AiGenerationStatus::Failed
                    ? 'Soal belum berhasil dibuat. Silakan coba proses kembali.'
                    : null,
            ],
            'questions' => $questions,
            'illustration' => $illustration ? [
                ...$illustration->only(['id', 'status', 'created_at']),
                'error' => $illustration->status === AiGenerationStatus::Failed
                    ? 'Ilustrasi belum berhasil dibuat. Silakan coba kembali.'
                    : null,
            ] : null,
        ]);
    }

    public function retry(Request $request, AiGeneration $generation): RedirectResponse
    {
        $this->ensureAccessible($request, $generation);
        $this->markStaleGenerationAsFailed($generation);

        if ($generation->status !== AiGenerationStatus::Failed) {
            throw ValidationException::withMessages([
                'generation' => 'Permintaan ini masih diproses atau sudah selesai.',
            ]);
        }

        if (data_get($generation->result_payload, 'question_ids', []) !== []) {
            throw ValidationException::withMessages([
                'generation' => 'Draft soal sudah terbentuk dan tidak boleh dibuat ulang.',
            ]);
        }

        $generation->update([
            'status' => AiGenerationStatus::Pending,
            'result_payload' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_microusd' => 0,
            'error' => null,
        ]);

        GenerateStoryQuestions::dispatch($generation->id);

        return back()->with('success', 'Pembuatan soal cerita dijalankan kembali.');
    }

    public function publishBundle(
        Request $request,
        AiGeneration $generation,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->ensureAccessible($request, $generation);

        if (data_get($generation->request_payload, 'format') === 'direct') {
            throw ValidationException::withMessages([
                'generation' => 'Soal langsung tidak menggunakan bundle. Verifikasi setiap soal secara terpisah.',
            ]);
        }

        if ($generation->status !== AiGenerationStatus::Completed) {
            throw ValidationException::withMessages([
                'generation' => 'Bundle hanya dapat diverifikasi setelah proses AI selesai.',
            ]);
        }

        $questionIds = collect(data_get($generation->result_payload, 'question_ids', []))
            ->filter(fn ($id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($questionIds->isEmpty()) {
            throw ValidationException::withMessages([
                'generation' => 'Bundle ini belum memiliki soal yang dapat diverifikasi.',
            ]);
        }

        $publishedCount = DB::transaction(function () use ($generation, $questionIds, $request): int {
            $questions = Question::query()
                ->where('school_id', $request->user()->school_id)
                ->where('story_generation_id', $generation->id)
                ->whereIn('id', $questionIds)
                ->lockForUpdate()
                ->get();

            if ($questions->count() !== $questionIds->count()) {
                throw ValidationException::withMessages([
                    'generation' => 'Sebagian soal bundle tidak ditemukan. Muat ulang halaman dan periksa kembali.',
                ]);
            }

            if ($questions->contains(fn (Question $question): bool => $question->status === QuestionStatus::Archived || $question->superseded_by_id !== null
            )) {
                throw ValidationException::withMessages([
                    'generation' => 'Bundle memuat soal yang sudah diarsipkan atau digantikan. Periksa soal satu per satu.',
                ]);
            }

            $publishable = $questions->where('status', '!=', QuestionStatus::Published);

            Question::query()
                ->whereIn('id', $publishable->pluck('id'))
                ->update([
                    'status' => QuestionStatus::Published,
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                ]);

            return $publishable->count();
        });

        if ($publishedCount === 0) {
            return back()->with('success', 'Seluruh soal dalam bundle sudah terbit.');
        }

        $auditLogger->log($request, 'story_bundle.published', $generation, [
            'question_ids' => $questionIds->all(),
            'question_count' => $publishedCount,
        ]);

        return back()->with('success', "{$publishedCount} soal dalam bundle berhasil diverifikasi dan diterbitkan.");
    }

    private function ensureAccessible(Request $request, AiGeneration $generation): void
    {
        abort_unless(
            $generation->school_id === $request->user()->school_id
            && $generation->type === AiGenerationType::StoryQuestions,
            404,
        );
    }

    private function markStaleGenerationAsFailed(AiGeneration $generation): void
    {
        $stalePending = $generation->status === AiGenerationStatus::Pending
            && $generation->updated_at->lt(now()->subMinute());
        $staleProcessing = $generation->status === AiGenerationStatus::Processing
            && $generation->updated_at->lt(now()->subMinutes(5));

        if ($stalePending || $staleProcessing) {
            $generation->update([
                'status' => AiGenerationStatus::Failed,
                'error' => 'Proses antrean terhenti. Silakan jalankan ulang permintaan ini.',
            ]);
        }
    }
}
