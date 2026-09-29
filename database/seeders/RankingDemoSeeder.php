<?php

namespace Database\Seeders;

use App\Enums\AssessmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RankingDemoSeeder extends Seeder
{
    private const PREFIX = '[DEMO RANKING]';

    private const STUDENTS_PER_SCHOOL = 18;

    private ?string $studentPasswordHash = null;

    public function run(): void
    {
        DB::transaction(function (): void {
            $schools = $this->schools();
            $admin = $this->manager($schools->first(), 'ranking.admin@toa.local', 'Admin Demo Ranking', UserRole::Admin);
            $teacher = $this->manager($schools->first(), 'ranking.guru@toa.local', 'Guru Demo Ranking', UserRole::Teacher);
            $students = $schools->mapWithKeys(fn (School $school, int $schoolIndex): array => [
                $school->id => $this->students($school, $schoolIndex),
            ]);

            $togetherAssessments = collect([
                ['Literasi Dasar', -28, -27],
                ['Numerasi Terapan', -21, -20],
                ['Penalaran Terpadu', -14, -13],
                ['Evaluasi Akhir', -7, -6],
            ])->map(fn (array $specification, int $index): Assessment => $this->assessment(
                school: $schools->first(),
                creator: $admin,
                title: self::PREFIX.' Bersama '.($index + 1).' · '.$specification[0],
                type: Assessment::TYPE_TOGETHER,
                startsAt: now()->addDays($specification[1]),
                endsAt: now()->addDays($specification[2]),
            ));

            foreach ($togetherAssessments as $packageIndex => $assessment) {
                foreach ($schools as $schoolIndex => $school) {
                    foreach ($students[$school->id]->take(16) as $studentIndex => $student) {
                        if ($this->isSubmitted($schoolIndex, $studentIndex, $packageIndex)) {
                            $this->submittedAttempt($assessment, $student, $schoolIndex, $studentIndex, $packageIndex);
                        } elseif (($schoolIndex + $studentIndex + $packageIndex) % 4 === 0) {
                            $this->inProgressAttempt($assessment, $student, $studentIndex);
                        }
                    }
                }
            }

            collect([
                [$schools[0], $teacher, 'Latihan Literasi Sekolah A', 0],
                [$schools[0], $teacher, 'Latihan Numerasi Sekolah A', 1],
                [$schools[5], $admin, 'Evaluasi Reguler Sekolah F', 2],
            ])->each(function (array $specification) use ($students): void {
                [$school, $creator, $title, $packageIndex] = $specification;
                $assessment = $this->assessment(
                    school: $school,
                    creator: $creator,
                    title: self::PREFIX.' Reguler · '.$title,
                    type: Assessment::TYPE_REGULAR,
                    startsAt: now()->subDays(4 - $packageIndex),
                    endsAt: now()->subDays(3 - $packageIndex),
                );

                foreach ($students[$school->id]->take(14) as $studentIndex => $student) {
                    $this->submittedAttempt($assessment, $student, $school->id % 16, $studentIndex, $packageIndex + 5);
                }
            });

            $this->command?->info(sprintf(
                'Data demo ranking siap: %d sekolah, %d siswa, %d paket bersama, dan 3 paket reguler.',
                $schools->count(),
                $students->flatten()->count(),
                $togetherAssessments->count(),
            ));
            $this->command?->info('Login admin: ranking.admin@toa.local / password');
            $this->command?->info('Login guru: ranking.guru@toa.local / password');
        });
    }

    /** @return Collection<int, School> */
    private function schools(): Collection
    {
        $schools = collect(School::SUBDISTRICTS)->flatMap(function (string $subdistrict, int $districtIndex): Collection {
            return collect(range(1, 5))->map(function (int $number) use ($subdistrict, $districtIndex): School {
                $sequence = ($districtIndex * 5) + $number;

                return School::query()->updateOrCreate(
                    ['npsn' => sprintf('3280%04d', $sequence)],
                    [
                        'name' => sprintf('%s SD Demo %s %02d', self::PREFIX, $subdistrict, $number),
                        'subdistrict' => $subdistrict,
                        'timezone' => 'Asia/Jakarta',
                    ],
                );
            });
        });

        return $schools->push(School::query()->updateOrCreate(
            ['npsn' => '32809999'],
            [
                'name' => self::PREFIX.' SD Demo Kecamatan Belum Diisi',
                'subdistrict' => null,
                'timezone' => 'Asia/Jakarta',
            ],
        ))->values();
    }

    private function manager(School $school, string $email, string $name, UserRole $role): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'school_id' => $school->id,
                'name' => $name,
                'password' => Hash::make('password'),
                'role' => $role,
                'student_identifier' => null,
                'grade_level' => null,
                'is_active' => true,
                'approved_at' => now(),
                'email_verified_at' => now(),
            ],
        );
    }

    /** @return Collection<int, User> */
    private function students(School $school, int $schoolIndex): Collection
    {
        $this->studentPasswordHash ??= Hash::make(Str::random(40));

        return collect(range(1, self::STUDENTS_PER_SCHOOL))->map(function (int $number) use ($school, $schoolIndex): User {
            $identifier = sprintf('RANK-%02d-%03d', $schoolIndex + 1, $number);

            return User::query()->updateOrCreate(
                ['email' => strtolower($identifier).'@student.toa.local'],
                [
                    'school_id' => $school->id,
                    'name' => sprintf('Siswa Demo Ranking %02d-%02d', $schoolIndex + 1, $number),
                    'password' => $this->studentPasswordHash,
                    'role' => UserRole::Student,
                    'student_identifier' => $identifier,
                    'grade_level' => $number % 6 === 0 ? 5 : 6,
                    'is_active' => $number !== self::STUDENTS_PER_SCHOOL,
                    'approved_at' => now(),
                    'email_verified_at' => now(),
                ],
            );
        });
    }

    private function assessment(
        School $school,
        User $creator,
        string $title,
        string $type,
        mixed $startsAt,
        mixed $endsAt,
    ): Assessment {
        return Assessment::query()->updateOrCreate(
            ['title' => $title],
            [
                'created_by' => $creator->id,
                'description' => 'Paket data dummy bervariasi untuk memeriksa seluruh tampilan dan filter ranking TOA.',
                'grade_level' => 6,
                'duration_minutes' => 60,
                'status' => AssessmentStatus::Published,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'settings' => [
                    'type' => $type,
                    'type_label' => config("assessment.types.{$type}"),
                    'demo' => true,
                ],
            ],
        );
    }

    private function isSubmitted(int $schoolIndex, int $studentIndex, int $packageIndex): bool
    {
        return match ($packageIndex) {
            0 => true,
            1 => ($schoolIndex + $studentIndex) % 7 !== 0,
            2 => ($studentIndex + ($schoolIndex * 2)) % 5 !== 0,
            default => ($studentIndex + $schoolIndex) % 3 !== 0,
        };
    }

    private function submittedAttempt(
        Assessment $assessment,
        User $student,
        int $schoolIndex,
        int $studentIndex,
        int $packageIndex,
    ): void {
        $score = max(10, min(100,
            42
            + (($schoolIndex * 7) % 39)
            + (($studentIndex * 9 + $packageIndex * 11) % 31)
            - 13
            + [0, 7, -5, 12, -8, 4, 9][$packageIndex % 7],
        ));
        if ($packageIndex === 0 && in_array($studentIndex, [0, 1], true)) {
            $score = 88;
        }

        $duration = 900 + (($schoolIndex * 83 + $studentIndex * 137 + $packageIndex * 211) % 2600);
        $submittedAt = now()->subDays(max(1, 30 - ($packageIndex * 6)))->addMinutes(($schoolIndex * 20) + $studentIndex);
        $attempt = Attempt::query()->firstOrNew([
            'assessment_id' => $assessment->id,
            'user_id' => $student->id,
        ]);
        $attempt->public_id ??= (string) Str::uuid();
        $attempt->fill([
            'status' => AttemptStatus::Submitted,
            'started_at' => $submittedAt->copy()->subSeconds($duration),
            'submitted_at' => $submittedAt,
            'duration_seconds' => $duration,
            'score' => $score,
            'max_score' => 100,
            'summary' => sprintf('Data dummy: nilai %d dengan durasi %d detik.', $score, $duration),
        ]);
        $attempt->save();
    }

    private function inProgressAttempt(Assessment $assessment, User $student, int $studentIndex): void
    {
        $attempt = Attempt::query()->firstOrNew([
            'assessment_id' => $assessment->id,
            'user_id' => $student->id,
        ]);
        $attempt->public_id ??= (string) Str::uuid();
        $attempt->fill([
            'status' => AttemptStatus::InProgress,
            'started_at' => now()->subMinutes(10 + $studentIndex),
            'submitted_at' => null,
            'duration_seconds' => 0,
            'score' => 0,
            'max_score' => 100,
            'summary' => null,
        ]);
        $attempt->save();
    }
}
