<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->after('school_id')->constrained()->nullOnDelete();
            // JSON array of competency_id (sub-competencies planned for this assessment)
            $table->json('competency_slots')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropForeign(['subject_id']);
            $table->dropColumn(['subject_id', 'competency_slots']);
        });
    }
};
