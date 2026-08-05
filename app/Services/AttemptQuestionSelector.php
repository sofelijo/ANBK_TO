<?php

namespace App\Services;

use App\Enums\QuestionStatus;
use App\Models\Assessment;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttemptQuestionSelector
{
    public function select(Assessment $assessment, User $student): Collection
    {
        $settings = $assessment->settings ?? [];
        $mode = data_get($settings, 'selection_mode', 'manual');

        if ($mode === 'manual') {
            return $assessment->questions()->with('options')->get();
        }

        $targetCount = (int) data_get($settings, 'question_count', $assessment->questions()->count());
        $candidateIds = collect(data_get($settings, 'candidate_question_ids', []))->map(fn ($id): int => (int) $id);
        $baseQuery = Question::query()
            ->with('options')
            ->where('school_id', $assessment->school_id)
            ->where('grade_level', $assessment->grade_level)
            ->when(
                $candidateIds->isNotEmpty(),
                fn (Builder $query) => $query->whereIn('id', $candidateIds),
                fn (Builder $query) => $query->where('status', QuestionStatus::Published),
            );

        if ($mode === 'competency') {
            return $this->selectByRows(
                $baseQuery,
                data_get($settings, 'competency_rows', []),
                $student->id,
                fn (Builder $query, array $row): Builder => $query->where('competency_id', $row['competency_id']),
                'Komposisi kompetensi tidak dapat dipenuhi oleh bank soal.',
            );
        }

        if ($mode === 'blueprint') {
            return $this->selectByRows(
                $baseQuery,
                data_get($settings, 'blueprint_rows', []),
                $student->id,
                fn (Builder $query, array $row): Builder => $query
                    ->where('competency_id', $row['competency_id'])
                    ->where('type', $row['type'])
                    ->where('difficulty', $row['difficulty']),
                'Komposisi blueprint tidak dapat dipenuhi oleh bank soal.',
            );
        }

        $questions = $this->prioritizeForStudent($baseQuery, $student->id)
            ->limit($targetCount)
            ->get();

        $this->ensureCount($questions, $targetCount, 'Jumlah soal dalam pool belum mencukupi paket ini.');

        return $questions;
    }

    private function selectByRows(
        Builder $baseQuery,
        array $rows,
        int $studentId,
        callable $applyRow,
        string $error,
    ): Collection {
        $questions = new Collection;

        foreach ($rows as $row) {
            $query = $applyRow(clone $baseQuery, $row)
                ->whereNotIn('id', $questions->pluck('id'));
            $selected = $this->prioritizeForStudent($query, $studentId)
                ->limit((int) $row['count'])
                ->get();

            $this->ensureCount($selected, (int) $row['count'], $error);
            $questions->push(...$selected);
        }

        return $questions;
    }

    private function prioritizeForStudent(Builder $query, int $studentId): Builder
    {
        $studentUsage = DB::table('attempt_question')
            ->join('attempts', 'attempts.id', '=', 'attempt_question.attempt_id')
            ->whereColumn('attempt_question.question_id', 'questions.id')
            ->where('attempts.user_id', $studentId)
            ->selectRaw('COUNT(attempts.id)');

        return $query
            ->orderBy($studentUsage)
            ->inRandomOrder();
    }

    private function ensureCount(Collection $questions, int $expected, string $message): void
    {
        if ($questions->count() !== $expected) {
            throw ValidationException::withMessages(['assessment' => $message]);
        }
    }
}
