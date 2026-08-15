<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONTENT_COLUMNS = [
        'ai_generations' => ['request_payload', 'result_payload', 'error'],
        'assessment_question' => ['snapshot'],
        'assessments' => ['title', 'description', 'settings'],
        'attempt_answers' => ['response'],
        'attempt_events' => ['payload'],
        'attempt_question' => ['snapshot'],
        'attempts' => ['summary'],
        'audit_logs' => ['metadata'],
        'chat_messages' => ['source_key', 'content', 'metadata'],
        'competencies' => ['domain', 'name', 'description'],
        'question_options' => ['content'],
        'question_reviews' => ['issues', 'suggestions'],
        'questions' => ['title', 'stimulus', 'prompt', 'explanation', 'cognitive_level', 'metadata'],
        'recommendations' => ['reason'],
        'schools' => ['name', 'settings'],
        'subjects' => ['name', 'description', 'ai_context'],
        'users' => ['name'],
    ];

    private const OLD_TERMS = [
        'TKA Cerdas',
        'Try Out TKA',
        'Simulasi TKA',
        'TKA',
    ];

    private const NEW_TERMS = [
        'TOA',
        'Try Out Adaptif',
        'Simulasi Adaptif',
        'TOA',
    ];

    public function up(): void
    {
        foreach (self::CONTENT_COLUMNS as $table => $columns) {
            $this->replaceTableContent($table, $columns);
        }

        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'email')) {
            return;
        }

        DB::table('users')
            ->where(function ($query): void {
                $query->where('email', 'like', '%@tka.local')
                    ->orWhere('email', 'like', '%@tka.invalid');
            })
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $email = str_ireplace(
                        ['@tka.local', '@tka.invalid'],
                        ['@toa.local', '@toa.invalid'],
                        $user->email,
                    );

                    $emailExists = DB::table('users')
                        ->where('email', $email)
                        ->where('id', '!=', $user->id)
                        ->exists();

                    if (! $emailExists) {
                        DB::table('users')->where('id', $user->id)->update(['email' => $email]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Rebranding historical content is intentionally irreversible.
    }

    private function replaceTableContent(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $columns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($columns === []) {
            return;
        }

        DB::table($table)
            ->select(['id', ...$columns])
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $columns): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach ($columns as $column) {
                        if (! is_string($row->{$column})) {
                            continue;
                        }

                        $replacement = str_ireplace(
                            self::OLD_TERMS,
                            self::NEW_TERMS,
                            $row->{$column},
                        );

                        if ($replacement !== $row->{$column}) {
                            $updates[$column] = $replacement;
                        }
                    }

                    if ($updates !== []) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            });
    }
};
