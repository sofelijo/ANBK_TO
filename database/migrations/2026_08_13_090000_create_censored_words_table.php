<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('censored_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('word', 100);
            $table->text('custom_advice')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'word']);
        });

        // Insert initial default censored words
        $defaultWords = [
            'jancok', 'jancuk', 'anjing', 'babi', 'kontol', 'memek',
            'goblok', 'tolol', 'bangsat', 'bajingan', 'pantek', 'perek',
        ];

        $now = now();
        $records = array_map(fn ($word) => [
            'school_id' => null,
            'created_by' => null,
            'word' => mb_strtolower($word),
            'custom_advice' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $defaultWords);

        DB::table('censored_words')->insert($records);
    }

    public function down(): void
    {
        Schema::dropIfExists('censored_words');
    }
};
