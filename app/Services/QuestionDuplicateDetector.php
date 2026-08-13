<?php

namespace App\Services;

use App\Enums\QuestionStatus;
use App\Models\Question;
use Illuminate\Support\Collection;

class QuestionDuplicateDetector
{
    public function candidates(Question $question): Collection
    {
        $question->loadMissing('competency:id,subject_id');

        return Question::query()
            ->where('school_id', $question->school_id)
            ->where('id', '!=', $question->id)
            ->whereNotIn('id', array_filter([$question->revision_of_id, $question->parent_id]))
            ->where(fn ($query) => $query
                ->whereNull('revision_of_id')
                ->orWhere('revision_of_id', '!=', $question->id))
            ->where(fn ($query) => $query
                ->whereNull('parent_id')
                ->orWhere('parent_id', '!=', $question->id))
            ->whereNull('superseded_by_id')
            ->where('status', '!=', QuestionStatus::Archived)
            ->where('grade_level', $question->grade_level)
            ->whereHas('competency', fn ($query) => $query->where('subject_id', $question->competency->subject_id))
            ->with('competency:id,code,name')
            ->get()
            ->map(function (Question $candidate) use ($question): array {
                $promptScore = $this->similarity($question->prompt, $candidate->prompt);
                $stimulusScore = $this->similarity($question->stimulus, $candidate->stimulus);
                $score = $stimulusScore > 0
                    ? ($promptScore * 0.8) + ($stimulusScore * 0.2)
                    : $promptScore;
                $exact = $this->normalize($question->prompt) === $this->normalize($candidate->prompt);

                return [
                    'id' => $candidate->id,
                    'title' => $candidate->title,
                    'prompt' => $candidate->prompt,
                    'status' => $candidate->status->value,
                    'competency' => $candidate->competency->code,
                    'similarity' => round($score * 100, 1),
                    'blocking' => $exact || $score >= 0.88,
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['similarity'] >= 65)
            ->sortByDesc('similarity')
            ->take(5)
            ->values();
    }

    public function hasBlockingDuplicate(Question $question): bool
    {
        return $this->candidates($question)->contains('blocking', true);
    }

    private function similarity(?string $left, ?string $right): float
    {
        $leftTokens = $this->tokens($left);
        $rightTokens = $this->tokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return 0;
        }

        $intersection = count(array_intersect($leftTokens, $rightTokens));
        $union = count(array_unique([...$leftTokens, ...$rightTokens]));

        return $union === 0 ? 0 : $intersection / $union;
    }

    private function tokens(?string $value): array
    {
        $normalized = $this->normalize($value);
        if ($normalized === '') {
            return [];
        }

        return array_values(array_unique(array_filter(explode(' ', $normalized), fn (string $token): bool => mb_strlen($token) > 1)));
    }

    private function normalize(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($value ?? ''))) ?? '');
    }
}
