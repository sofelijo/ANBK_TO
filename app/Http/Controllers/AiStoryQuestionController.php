<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Jobs\GenerateStoryQuestions;
use App\Models\AiGeneration;
use App\Models\Question;
use App\Services\AI\AiManager;
use App\Services\AI\StoryIllustrationService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AiStoryQuestionController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Questions/StoryCreate', [
            'recentGenerations' => AiGeneration::query()
                ->where('school_id', $request->user()->school_id)
                ->where('requested_by', $request->user()->id)
                ->where('type', AiGenerationType::StoryQuestions)
                ->latest()
                ->limit(10)
                ->get(['id', 'status', 'request_payload', 'result_payload', 'created_at']),
        ]);
    }

    public function store(Request $request, AiManager $manager, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', 'string', 'max:255'],
            'paragraph_count' => ['required', 'integer', 'between:1,5'],
            'question_count' => ['required', 'integer', 'between:2,4'],
        ]);
        $theme = trim($data['theme']);

        $dailyUsage = AiGeneration::query()
            ->where('requested_by', $request->user()->id)
            ->where('type', AiGenerationType::StoryQuestions)
            ->whereDate('created_at', today())
            ->count();

        if ($dailyUsage >= config('ai.daily_story_limit')) {
            throw ValidationException::withMessages([
                'theme' => 'Kuota pembuatan soal cerita AI hari ini sudah habis.',
            ]);
        }

        $provider = $manager->provider();
        $payload = [
            'theme' => $theme,
            'paragraph_count' => (int) $data['paragraph_count'],
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

        return to_route('story-questions.show', $generation)
            ->with('success', 'Tema diterima. AI sedang membuat cerita dan 2–4 soal draft.');
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
            ->with(['competency:id,code,name,grade_level', 'options'])
            ->get()
            ->sortBy(fn (Question $question) => array_search($question->id, $questionIds, true))
            ->values();

        return Inertia::render('Questions/StoryShow', [
            'generation' => $generation->only([
                'id', 'status', 'model', 'request_payload', 'result_payload',
                'input_tokens', 'output_tokens', 'cost_microusd', 'error', 'created_at',
            ]),
            'questions' => $questions,
            'illustration' => $illustration?->only([
                'id', 'status', 'model', 'result_payload',
                'cost_microusd', 'error', 'created_at',
            ]),
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
