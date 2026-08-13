<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table): void {
            $table->string('ai_question_format', 20)->default('direct')->after('description');
        });

        DB::table('subjects')
            ->whereRaw('LOWER(name) LIKE ?', ['%bahasa indonesia%'])
            ->update(['ai_question_format' => 'story']);
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table): void {
            $table->dropColumn('ai_question_format');
        });
    }
};
