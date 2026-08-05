<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Question;
use Illuminate\Support\Facades\Storage;

class QuestionSnapshotService
{
    public function build(Question $question): array
    {
        $question->loadMissing('options');

        return [
            'schema_version' => 1,
            'question_id' => $question->id,
            'question_version' => $question->version,
            'competency_id' => $question->competency_id,
            'type' => $question->type->value,
            'title' => $question->title,
            'stimulus' => $question->stimulus,
            'prompt' => $question->prompt,
            'explanation' => $question->explanation,
            'difficulty' => $question->difficulty,
            'grade_level' => $question->grade_level,
            'cognitive_level' => $question->cognitive_level,
            'metadata' => $question->metadata,
            'options' => $question->options->map(fn ($option): array => [
                'id' => $option->id,
                'label' => $option->label,
                'content' => $option->content,
                'is_correct' => $option->is_correct,
                'position' => $option->position,
            ])->values()->all(),
        ];
    }

    public function forQuestion(Question $question): array
    {
        $snapshot = $this->decode($question->pivot?->snapshot);

        return $snapshot !== null
            ? $snapshot
            : $this->build($question);
    }

    public function hasSnapshot(Question $question): bool
    {
        return $this->decode($question->pivot?->snapshot) !== null;
    }

    public function snapshotAssessment(Assessment $assessment, bool $force = false): void
    {
        $assessment->loadMissing('questions.options');

        foreach ($assessment->questions as $question) {
            if (! $force && $this->decode($question->pivot->snapshot) !== null) {
                continue;
            }

            $assessment->questions()->updateExistingPivot($question->id, [
                'snapshot' => $this->build($question),
            ]);
        }

        $assessment->unsetRelation('questions');
    }

    public function illustrationUrl(array $snapshot): ?string
    {
        $disk = data_get($snapshot, 'metadata.illustration.disk');
        $path = data_get($snapshot, 'metadata.illustration.path');

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return null;
        }

        return Storage::disk($disk)->url($path);
    }

    private function decode(mixed $snapshot): ?array
    {
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        return is_array($snapshot) && $snapshot !== [] ? $snapshot : null;
    }
}
