<?php

namespace App\Http\Controllers;

use App\Enums\AssessmentStatus;
use App\Models\Assessment;
use App\Models\AssessmentSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AssessmentScheduleController extends Controller
{
    public function index(Request $request): Response
    {
        $schoolNpsn = (string) $request->user()->school()->value('npsn');

        return Inertia::render('Schedules/Index', [
            'assessments' => Assessment::query()
                ->where('status', AssessmentStatus::Published)
                ->where('settings->type', Assessment::TYPE_TOGETHER)
                ->orderBy('title')
                ->get(['id', 'title', 'grade_level']),
            'schedules' => AssessmentSchedule::query()
                ->where('school_npsn', $schoolNpsn)
                ->with(['assessment:id,title,grade_level', 'creator:id,name'])
                ->orderBy('starts_at')
                ->get(),
            'schoolNpsn' => $schoolNpsn,
            'slots' => collect(AssessmentSchedule::SLOTS)->map(fn (array $slot, int $number): array => [
                'number' => $number,
                'label' => "{$slot['start']}–{$slot['end']}",
            ])->values(),
            'capacity' => [
                'daily' => count(AssessmentSchedule::SLOTS),
                'weekly' => count(AssessmentSchedule::SLOTS) * 5,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'school_npsn' => (string) $request->user()->school()->value('npsn'),
        ]);

        $data = $request->validate([
            'assessment_id' => [
                'required',
                'integer',
                Rule::exists('assessments', 'id')->where(fn ($query) => $query->where('status', AssessmentStatus::Published->value)),
            ],
            'school_npsn' => ['required', 'regex:/^\d{8}$/'],
            'scheduled_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'session_number' => ['required', 'integer', Rule::in(array_keys(AssessmentSchedule::SLOTS))],
        ], [
            'school_npsn.regex' => 'NPSN harus terdiri dari tepat 8 angka.',
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

        $slot = AssessmentSchedule::SLOTS[$data['session_number']];
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
            ->exists()) {
            throw ValidationException::withMessages([
                'session_number' => 'Sesi ini sudah dipakai oleh NPSN sekolah lain.',
            ]);
        }

        if (AssessmentSchedule::query()
            ->where('assessment_id', $data['assessment_id'])
            ->where('school_npsn', $data['school_npsn'])
            ->exists()) {
            throw ValidationException::withMessages([
                'school_npsn' => 'NPSN ini sudah memiliki jadwal untuk paket yang sama.',
            ]);
        }

        AssessmentSchedule::create([
            ...$data,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', "Jadwal NPSN {$data['school_npsn']} berhasil dibuat.");
    }

    public function destroy(Request $request, AssessmentSchedule $schedule): RedirectResponse
    {
        abort_unless($schedule->school_npsn === $request->user()->school()->value('npsn'), 404);
        $schedule->delete();

        return back()->with('success', 'Jadwal berhasil dihapus.');
    }
}
