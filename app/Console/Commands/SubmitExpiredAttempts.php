<?php

namespace App\Console\Commands;

use App\Enums\AttemptStatus;
use App\Models\Attempt;
use App\Services\AttemptSubmissionService;
use Illuminate\Console\Command;
use Throwable;

class SubmitExpiredAttempts extends Command
{
    protected $signature = 'attempts:submit-expired
        {--chunk=100 : Jumlah attempt yang diproses per batch}';

    protected $description = 'Mengirim otomatis attempt yang waktu pengerjaannya telah habis';

    public function handle(AttemptSubmissionService $submissionService): int
    {
        $chunkSize = max(1, min(1000, (int) $this->option('chunk')));
        $submittedCount = 0;
        $failedCount = 0;

        Attempt::query()
            ->where('status', AttemptStatus::InProgress)
            ->with('assessment')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($attempts) use ($submissionService, &$submittedCount, &$failedCount): void {
                foreach ($attempts as $attempt) {
                    if (! $attempt->isExpired()) {
                        continue;
                    }

                    try {
                        $submitted = $submissionService->submit($attempt);

                        if ($submitted->status === AttemptStatus::Submitted) {
                            $submittedCount++;
                        }
                    } catch (Throwable $exception) {
                        $failedCount++;
                        report($exception);
                        $this->components->error("Attempt {$attempt->id} gagal dikirim: {$exception->getMessage()}");
                    }
                }
            });

        $this->components->info("{$submittedCount} attempt kedaluwarsa berhasil dikirim.");

        if ($failedCount > 0) {
            $this->components->warn("{$failedCount} attempt gagal diproses dan akan dicoba kembali pada jadwal berikutnya.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
