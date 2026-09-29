<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table): void {
            $table->foreignId('assessment_schedule_id')
                ->nullable()
                ->after('assessment_id')
                ->constrained('assessment_schedules')
                ->nullOnDelete();
            $table->index(['assessment_schedule_id', 'status']);
        });

        DB::table('attempts')
            ->join('users', 'users.id', '=', 'attempts.user_id')
            ->join('schools', 'schools.id', '=', 'users.school_id')
            ->select([
                'attempts.id',
                'attempts.assessment_id',
                'attempts.started_at',
                'schools.npsn as school_npsn',
            ])
            ->orderBy('attempts.id')
            ->each(function (object $attempt): void {
                $scheduleId = DB::table('assessment_schedules')
                    ->where('assessment_id', $attempt->assessment_id)
                    ->where('school_npsn', $attempt->school_npsn)
                    ->where('starts_at', $attempt->started_at)
                    ->value('id');

                if ($scheduleId !== null) {
                    DB::table('attempts')
                        ->where('id', $attempt->id)
                        ->update(['assessment_schedule_id' => $scheduleId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $table): void {
            $table->dropIndex(['assessment_schedule_id', 'status']);
            $table->dropConstrainedForeignId('assessment_schedule_id');
        });
    }
};
