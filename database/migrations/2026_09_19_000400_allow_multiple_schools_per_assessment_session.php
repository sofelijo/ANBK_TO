<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_schedules', function (Blueprint $table): void {
            $table->dropUnique('assessment_schedules_scheduled_date_session_number_unique');
            $table->unique(
                ['scheduled_date', 'session_number', 'school_npsn'],
                'assessment_schedules_date_session_npsn_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('assessment_schedules', function (Blueprint $table): void {
            $table->dropUnique('assessment_schedules_date_session_npsn_unique');
            $table->unique(['scheduled_date', 'session_number']);
        });
    }
};
