<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Jobs\GenerateSchoolAssessmentAnalysis;
use App\Models\AiGeneration;
use App\Models\Assessment;
use App\Services\AI\AiManager;
use App\Services\AssessmentAiAnalysisData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MonitoringAiAnalysisController extends Controller
{
    public function store(
        Request $request,
        Assessment $assessment,
        AssessmentAiAnalysisData $analysisData,
        AiManager $manager,
    ): RedirectResponse {
        $schoolId = (int) $request->user()->school_id;
        $this->ensureAccessible($assessment, $schoolId);
        $context = $analysisData->build($assessment, $schoolId);

        if ($context['sample_size'] < 1 || $context['difficult_questions'] === []) {
            throw ValidationException::withMessages([
                'analysis' => 'Analisis AI tersedia setelah minimal satu siswa menyelesaikan paket dan memiliki jawaban yang dapat dianalisis.',
            ]);
        }

        $inProgress = AiGeneration::query()
            ->where('school_id', $schoolId)
            ->where('assessment_id', $assessment->id)
            ->where('type', AiGenerationType::SchoolAssessmentAnalysis)
            ->whereIn('status', [AiGenerationStatus::Pending, AiGenerationStatus::Processing])
            ->exists();

        if ($inProgress) {
            return back()->with('success', 'Analisis AI untuk paket ini masih diproses.');
        }

        $provider = $manager->provider();
        $generation = AiGeneration::create([
            'school_id' => $schoolId,
            'requested_by' => $request->user()->id,
            'assessment_id' => $assessment->id,
            'type' => AiGenerationType::SchoolAssessmentAnalysis,
            'status' => AiGenerationStatus::Pending,
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'input_hash' => $analysisData->hash($context),
            'request_payload' => $context,
        ]);

        GenerateSchoolAssessmentAnalysis::dispatch($generation->id);

        return back()->with('success', 'Analisis hasil sekolah dan pembuatan latihan AI sudah masuk antrean.');
    }

    private function ensureAccessible(Assessment $assessment, int $schoolId): void
    {
        abort_unless($assessment->attempts()
            ->whereHas('student', fn ($students) => $students->where('school_id', $schoolId))
            ->exists(), 404);
    }
}
