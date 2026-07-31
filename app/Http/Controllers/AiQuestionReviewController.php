<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Jobs\ValidateQuestionQuality;
use App\Models\AiGeneration;
use App\Models\Question;
use App\Services\AI\AiManager;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AiQuestionReviewController extends Controller
{
    public function store(
        Request $request,
        Question $question,
        AiManager $manager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        abort_unless($question->school_id === $request->user()->school_id, 404);

        $provider = $manager->provider();
        $payload = ['question_id' => $question->id, 'updated_at' => $question->updated_at?->toIso8601String()];
        $generation = AiGeneration::create([
            'school_id' => $question->school_id,
            'requested_by' => $request->user()->id,
            'source_question_id' => $question->id,
            'type' => AiGenerationType::QuestionValidation,
            'status' => AiGenerationStatus::Pending,
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'input_hash' => hash('sha256', json_encode($payload)),
            'request_payload' => $payload,
        ]);
        ValidateQuestionQuality::dispatch($generation->id);
        $auditLogger->log($request, 'question.ai_review_requested', $question);

        return back()->with('success', 'Validasi kualitas AI masuk antrean. Hasilnya tidak menggantikan keputusan guru.');
    }
}
