<?php

namespace App\Console\Commands;

use App\Models\AssessmentSchedule;
use App\Notifications\ActionNotification;
use App\Services\NotificationAudience;
use Illuminate\Console\Command;

class SendAssessmentScheduleReminders extends Command
{
    protected $signature = 'schedules:send-reminders';

    protected $description = 'Send one-hour reminders for upcoming assessment schedules';

    public function handle(NotificationAudience $audience): int
    {
        $schedules = AssessmentSchedule::query()
            ->whereBetween('starts_at', [now()->addMinutes(45), now()->addMinutes(65)])
            ->with('assessment:id,title,grade_level')
            ->get();

        foreach ($schedules as $schedule) {
            $audience->send(
                $audience->studentsForSchedule($schedule),
                new ActionNotification(
                    'Try out segera dimulai',
                    "{$schedule->assessment->title} dimulai pukul {$schedule->starts_at->format('H:i')}. Siapkan perangkat dan koneksi Anda.",
                    route('assessments.show', $schedule->assessment_id, absolute: false),
                    'warning',
                    "schedule-reminder:{$schedule->id}:{$schedule->starts_at->timestamp}",
                ),
            );
        }

        $this->info("Pengingat diproses untuk {$schedules->count()} jadwal.");

        return self::SUCCESS;
    }
}
