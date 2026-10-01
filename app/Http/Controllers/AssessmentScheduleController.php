<?php

namespace App\Http\Controllers;

use App\Enums\AssessmentStatus;
use App\Enums\UserRole;
use App\Models\ApplicationSetting;
use App\Models\Assessment;
use App\Models\AssessmentSchedule;
use App\Models\School;
use App\Models\User;
use App\Notifications\ActionNotification;
use App\Services\AssessmentScheduleCapacityService;
use App\Services\AuditLogger;
use App\Services\NotificationAudience;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AssessmentScheduleController extends Controller
{
    public function index(Request $request, AssessmentScheduleCapacityService $capacityService): Response
    {
        $isAdmin = $request->user()->hasRole(UserRole::Admin);
        $schoolNpsn = $isAdmin ? '' : (string) $request->user()->school()->value('npsn');
        $schedules = AssessmentSchedule::query()
            ->when(! $isAdmin, fn ($query) => $query->where('school_npsn', $schoolNpsn))
            ->with([
                'assessment:id,title,grade_level',
                'creator:id,name',
                'participants:id,name,student_identifier',
            ])
            ->orderBy('starts_at')
            ->get();
        $slotSchedules = $schedules->isEmpty()
            ? collect()
            : AssessmentSchedule::query()
                ->where(function ($query) use ($schedules): void {
                    foreach ($schedules as $schedule) {
                        $query->orWhere(fn ($slotQuery) => $slotQuery
                            ->whereDate('scheduled_date', $schedule->scheduled_date)
                            ->where('session_number', $schedule->session_number));
                    }
                })
                ->get(['school_npsn', 'scheduled_date', 'session_number', 'student_count']);
        $slotUsage = $slotSchedules
            ->groupBy(fn (AssessmentSchedule $schedule): string => $schedule->scheduled_date->format('Y-m-d').'-'.$schedule->session_number)
            ->map(fn ($group): int => (int) $group->sum('student_count'));
        $studentCapacity = $capacityService->capacity();
        $sessionDurationMinutes = $capacityService->durationMinutes();
        $availabilityThresholds = $capacityService->availabilityThresholds();
        $slots = $capacityService->slots($sessionDurationMinutes);
        $sessionUsage = AssessmentSchedule::query()
            ->whereDate('scheduled_date', '>=', today())
            ->selectRaw('scheduled_date, session_number, SUM(student_count) as reserved_students')
            ->groupBy('scheduled_date', 'session_number')
            ->orderBy('scheduled_date')
            ->orderBy('session_number')
            ->get()
            ->groupBy(fn (AssessmentSchedule $schedule): string => $schedule->scheduled_date->format('Y-m-d'))
            ->map(fn ($group) => $group->mapWithKeys(fn (AssessmentSchedule $schedule): array => [
                $schedule->session_number => (int) $schedule->reserved_students,
            ]));
        $defaultStudentCount = (int) $request->session()->get(
            'schedule_student_count',
            AssessmentSchedule::query()
                ->where('created_by', $request->user()->id)
                ->latest('id')
                ->value('student_count') ?? 1,
        );

        $schedules->each(function (AssessmentSchedule $schedule) use ($slotUsage, $studentCapacity): void {
            $key = $schedule->scheduled_date->format('Y-m-d').'-'.$schedule->session_number;
            $schedule->setAttribute('session_student_count', (int) $slotUsage->get($key, 0));
            $schedule->setAttribute('session_student_remaining', max(0, $studentCapacity - (int) $slotUsage->get($key, 0)));
        });
        $scheduleSummaries = $schedules
            ->groupBy(fn (AssessmentSchedule $schedule): string => $schedule->assessment_id.'-'.$schedule->school_npsn)
            ->map(fn ($group): array => [
                'assessment_id' => $group->first()->assessment_id,
                'assessment_title' => $group->first()->assessment->title,
                'school_npsn' => $group->first()->school_npsn,
                'session_count' => $group->count(),
                'student_count' => (int) $group->sum('student_count'),
            ])
            ->values();

        return Inertia::render('Schedules/Index', [
            'assessments' => Assessment::query()
                ->where('status', AssessmentStatus::Published)
                ->where('settings->type', Assessment::TYPE_TOGETHER)
                ->orderBy('title')
                ->get(['id', 'title', 'grade_level']),
            'schedules' => $schedules,
            'scheduleSummaries' => $scheduleSummaries,
            'sessionUsage' => $sessionUsage,
            'defaultStudentCount' => max(1, $defaultStudentCount),
            'schoolNpsn' => $schoolNpsn,
            'canChooseNpsn' => $isAdmin,
            'schools' => $isAdmin
                ? School::query()
                    ->whereNotNull('npsn')
                    ->orderBy('name')
                    ->get(['name', 'npsn'])
                : [],
            'slots' => collect($slots)->map(fn (array $slot, int $number): array => [
                'number' => $number,
                'label' => "{$slot['start']}–{$slot['end']}",
            ])->values(),
            'capacity' => [
                'studentsPerSession' => $studentCapacity,
                'sessionDurationMinutes' => $sessionDurationMinutes,
                'daily' => $studentCapacity * count($slots),
                'weekly' => $studentCapacity * count($slots) * 5,
                'greenThreshold' => $availabilityThresholds['green'],
                'yellowThreshold' => $availabilityThresholds['yellow'],
            ],
        ]);
    }

    public function store(Request $request, AssessmentScheduleCapacityService $capacityService): RedirectResponse
    {
        $slots = $capacityService->slots();

        if (! $request->user()->hasRole(UserRole::Admin)) {
            $request->merge([
                'school_npsn' => (string) $request->user()->school()->value('npsn'),
            ]);
        }

        $data = $request->validate([
            'assessment_id' => [
                'required',
                'integer',
                Rule::exists('assessments', 'id')->where(fn ($query) => $query->where('status', AssessmentStatus::Published->value)),
            ],
            'school_npsn' => ['required', 'regex:/^\d{8}$/'],
            'scheduled_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'session_number' => ['required', 'integer', Rule::in(array_keys($slots))],
            'student_count' => ['required', 'integer', 'min:1', 'max:100000'],
        ], [
            'school_npsn.regex' => 'NPSN harus terdiri dari tepat 8 angka.',
            'student_count.min' => 'Jumlah peserta minimal 1 siswa.',
            'student_count.max' => 'Jumlah peserta maksimal 100.000 siswa.',
        ]);

        $date = CarbonImmutable::createFromFormat('Y-m-d', $data['scheduled_date'])->startOfDay();
        $assessment = Assessment::query()->findOrFail($data['assessment_id']);
        if (! $assessment->requiresSchoolSchedule()) {
            throw ValidationException::withMessages([
                'assessment_id' => 'Jadwal sekolah hanya dapat diambil untuk Try Out Bersama.',
            ]);
        }

        if (! $date->isWeekday()) {
            throw ValidationException::withMessages([
                'scheduled_date' => 'Jadwal hanya tersedia hari Senin sampai Jumat.',
            ]);
        }

        $slot = $slots[$data['session_number']];
        $startsAt = $date->setTimeFromTimeString($slot['start']);
        $endsAt = $date->setTimeFromTimeString($slot['end']);

        if ($startsAt->isPast()) {
            throw ValidationException::withMessages([
                'session_number' => 'Sesi yang dipilih sudah dimulai atau berlalu.',
            ]);
        }

        if (AssessmentSchedule::query()
            ->whereDate('scheduled_date', $date)
            ->where('session_number', $data['session_number'])
            ->where('school_npsn', $data['school_npsn'])
            ->exists()) {
            throw ValidationException::withMessages([
                'session_number' => 'Sekolah ini sudah mengambil sesi tersebut.',
            ]);
        }

        $schedule = DB::transaction(function () use ($capacityService, $data, $date, $startsAt, $endsAt, $request): AssessmentSchedule {
            $used = (int) AssessmentSchedule::query()
                ->whereDate('scheduled_date', $date)
                ->where('session_number', $data['session_number'])
                ->lockForUpdate()
                ->sum('student_count');
            $requested = (int) $data['student_count'];
            $capacity = $capacityService->capacity();

            if ($used + $requested > $capacity) {
                throw ValidationException::withMessages([
                    'student_count' => "Kapasitas sesi tidak cukup. Terpakai {$used} siswa dan tersisa ".max(0, $capacity - $used)." dari batas {$capacity} siswa.",
                ]);
            }

            return AssessmentSchedule::create([
                ...$data,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'created_by' => $request->user()->id,
            ]);
        });
        $request->session()->put('schedule_student_count', (int) $data['student_count']);

        User::query()
            ->where('role', UserRole::Student)
            ->where('grade_level', $assessment->grade_level)
            ->where('is_active', true)
            ->whereHas('school', fn ($school) => $school->where('npsn', $data['school_npsn']))
            ->chunkById(200, function ($students) use ($assessment, $schedule): void {
                foreach ($students as $student) {
                    $student->notify(new ActionNotification(
                        'Jadwal try out tersedia',
                        "{$assessment->title} dijadwalkan pada {$schedule->starts_at->format('d/m/Y H:i')}.",
                        route('assessments.show', $assessment, absolute: false),
                        'info',
                    ));
                }
            });

        return back()->with('success', "Jadwal NPSN {$data['school_npsn']} untuk {$data['student_count']} siswa berhasil dibuat.");
    }

    public function updateCapacity(
        Request $request,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $data = $request->validate([
            'student_capacity' => ['required', 'integer', 'min:1', 'max:100000'],
        ], [
            'student_capacity.min' => 'Kapasitas minimal adalah 1 siswa per sesi.',
            'student_capacity.max' => 'Kapasitas maksimal adalah 100.000 siswa per sesi.',
        ]);

        $setting = ApplicationSetting::query()->updateOrCreate(
            ['key' => AssessmentScheduleCapacityService::SETTINGS_KEY],
            ['value' => (string) $data['student_capacity']],
        );

        $auditLogger->log($request, 'assessment_schedule_capacity.updated', $setting, [
            'student_capacity' => (int) $data['student_capacity'],
        ]);

        return back()->with('success', "Kapasitas diperbarui menjadi {$data['student_capacity']} siswa per sesi.");
    }

    public function updateSessionDuration(
        Request $request,
        AssessmentScheduleCapacityService $capacityService,
        AuditLogger $auditLogger,
        NotificationAudience $audience,
    ): RedirectResponse {
        $data = $request->validate([
            'session_duration_minutes' => ['required', 'integer', 'min:30', 'max:600'],
        ], [
            'session_duration_minutes.min' => 'Durasi minimal adalah 30 menit per sesi.',
            'session_duration_minutes.max' => 'Durasi maksimal adalah 600 menit per sesi.',
        ]);
        $duration = (int) $data['session_duration_minutes'];
        $slots = $capacityService->slots($duration);
        $highestFutureSession = (int) AssessmentSchedule::query()
            ->where('starts_at', '>', now())
            ->max('session_number');

        if ($highestFutureSession > count($slots)) {
            throw ValidationException::withMessages([
                'session_duration_minutes' => 'Durasi ini hanya menyediakan '.count($slots)." sesi, tetapi masih ada jadwal mendatang pada sesi {$highestFutureSession}.",
            ]);
        }

        $affectedSchedules = AssessmentSchedule::query()
            ->where('starts_at', '>', now())
            ->with('assessment:id,title,grade_level')
            ->get();
        $previousStartsAt = $affectedSchedules->mapWithKeys(fn (AssessmentSchedule $schedule): array => [
            $schedule->id => $schedule->starts_at?->toISOString(),
        ]);

        $setting = DB::transaction(function () use ($duration, $slots, $affectedSchedules): ApplicationSetting {
            $setting = ApplicationSetting::query()->updateOrCreate(
                ['key' => AssessmentScheduleCapacityService::DURATION_SETTINGS_KEY],
                ['value' => (string) $duration],
            );

            $affectedSchedules
                ->each(function (AssessmentSchedule $schedule) use ($slots): void {
                    $slot = $slots[$schedule->session_number];
                    $date = CarbonImmutable::parse($schedule->scheduled_date)->startOfDay();
                    $schedule->update([
                        'starts_at' => $date->setTimeFromTimeString($slot['start']),
                        'ends_at' => $date->setTimeFromTimeString($slot['end']),
                    ]);
                });

            return $setting;
        });

        $auditLogger->log($request, 'assessment_schedule_duration.updated', $setting, [
            'session_duration_minutes' => $duration,
        ]);

        foreach ($affectedSchedules as $schedule) {
            if ($previousStartsAt->get($schedule->id) === $schedule->starts_at?->toISOString()) {
                continue;
            }

            $audience->send(
                $audience->studentsForSchedule($schedule),
                new ActionNotification(
                    'Jadwal try out berubah',
                    "Jadwal {$schedule->assessment->title} diperbarui menjadi {$schedule->starts_at->format('d/m/Y H:i')}.",
                    route('assessments.show', $schedule->assessment_id, absolute: false),
                    'warning',
                    "schedule-updated:{$schedule->id}:{$schedule->updated_at?->timestamp}",
                ),
            );
        }

        return back()->with('success', "Durasi diperbarui menjadi {$duration} menit per sesi.");
    }

    public function updateAvailabilityThresholds(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'green_threshold' => ['required', 'integer', 'min:1', 'max:98'],
            'yellow_threshold' => ['required', 'integer', 'gt:green_threshold', 'max:99'],
        ], [
            'green_threshold.min' => 'Batas hijau minimal 1%.',
            'green_threshold.max' => 'Batas hijau maksimal 98%.',
            'yellow_threshold.gt' => 'Batas kuning harus lebih besar daripada batas hijau.',
            'yellow_threshold.max' => 'Batas kuning maksimal 99%.',
        ]);

        $settings = DB::transaction(function () use ($data): array {
            $green = ApplicationSetting::query()->updateOrCreate(
                ['key' => AssessmentScheduleCapacityService::GREEN_THRESHOLD_SETTINGS_KEY],
                ['value' => (string) $data['green_threshold']],
            );
            $yellow = ApplicationSetting::query()->updateOrCreate(
                ['key' => AssessmentScheduleCapacityService::YELLOW_THRESHOLD_SETTINGS_KEY],
                ['value' => (string) $data['yellow_threshold']],
            );

            return [$green, $yellow];
        });

        $auditLogger->log($request, 'assessment_schedule_availability_thresholds.updated', $settings[0], [
            'green_threshold' => (int) $data['green_threshold'],
            'yellow_threshold' => (int) $data['yellow_threshold'],
        ]);

        return back()->with('success', 'Ambang warna kapasitas tanggal berhasil diperbarui.');
    }

    public function destroy(Request $request, AssessmentSchedule $schedule, NotificationAudience $audience): RedirectResponse
    {
        abort_unless(
            $request->user()->hasRole(UserRole::Admin)
            || $schedule->school_npsn === $request->user()->school()->value('npsn'),
            404,
        );
        $schedule->loadMissing('assessment:id,title,grade_level');
        $students = $audience->studentsForSchedule($schedule);
        $assessmentTitle = $schedule->assessment->title;
        $scheduleId = $schedule->id;
        $schedule->delete();

        $audience->send(
            $students,
            new ActionNotification(
                'Jadwal try out dibatalkan',
                "Jadwal {$assessmentTitle} telah dibatalkan oleh pengelola sekolah.",
                route('assessments.index', absolute: false),
                'warning',
                "schedule-cancelled:{$scheduleId}",
            ),
        );

        return back()->with('success', 'Jadwal berhasil dihapus.');
    }
}
