<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\School;
use App\Models\User;
use Illuminate\Console\Command;

class GenerateLoadTestUsers extends Command
{
    protected $signature = 'anbk:load-users
        {npsn : NPSN sekolah target}
        {--count=50 : Jumlah akun}
        {--grade=5 : Jenjang 5, 8, atau 11}
        {--password=load-test-only : Kata sandi akun}
        {--force : Izinkan pada APP_ENV production}';

    protected $description = 'Membuat akun murid khusus pengujian beban';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Gunakan --force bila benar-benar ingin membuat akun load test di production.');

            return self::FAILURE;
        }

        $school = School::query()->where('npsn', $this->argument('npsn'))->first();
        if (! $school) {
            $this->error('Sekolah tidak ditemukan.');

            return self::FAILURE;
        }

        $count = max(1, min(5000, (int) $this->option('count')));
        $grade = (int) $this->option('grade');
        if (! in_array($grade, [5, 8, 11], true)) {
            $this->error('Grade harus 5, 8, atau 11.');

            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar($count);
        for ($number = 1; $number <= $count; $number++) {
            $suffix = str_pad((string) $number, 4, '0', STR_PAD_LEFT);
            User::updateOrCreate(
                ['email' => "loadtest+{$suffix}@anbk.invalid"],
                [
                    'school_id' => $school->id,
                    'name' => "Load Test {$suffix}",
                    'password' => (string) $this->option('password'),
                    'role' => UserRole::Student,
                    'student_identifier' => "LOAD-{$suffix}",
                    'grade_level' => $grade,
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
            );
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("{$count} akun load test siap digunakan.");

        return self::SUCCESS;
    }
}
