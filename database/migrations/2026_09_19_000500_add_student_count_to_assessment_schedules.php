<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_schedules', function (Blueprint $table): void {
            $table->unsignedInteger('student_count')->default(1)->after('session_number');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_schedules', function (Blueprint $table): void {
            $table->dropColumn('student_count');
        });
    }
};
