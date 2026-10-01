<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\AssessmentSchedule;
use App\Models\User;
use App\Notifications\ActionNotification;
use Illuminate\Database\Eloquent\Builder;

class NotificationAudience
{
    public function send(Builder $users, ActionNotification $notification): void
    {
        $users->select('users.*')->chunkById(200, function ($recipients) use ($notification): void {
            foreach ($recipients as $recipient) {
                if ($notification->key !== null
                    && $recipient->notifications()->where('data->key', $notification->key)->exists()) {
                    continue;
                }
                $recipient->notify($notification);
            }
        });
    }

    public function administrators(): Builder
    {
        return User::query()
            ->where('role', UserRole::Admin)
            ->where('is_active', true);
    }

    public function approversForRegistration(UserRole $role, int $schoolId): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($role, $schoolId): void {
                $query->where('role', UserRole::Admin);

                if ($role === UserRole::Student) {
                    $query->orWhere(fn (Builder $staff) => $staff
                        ->where('role', UserRole::Operator)
                        ->where('school_id', $schoolId));
                }
            });
    }

    public function staffForSchool(int $schoolId): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->where('role', UserRole::Admin)
                ->orWhere(fn (Builder $staff) => $staff
                    ->where('school_id', $schoolId)
                    ->where('role', UserRole::Teacher)));
    }

    public function studentsForSchedule(AssessmentSchedule $schedule): Builder
    {
        $schedule->loadMissing('assessment:id,grade_level');

        return User::query()
            ->where('role', UserRole::Student)
            ->where('grade_level', $schedule->assessment->grade_level)
            ->where('is_active', true)
            ->whereHas('school', fn (Builder $school) => $school->where('npsn', $schedule->school_npsn));
    }

    public function studentsForAssessment(Assessment $assessment): Builder
    {
        $query = User::query()
            ->where('role', UserRole::Student)
            ->where('grade_level', $assessment->grade_level)
            ->where('is_active', true);

        if ($assessment->requiresSchoolSchedule()) {
            $npsns = $assessment->schedules()->pluck('school_npsn');
            $query->whereHas('school', fn (Builder $school) => $school->whereIn('npsn', $npsns));
        }

        return $query;
    }
}
