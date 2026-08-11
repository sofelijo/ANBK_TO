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
        'users' => ['name'],
    ];

    public function up(): void
    {
        foreach (self::CONTENT_COLUMNS as $table => $columns) {
            $this->replaceTableContent($table, $columns);
        }

        $legacyMailDomain = '@'.strtolower($this->legacyBrand());

        DB::table('users')
            ->where('email', 'like', "%{$legacyMailDomain}.local")
            ->orWhere('email', 'like', "%{$legacyMailDomain}.invalid")
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($legacyMailDomain): void {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'email' => str_replace(
                            ["{$legacyMailDomain}.local", "{$legacyMailDomain}.invalid"],
                            ['@tka.local', '@tka.invalid'],
                            $user->email,
                        ),
                    ]);
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

        $columns = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));
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
                            $this->legacyBrand(),
                            'TKA',
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

    private function legacyBrand(): string
    {
        return implode('', array_map('chr', [65, 78, 66, 75]));
    }
};
