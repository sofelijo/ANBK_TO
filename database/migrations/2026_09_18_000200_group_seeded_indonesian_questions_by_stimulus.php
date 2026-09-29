<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $questions = DB::table('questions')
            ->whereNull('story_generation_id')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->get(['id', 'school_id', 'author_id', 'competency_id', 'title', 'stimulus', 'metadata', 'created_at', 'updated_at']);

        $questions
            ->filter(fn ($question): bool => filled(data_get($this->metadata($question->metadata), 'bundle_key')))
            ->groupBy(function ($question): string {
                $metadata = $this->metadata($question->metadata);

                return implode(':', [
                    $question->school_id,
                    data_get($metadata, 'bundle_key'),
                    data_get($metadata, 'stimulus_number'),
                ]);
            })
            ->filter(fn ($bundle): bool => $bundle->count() > 1)
            ->each(function ($bundle): void {
                $first = $bundle->first();
                $metadata = $this->metadata($first->metadata);
                $bundleKey = (string) data_get($metadata, 'bundle_key');
                $stimulusNumber = (int) data_get($metadata, 'stimulus_number');
                $title = preg_replace('/^.*?·\s*/u', '', (string) $first->title) ?: (string) $first->title;
                $questionIds = $bundle->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $now = now();

                $generationId = DB::table('ai_generations')->insertGetId([
                    'school_id' => $first->school_id,
                    'requested_by' => $first->author_id,
                    'type' => 'story_questions',
                    'status' => 'completed',
                    'provider' => 'seeder',
                    'model' => 'static',
                    'input_hash' => hash('sha256', "{$bundleKey}:stimulus:{$stimulusNumber}"),
                    'request_payload' => json_encode([
                        'source' => 'seeder',
                        'format' => 'story',
                        'submission_mode' => 'review',
                        'draft_complete' => true,
                        'competency_id' => $first->competency_id,
                        'theme' => $title,
                        'question_count' => count($questionIds),
                    ], JSON_UNESCAPED_UNICODE),
                    'result_payload' => json_encode([
                        'source' => 'seeder',
                        'format' => 'story',
                        'title' => $title,
                        'story' => $first->stimulus,
                        'question_count' => count($questionIds),
                        'question_ids' => $questionIds,
                    ], JSON_UNESCAPED_UNICODE),
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'cost_microusd' => 0,
                    'created_at' => $first->created_at ?? $now,
                    'updated_at' => $first->updated_at ?? $now,
                ]);

                foreach ($bundle as $question) {
                    $questionMetadata = $this->metadata($question->metadata);
                    $questionMetadata['story_generation_id'] = $generationId;
                    $questionMetadata['generation_format'] = 'story';

                    DB::table('questions')->where('id', $question->id)->update([
                        'story_generation_id' => $generationId,
                        'metadata' => json_encode($questionMetadata, JSON_UNESCAPED_UNICODE),
                        'updated_at' => $question->updated_at ?? $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        $generationIds = DB::table('ai_generations')
            ->where('provider', 'seeder')
            ->where('model', 'static')
            ->pluck('id');

        if ($generationIds->isEmpty()) {
            return;
        }

        DB::table('questions')
            ->whereIn('story_generation_id', $generationIds)
            ->update(['story_generation_id' => null]);

        DB::table('ai_generations')->whereIn('id', $generationIds)->delete();
    }

    /** @return array<string, mixed> */
    private function metadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || $metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }
};
