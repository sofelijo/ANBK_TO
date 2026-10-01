<?php

namespace App\Observers;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Models\AiGeneration;
use App\Notifications\ActionNotification;

class AiGenerationObserver
{
    public function updated(AiGeneration $generation): void
    {
        if (! $generation->wasChanged('status')
            || ! in_array($generation->status, [AiGenerationStatus::Completed, AiGenerationStatus::Failed], true)
            || in_array($generation->type, [AiGenerationType::AttemptSummary, AiGenerationType::StudentChat], true)) {
            return;
        }

        $requester = $generation->requester()->first();
        if (! $requester) {
            return;
        }

        $failed = $generation->status === AiGenerationStatus::Failed;
        $label = match ($generation->type) {
            AiGenerationType::StoryQuestions => 'Pembuatan soal AI',
            AiGenerationType::StoryIllustration => 'Pembuatan ilustrasi AI',
            AiGenerationType::QuestionVariants => 'Pembuatan variasi soal',
            AiGenerationType::QuestionValidation => 'Pemeriksaan kualitas soal',
            AiGenerationType::SchoolAssessmentAnalysis => 'Analisis hasil sekolah',
            default => 'Proses AI',
        };

        $requester->notify(new ActionNotification(
            $failed ? "{$label} gagal" : "{$label} selesai",
            $failed
                ? "{$label} belum berhasil diproses. Buka halaman terkait untuk mencoba kembali."
                : "{$label} telah selesai dan hasilnya siap ditinjau.",
            $this->url($generation),
            $failed ? 'warning' : 'success',
        ));
    }

    private function url(AiGeneration $generation): string
    {
        return match ($generation->type) {
            AiGenerationType::StoryQuestions => route('story-questions.show', $generation, absolute: false),
            AiGenerationType::StoryIllustration => route(
                'story-questions.show',
                (int) data_get($generation->request_payload, 'story_generation_id'),
                absolute: false,
            ),
            AiGenerationType::QuestionVariants, AiGenerationType::QuestionValidation => route(
                'questions.show',
                $generation->source_question_id,
                absolute: false,
            ),
            AiGenerationType::SchoolAssessmentAnalysis => route('monitoring.index', absolute: false),
            default => route('dashboard', absolute: false),
        };
    }
}
