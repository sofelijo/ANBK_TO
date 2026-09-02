<?php

namespace App\Services;

use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Models\Question;
use App\Models\QuestionVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuestionVerificationService
{
    /** @return array{created: bool, published: bool, already_published: bool, count: int, remaining: int} */
    public function verify(Question $question, User $verifier): array
    {
        if (! $verifier->hasRole(UserRole::Teacher)) {
            throw ValidationException::withMessages([
                'verification' => 'Hanya akun guru yang dapat memverifikasi soal.',
            ]);
        }

        return DB::transaction(function () use ($question, $verifier): array {
            $lockedQuestion = Question::query()->lockForUpdate()->findOrFail($question->id);

            if ($lockedQuestion->status === QuestionStatus::Published) {
                $verification = QuestionVerification::query()->firstOrCreate(
                    [
                        'question_id' => $lockedQuestion->id,
                        'verifier_id' => $verifier->id,
                    ],
                    ['verified_at' => now()],
                );
                $count = $lockedQuestion->verifications()->count();

                return [
                    'created' => $verification->wasRecentlyCreated,
                    'published' => false,
                    'already_published' => true,
                    'count' => $count,
                    'remaining' => max(0, Question::REQUIRED_VERIFICATIONS - $count),
                ];
            }

            if ($lockedQuestion->status === QuestionStatus::Archived || $lockedQuestion->superseded_by_id !== null) {
                throw ValidationException::withMessages([
                    'verification' => 'Soal yang diarsipkan atau sudah digantikan tidak dapat diverifikasi.',
                ]);
            }

            $verification = QuestionVerification::query()->firstOrCreate(
                [
                    'question_id' => $lockedQuestion->id,
                    'verifier_id' => $verifier->id,
                ],
                ['verified_at' => now()],
            );
            $count = $lockedQuestion->verifications()->count();
            $published = $count >= Question::REQUIRED_VERIFICATIONS;

            if ($published) {
                if ($lockedQuestion->revision_of_id) {
                    $source = Question::query()->lockForUpdate()->findOrFail($lockedQuestion->revision_of_id);
                    abort_if(
                        $source->superseded_by_id !== null && $source->superseded_by_id !== $lockedQuestion->id,
                        409,
                        'Sudah ada revisi lain yang diterbitkan untuk soal ini.',
                    );
                    $source->update([
                        'status' => QuestionStatus::Archived,
                        'superseded_by_id' => $lockedQuestion->id,
                    ]);
                }

                $lockedQuestion->update([
                    'status' => QuestionStatus::Published,
                    'approved_by' => $verifier->id,
                    'approved_at' => now(),
                ]);
            } else {
                $lockedQuestion->update([
                    'status' => QuestionStatus::Review,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);
            }

            return [
                'created' => $verification->wasRecentlyCreated,
                'published' => $published,
                'already_published' => false,
                'count' => $count,
                'remaining' => max(0, Question::REQUIRED_VERIFICATIONS - $count),
            ];
        });
    }
}
