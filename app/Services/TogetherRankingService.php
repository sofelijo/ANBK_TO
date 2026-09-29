<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\School;
use Illuminate\Support\Collection;

class TogetherRankingService
{
    /** @return Collection<int, Assessment> */
    public function assessments(): Collection
    {
        return Assessment::query()
            ->where('settings->type', Assessment::TYPE_TOGETHER)
            ->whereHas('attempts', fn ($query) => $query
                ->where('status', AttemptStatus::Submitted)
                ->where('max_score', '>', 0))
            ->withCount(['attempts as participant_count' => fn ($query) => $query
                ->where('status', AttemptStatus::Submitted)
                ->where('max_score', '>', 0)])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get(['id', 'title', 'grade_level', 'starts_at', 'ends_at']);
    }

    /** @return Collection<int, Assessment> */
    public function selectableAssessments(): Collection
    {
        return Assessment::query()
            ->whereHas('attempts', fn ($query) => $query
                ->where('status', AttemptStatus::Submitted)
                ->where('max_score', '>', 0))
            ->withCount(['attempts as participant_count' => fn ($query) => $query
                ->where('status', AttemptStatus::Submitted)
                ->where('max_score', '>', 0)])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get(['id', 'title', 'grade_level', 'starts_at', 'ends_at', 'settings']);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function attempts(?Assessment $assessment = null, ?string $subdistrict = null): Collection
    {
        return Attempt::query()
            ->when(
                $assessment,
                fn ($query) => $query->where('assessment_id', $assessment->id),
                fn ($query) => $query->whereHas(
                    'assessment',
                    fn ($assessmentQuery) => $assessmentQuery->where('settings->type', Assessment::TYPE_TOGETHER),
                ),
            )
            ->where('status', AttemptStatus::Submitted)
            ->where('max_score', '>', 0)
            ->when($subdistrict, fn ($query) => $query->whereHas(
                'student.school',
                fn ($schoolQuery) => $schoolQuery->where('subdistrict', $subdistrict),
            ))
            ->with([
                'assessment:id,title',
                'student:id,school_id,name,student_identifier,grade_level',
                'student.school:id,name,npsn,subdistrict',
            ])
            ->get()
            ->map(fn (Attempt $attempt): array => [
                'attemptId' => $attempt->id,
                'assessment' => [
                    'id' => $attempt->assessment->id,
                    'title' => $attempt->assessment->title,
                ],
                'student' => [
                    'id' => $attempt->student->id,
                    'name' => $attempt->student->name,
                    'identifier' => $attempt->student->student_identifier,
                    'gradeLevel' => $attempt->student->grade_level,
                ],
                'school' => [
                    'name' => $attempt->student->school?->name ?? 'Sekolah tidak tersedia',
                    'npsn' => $attempt->student->school?->npsn,
                    'subdistrict' => $attempt->student->school?->subdistrict,
                ],
                'score' => (float) $attempt->score,
                'maxScore' => (float) $attempt->max_score,
                'percentage' => round(((float) $attempt->score / (float) $attempt->max_score) * 100, 2),
                'durationSeconds' => $attempt->duration_seconds,
                'submittedAt' => $attempt->submitted_at?->toIso8601String(),
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $attempts
     * @return Collection<int, array<string, mixed>>
     */
    public function individuals(Collection $attempts): Collection
    {
        return $attempts
            ->groupBy('student.id')
            ->map(function (Collection $rows): array {
                $first = $rows->first();

                return [
                    'student' => $first['student'],
                    'school' => $first['school'],
                    'packagesCompleted' => $rows->pluck('assessment.id')->unique()->count(),
                    'averageScore' => round((float) $rows->avg('percentage'), 2),
                    'highestScore' => round((float) $rows->max('percentage'), 2),
                    'averageDurationSeconds' => (int) round((float) $rows->avg('durationSeconds')),
                    'attempts' => $rows->sortBy('assessment.title')->values(),
                ];
            })
            ->sortBy([
                ['averageScore', 'desc'],
                ['highestScore', 'desc'],
                ['averageDurationSeconds', 'asc'],
                ['student.name', 'asc'],
            ])
            ->values()
            ->map(fn (array $student, int $index): array => [
                'rank' => $index + 1,
                ...$student,
            ]);
    }

    /**
     * Setiap paket berbobot sama walaupun jumlah pesertanya berbeda.
     *
     * @param  Collection<int, array<string, mixed>>  $attempts
     * @return Collection<int, array<string, mixed>>
     */
    public function schools(Collection $attempts): Collection
    {
        return $attempts
            ->filter(fn (array $row): bool => filled(data_get($row, 'school.npsn')))
            ->groupBy('school.npsn')
            ->map(function (Collection $rows): array {
                $packageAverages = $rows
                    ->groupBy('assessment.id')
                    ->map(fn (Collection $packageRows): float => (float) $packageRows->avg('percentage'));

                return [
                    'school' => $rows->first()['school'],
                    'participants' => $rows->pluck('student.id')->unique()->count(),
                    'packagesCompleted' => $packageAverages->count(),
                    'averageScore' => round((float) $packageAverages->avg(), 2),
                    'highestScore' => round((float) $rows->max('percentage'), 2),
                    'averageDurationSeconds' => (int) round((float) $rows->avg('durationSeconds')),
                ];
            })
            ->sortBy([
                ['averageScore', 'desc'],
                ['highestScore', 'desc'],
                ['averageDurationSeconds', 'asc'],
                ['school.name', 'asc'],
            ])
            ->values()
            ->map(fn (array $school, int $index): array => [
                'rank' => $index + 1,
                ...$school,
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $attempts
     * @return Collection<int, array<string, mixed>>
     */
    public function districts(Collection $attempts): Collection
    {
        return collect(School::SUBDISTRICTS)
            ->map(function (string $subdistrict) use ($attempts): array {
                $rows = $attempts->where('school.subdistrict', $subdistrict);
                $packageAverages = $rows
                    ->groupBy('assessment.id')
                    ->map(fn (Collection $packageRows): float => (float) $packageRows->avg('percentage'));

                return [
                    'name' => $subdistrict,
                    'participants' => $rows->pluck('student.id')->unique()->count(),
                    'schools' => $rows->pluck('school.npsn')->filter()->unique()->count(),
                    'packagesCompleted' => $packageAverages->count(),
                    'averageScore' => round((float) ($packageAverages->avg() ?? 0), 2),
                    'highestScore' => round((float) ($rows->max('percentage') ?? 0), 2),
                ];
            })
            ->sortBy([
                ['averageScore', 'desc'],
                ['highestScore', 'desc'],
                ['name', 'asc'],
            ])
            ->values()
            ->map(fn (array $district, int $index): array => [
                'rank' => $index + 1,
                ...$district,
            ]);
    }

    /** @return array<string, mixed>|null */
    public function preview(): ?array
    {
        $assessments = $this->assessments();

        if ($assessments->isEmpty()) {
            return null;
        }

        $attempts = $this->attempts();

        return [
            'assessmentCount' => $assessments->count(),
            'participantCount' => $attempts->pluck('student.id')->unique()->count(),
            'schoolCount' => $attempts->pluck('school.npsn')->filter()->unique()->count(),
            'schools' => $this->schools($attempts)->take(5)->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function assessmentData(Assessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'title' => $assessment->title,
            'gradeLevel' => $assessment->grade_level,
            'startsAt' => $assessment->starts_at?->toIso8601String(),
            'endsAt' => $assessment->ends_at?->toIso8601String(),
            'participantCount' => (int) ($assessment->participant_count ?? 0),
            'type' => $assessment->assessmentType(),
            'typeLabel' => config("assessment.types.{$assessment->assessmentType()}"),
        ];
    }
}
