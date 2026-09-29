<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_schedules', function (Blueprint $table): void {
            $table->dropUnique('assessment_schedules_assessment_id_school_npsn_unique');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_schedules', function (Blueprint $table): void {
            $table->unique(['assessment_id', 'school_npsn']);
        });
    }
};
