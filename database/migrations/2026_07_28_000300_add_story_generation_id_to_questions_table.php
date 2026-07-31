<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->foreignId('story_generation_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('ai_generations')
                ->nullOnDelete();
        });

        DB::table('questions')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(100, function ($questions): void {
                foreach ($questions as $question) {
                    $metadata = is_string($question->metadata)
                        ? json_decode($question->metadata, true)
                        : (array) $question->metadata;
                    $generationId = data_get($metadata, 'story_generation_id');

                    if (! is_numeric($generationId)) {
                        continue;
                    }

                    $generationExists = DB::table('ai_generations')
                        ->where('id', (int) $generationId)
                        ->where('type', 'story_questions')
                        ->exists();

                    if ($generationExists) {
                        DB::table('questions')
                            ->where('id', $question->id)
                            ->update(['story_generation_id' => (int) $generationId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('story_generation_id');
        });
    }
};
