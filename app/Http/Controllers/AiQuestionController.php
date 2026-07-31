<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\QuestionType;
use App\Jobs\GenerateQuestionVariants;
use App\Models\AiGeneration;
use App\Models\Question;
use App\Services\AI\AiManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AiQuestionController extends Controller
{
    public function store(Request $request, Question $question, AiManager $manager): RedirectResponse
    {
        abort_unless($question->school_id === $request->user()->school_id, 404);

        if (in_array($question->type, [QuestionType::Matching, QuestionType::CategoryMatrix], true)) {
            throw ValidationException::withMessages([
                'ai' => 'Variasi AI untuk tipe soal ini belum tersedia. Soal tetap dapat diedit dan divalidasi AI.',
            ]);
        }

        $dailyUsage = AiGeneration::query()
            ->where('requested_by', $request->user()->id)
            ->where('type', AiGenerationType::QuestionVariants)
            ->whereDate('created_at', today())
            ->count();

        if ($dailyUsage >= config('ai.daily_question_limit')) {
            throw ValidationException::withMessages([
                'ai' => 'Kuota pembuatan variasi AI hari ini sudah habis.',
            ]);
        }

        $provider = $manager->provider();
        $payload = ['question_id' => $question->id, 'variant_count' => 3];
        $generation = AiGeneration::create([
            'school_id' => $question->school_id,
            'requested_by' => $request->user()->id,
            'source_question_id' => $question->id,
            'type' => AiGenerationType::QuestionVariants,
            'status' => AiGenerationStatus::Pending,
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'input_hash' => hash('sha256', json_encode($payload).$question->updated_at?->timestamp),
            'request_payload' => $payload,
        ]);

        GenerateQuestionVariants::dispatch($generation->id);

        return back()->with('success', 'Pembuatan tiga variasi soal sudah masuk antrean.');
    }
}
