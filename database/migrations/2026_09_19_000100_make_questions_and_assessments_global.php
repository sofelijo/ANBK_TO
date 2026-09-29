<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table): void {
            $table->foreignId('school_id')->nullable()->change();
        });

        Schema::table('assessments', function (Blueprint $table): void {
            $table->foreignId('school_id')->nullable()->change();
        });

        DB::table('questions')->update(['school_id' => null]);
        DB::table('assessments')->update(['school_id' => null]);
    }

    public function down(): void
    {
        DB::table('questions')
            ->whereNull('school_id')
            ->orderBy('id')
            ->chunkById(100, function ($questions): void {
                $schoolIds = DB::table('users')
                    ->whereIn('id', $questions->pluck('author_id'))
                    ->pluck('school_id', 'id');

                foreach ($questions as $question) {
                    DB::table('questions')->where('id', $question->id)->update([
                        'school_id' => $schoolIds[$question->author_id],
                    ]);
                }
            });

        DB::table('assessments')
            ->whereNull('school_id')
            ->orderBy('id')
            ->chunkById(100, function ($assessments): void {
                $schoolIds = DB::table('users')
                    ->whereIn('id', $assessments->pluck('created_by'))
                    ->pluck('school_id', 'id');

                foreach ($assessments as $assessment) {
                    DB::table('assessments')->where('id', $assessment->id)->update([
                        'school_id' => $schoolIds[$assessment->created_by],
                    ]);
                }
            });

        Schema::table('questions', function (Blueprint $table): void {
            $table->foreignId('school_id')->nullable(false)->change();
        });

        Schema::table('assessments', function (Blueprint $table): void {
            $table->foreignId('school_id')->nullable(false)->change();
        });
    }
};
