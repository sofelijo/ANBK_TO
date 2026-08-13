<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UP_MAP = [5 => 6, 8 => 9, 11 => 12];

    public function up(): void
    {
        $this->migrateGrades(self::UP_MAP);
    }

    public function down(): void
    {
        $this->migrateGrades(array_flip(self::UP_MAP));
    }

    private function migrateGrades(array $mapping): void
    {
        foreach (['users', 'questions', 'assessments'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'grade_level')) {
                continue;
            }

            foreach ($mapping as $oldGrade => $newGrade) {
                DB::table($table)->where('grade_level', $oldGrade)->update(['grade_level' => $newGrade]);
            }
        }

        $this->migrateCompetencies($mapping);
        $this->migrateSnapshots($mapping);
        $this->migrateVisibleGradeLabels($mapping);
    }

    private function migrateCompetencies(array $mapping): void
    {
        if (! Schema::hasTable('competencies')) {
            return;
        }

        foreach ($mapping as $oldGrade => $newGrade) {
            $competencies = DB::table('competencies')->where('grade_level', $oldGrade)->get(['id', 'school_id', 'code']);

            foreach ($competencies as $competency) {
                $newCode = preg_replace('/(?<=[A-Za-z])'.preg_quote((string) $oldGrade, '/').'(?=-|$)/', (string) $newGrade, $competency->code);
                $updates = ['grade_level' => $newGrade];

                if ($newCode !== $competency->code && ! $this->competencyCodeExists($competency->school_id, $newCode, $competency->id)) {
                    $updates['code'] = $newCode;
                }

                DB::table('competencies')->where('id', $competency->id)->update($updates);
            }
        }
    }

    private function competencyCodeExists(?int $schoolId, string $code, int $exceptId): bool
    {
        return DB::table('competencies')
            ->where('code', $code)
            ->where('id', '!=', $exceptId)
            ->where(function ($query) use ($schoolId): void {
                $schoolId === null ? $query->whereNull('school_id') : $query->where('school_id', $schoolId);
            })
            ->exists();
    }

    private function migrateSnapshots(array $mapping): void
    {
        if (! Schema::hasTable('assessment_question') || ! Schema::hasColumn('assessment_question', 'snapshot')) {
            return;
        }

        DB::table('assessment_question')->whereNotNull('snapshot')->orderBy('id')->select(['id', 'snapshot'])->each(function (object $row) use ($mapping): void {
            $snapshot = json_decode($row->snapshot, true);
            if (! is_array($snapshot)) {
                return;
            }

            $grade = $snapshot['grade_level'] ?? null;
            if (isset($mapping[$grade])) {
                $snapshot['grade_level'] = $mapping[$grade];
                DB::table('assessment_question')->where('id', $row->id)->update([
                    'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
    }

    private function migrateVisibleGradeLabels(array $mapping): void
    {
        $columns = [
            'competencies' => ['name', 'description'],
            'questions' => ['title', 'stimulus', 'prompt', 'explanation'],
            'assessments' => ['title', 'description'],
        ];

        foreach ($columns as $table => $textColumns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->orderBy('id')->select(['id', ...$textColumns])->each(function (object $row) use ($table, $textColumns, $mapping): void {
                $updates = [];
                foreach ($textColumns as $column) {
                    $value = $row->{$column};
                    if (! is_string($value) || $value === '') {
                        continue;
                    }

                    $updated = $this->replaceGradeLabels($value, $mapping);
                    if ($updated !== $value) {
                        $updates[$column] = $updated;
                    }
                }

                if ($updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            });
        }
    }

    private function replaceGradeLabels(string $value, array $mapping): string
    {
        uksort($mapping, fn (int $left, int $right): int => $right <=> $left);

        foreach ($mapping as $oldGrade => $newGrade) {
            $value = str_replace(
                ["Kelas {$oldGrade}", "kelas {$oldGrade}"],
                ["Kelas {$newGrade}", "kelas {$newGrade}"],
                $value,
            );
        }

        return $value;
    }
};
