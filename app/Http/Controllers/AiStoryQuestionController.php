<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateStoryQuestions;
use App\Models\AiGeneration;
use App\Models\Competency;
use App\Models\Question;
use App\Models\QuestionBlueprint;
use App\Models\Subject;
use App\Services\AI\AiManager;
use App\Services\AI\StoryIllustrationService;
use App\Services\AuditLogger;
use App\Services\IndonesianBundleConfiguration;
use App\Services\QuestionDuplicateDetector;
use App\Services\QuestionVerificationService;
use App\Services\TeacherAiQuota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AiStoryQuestionController extends Controller
{
    public function create(Request $request, IndonesianBundleConfiguration $bundleConfiguration): Response|RedirectResponse
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
                    'competency_positions' => $blueprint->competencies->mapWithKeys(fn (Competency $competency): array => [
                        $competency->id => $competency->pivot->position,
                    ]),
                ]),
            'indonesianBundleDefaults' => $bundleConfiguration->forUser($request->user()),
            'recentGenerations' => AiGeneration::query()
                ->where('school_id', $request->user()->school_id)
                ->where('requested_by', $request->user()->id)
                ->where('type', AiGenerationType::StoryQuestions)
                ->latest()
                ->limit(10)
                ->get(['id', 'status', 'request_payload', 'result_payload', 'created_at']),
        ]);
    }

    public function store(Request $request, AiManager $manager, AuditLogger $auditLogger, TeacherAiQuota $quota, IndonesianBundleConfiguration $bundleConfiguration): RedirectResponse
    {
        $requestedSubjectUsesIndonesianBundle = Subject::query()
            ->whereKey($request->integer('subject_id'))
            ->where('code', 'BIND')
            ->exists();

        if (! $requestedSubjectUsesIndonesianBundle) {
            $request->request->remove('bundle_slots');
        }

        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'root_competency_id' => ['nullable', 'integer'],
            'competency_id' => ['nullable', 'integer'],
            'question_blueprint_ids' => ['array'],
            'question_blueprint_ids.*' => ['integer', 'distinct'],
            'bundle_slots' => ['nullable', 'array', 'size:3'],
            'bundle_slots.*.question_blueprint_id' => ['required_with:bundle_slots', 'integer', 'distinct'],
            'bundle_slots.*.answer_format' => ['required_with:bundle_slots', Rule::in(IndonesianBundleConfiguration::ANSWER_FORMATS)],
            'bundle_slots.*.cognitive_level' => ['required_with:bundle_slots', Rule::in(IndonesianBundleConfiguration::COGNITIVE_LEVELS)],
            'theme' => ['nullable', 'string', 'max:5000'],
            'question_style' => ['nullable', Rule::in(['direct', 'reasoning'])],
            'answer_format' => ['nullable', Rule::in(['single_choice', 'true_false', 'multiple_choice', 'mixed'])],
            'difficulty' => ['nullable', 'integer', 'between:1,3'],
            'use_illustration' => ['nullable', 'boolean'],
            'illustration_mode' => ['nullable', Rule::in(['lite', 'pro'])],
            'paragraph_count' => ['nullable', 'integer', 'between:1,5'],
            'max_words' => ['nullable', 'integer', 'between:50,1000'],
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
        $availableBundleBlueprints = $selectedCompetency?->questionBlueprints()
            ->where('subject_id', $subject->id)
            ->get(['question_blueprints.id', 'code', 'name', 'description']) ?? collect();
        $usesIndonesianBundle = $subject->code === 'BIND' && $availableBundleBlueprints->count() === 3;
        $bundleSlots = [];
        if ($usesIndonesianBundle) {
            $submittedSlots = $data['bundle_slots'] ?? collect($bundleConfiguration->forUser($request->user()))
                ->values()
                ->map(fn (array $slot, int $index): array => [
                    'question_blueprint_id' => $availableBundleBlueprints[$index]->id,
                    ...$slot,
                ])->all();
            $bundleConfiguration->ensureComplete(
                collect($submittedSlots)->map(fn (array $slot): array => collect($slot)->only(['answer_format', 'cognitive_level'])->all())->all(),
                'bundle_slots',
            );
            $submittedBlueprintIds = collect($submittedSlots)->pluck('question_blueprint_id')->map(fn ($id): int => (int) $id);
            if ($submittedBlueprintIds->unique()->count() !== 3
                || $submittedBlueprintIds->sort()->values()->all() !== $availableBundleBlueprints->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'bundle_slots' => 'Bundle wajib menggunakan ketiga tipe soal milik kompetensi masing-masing satu kali.',
                ]);
            }
            $availableById = $availableBundleBlueprints->keyBy('id');
            $bundleSlots = collect($submittedSlots)->values()->map(fn (array $slot, int $index): array => [
                'position' => $index + 1,
                'question_blueprint_id' => (int) $slot['question_blueprint_id'],
                'answer_format' => $slot['answer_format'],
                'cognitive_level' => $slot['cognitive_level'],
                'cognitive_level_label' => $bundleConfiguration->levelLabel($slot['cognitive_level']),
            ])->all();
            $questionBlueprints = collect($bundleSlots)->map(function (array $slot) use ($availableById): array {
                $blueprint = $availableById[$slot['question_blueprint_id']];

                return [...$blueprint->only(['id', 'code', 'name', 'description']), ...$slot];
            });
        } else {
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
        }
        $generationFormat = $subject->ai_question_format === 'story' ? 'story' : 'direct';
        if ($generationFormat === 'story' && ! $usesIndonesianBundle && ! in_array((int) $data['question_count'], [2, 3, 4], true)) {
            throw ValidationException::withMessages([
                'question_count' => 'Paket cerita hanya mendukung 2–4 soal.',
            ]);
        }
        $theme = trim($data['theme'] ?? '');
        $questionStyle = $generationFormat === 'direct' ? ($data['question_style'] ?? 'direct') : 'reasoning';
        $answerFormat = $generationFormat === 'direct' ? ($data['answer_format'] ?? 'single_choice') : 'mixed';
        if ($answerFormat === 'mixed' && (int) $data['question_count'] < 2) {
            throw ValidationException::withMessages([
                'answer_format' => 'Format campuran membutuhkan minimal 2 soal.',
            ]);
        }
        $useIllustration = $generationFormat === 'story' || (bool) ($data['use_illustration'] ?? false);
        $illustrationMode = $generationFormat === 'story' ? 'pro' : ($data['illustration_mode'] ?? 'lite');

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
            'question_blueprints' => $questionBlueprints->values()->all(),
            'bundle_slots' => $bundleSlots,
            'theme' => $generationFormat === 'story'
                ? $theme
                : ($theme !== '' ? $theme : ($selectedCompetency?->name ?? $subject->name)),
            'theme_source' => $generationFormat === 'story' && $theme === '' ? 'ai' : 'user',
            'example_question' => $generationFormat === 'direct' && $theme !== '' ? $theme : null,
            'question_style' => $questionStyle,
            'answer_format' => $answerFormat,
            'difficulty' => $usesIndonesianBundle ? null : (int) ($data['difficulty'] ?? 2),
            'use_illustration' => $useIllustration,
            'illustration_mode' => $useIllustration ? $illustrationMode : null,
            'paragraph_count' => $generationFormat === 'story' ? (int) ($data['paragraph_count'] ?? 3) : 0,
            'max_words' => $generationFormat === 'story' ? (int) ($data['max_words'] ?? 200) : 0,
            'question_count' => $usesIndonesianBundle ? 3 : (int) $data['question_count'],
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
                'verifications.verifier:id,name',
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
            'questions' => $questions->map(fn (Question $question): array => [
                ...$question->toArray(),
                'verification' => $this->verificationSummary($question, $request),
            ]),
            'canVerify' => $request->user()->hasRole(UserRole::Teacher),
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
        QuestionDuplicateDetector $duplicateDetector,
        QuestionVerificationService $verificationService,
    ): RedirectResponse {
        $this->ensureAccessible($request, $generation);

        if (data_get($generation->request_payload, 'format') === 'direct') {
            throw ValidationException::withMessages([
                'generation' => 'Soal langsung tidak menggunakan bundle. Verifikasi setiap soal secara terpisah.',
            ]);
        }

        if ($generation->status !== AiGenerationStatus::Completed) {
            throw ValidationException::withMessages([
                'generation' => 'Bundle hanya dapat diverifikasi setelah paket selesai dibuat.',
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

        if (! $request->user()->hasRole(UserRole::Teacher)) {
            throw ValidationException::withMessages([
                'generation' => 'Hanya akun guru yang dapat memverifikasi bundle soal.',
            ]);
        }

        $questions = Question::query()
            ->where('school_id', $request->user()->school_id)
            ->where('story_generation_id', $generation->id)
            ->whereIn('id', $questionIds)
            ->with('verifications:id,question_id,verifier_id')
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

        $verifiable = $questions->reject(fn (Question $question): bool => $question
            ->verifications
            ->contains('verifier_id', $request->user()->id));

        if ($verifiable->isEmpty()) {
            return back()->with('success', 'Anda sudah memverifikasi seluruh soal dalam bundle ini.');
        }

        foreach ($verifiable as $index => $question) {
            if ($question->status !== QuestionStatus::Published && $duplicateDetector->hasBlockingDuplicate($question)) {
                throw ValidationException::withMessages([
                    'generation' => 'Soal nomor '.($index + 1).' terindikasi duplikat kuat. Periksa soal tersebut sebelum memverifikasi bundle.',
                ]);
            }
        }

        $results = $verifiable->map(fn (Question $question): array => $verificationService
            ->verify($question, $request->user()));
        $verifiedCount = $results->where('created', true)->count();
        $publishedCount = $results->where('published', true)->count();

        if ($verifiedCount === 0) {
            return back()->with('success', 'Anda sudah memverifikasi seluruh soal dalam bundle ini.');
        }

        $auditLogger->log($request, 'story_bundle.verified', $generation, [
            'question_ids' => $verifiable->pluck('id')->all(),
            'question_count' => $verifiedCount,
            'published_count' => $publishedCount,
        ]);

        if ($publishedCount > 0) {
            $auditLogger->log($request, 'story_bundle.published', $generation, [
                'question_ids' => $verifiable->pluck('id')->all(),
                'question_count' => $publishedCount,
            ]);
        }

        return back()->with(
            'success',
            $publishedCount > 0
                ? "Verifikasi Anda tercatat pada {$verifiedCount} soal; {$publishedCount} soal mencapai tiga verifikasi dan diterbitkan."
                : "Verifikasi Anda tercatat pada {$verifiedCount} soal. Tiga guru adalah batas minimal; verifikator tambahan tetap tercatat.",
        );
    }

    private function verificationSummary(Question $question, Request $request): array
    {
        $count = $question->verifications->count();

        return [
            'required' => Question::REQUIRED_VERIFICATIONS,
            'count' => $count,
            'remaining' => max(0, Question::REQUIRED_VERIFICATIONS - $count),
            'currentUserVerified' => $question->verifications->contains('verifier_id', $request->user()->id),
            'verifiers' => $question->verifications->map(fn ($verification): array => [
                'id' => $verification->verifier_id,
                'name' => $verification->verifier?->name ?? 'Guru tidak aktif',
                'verifiedAt' => $verification->verified_at,
            ])->values(),
        ];
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
