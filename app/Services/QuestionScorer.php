<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Models\Question;

class QuestionScorer
{
    public function isCorrect(Question $question, ?array $response): bool
    {
        if (! $response) {
            return false;
        }

        if ($question->type === QuestionType::ShortAnswer) {
            $answer = $this->normalize((string) ($response['text'] ?? ''));
            $acceptedAnswers = array_map(
                fn (mixed $acceptedAnswer): string => $this->normalize((string) $acceptedAnswer),
                $question->metadata['accepted_answers'] ?? [],
            );

            return $answer !== '' && in_array($answer, $acceptedAnswers, true);
        }

        if ($question->type === QuestionType::Matching) {
            $submitted = collect($response['matches'] ?? [])
                ->mapWithKeys(fn (mixed $rightId, mixed $leftId): array => [(string) $leftId => (string) $rightId])
                ->sortKeys()
                ->all();
            $answerKey = collect($question->metadata['matching_pairs'] ?? [])
                ->mapWithKeys(fn (array $pair): array => [(string) $pair['left_id'] => (string) $pair['right_id']])
                ->sortKeys()
                ->all();

            return $answerKey !== [] && $submitted === $answerKey;
        }

        if ($question->type === QuestionType::CategoryMatrix) {
            $submitted = collect($response['matrix_answers'] ?? [])
                ->mapWithKeys(fn (mixed $columnId, mixed $rowId): array => [(string) $rowId => (string) $columnId])
                ->sortKeys()
                ->all();
            $answerKey = collect($question->metadata['matrix_rows'] ?? [])
                ->mapWithKeys(fn (array $row): array => [(string) $row['id'] => (string) $row['correct_column_id']])
                ->sortKeys()
                ->all();

            return $answerKey !== [] && $submitted === $answerKey;
        }

        $selected = array_map('intval', $response['option_ids'] ?? []);
        $correct = $question->options
            ->where('is_correct', true)
            ->pluck('id')
            ->map(fn (int $id): int => $id)
            ->all();

        sort($selected);
        sort($correct);

        return $selected === $correct;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }
}
