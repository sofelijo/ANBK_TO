<?php

namespace App\Services;

use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Models\ApplicationSetting;
use App\Models\Question;
use App\Models\QuestionVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuestionVerificationService
{
    public const SETTINGS_KEY = 'required_verifications';

    /** Baca jumlah verifikasi minimum global untuk seluruh bank soal. */
    public static function requiredFor(Question $question): int
    {
        return self::requiredGlobally();
    }

    public static function requiredGlobally(): int
    {
        $fromSettings = ApplicationSetting::query()
            ->where('key', self::SETTINGS_KEY)
            ->value('value');

        return is_numeric($fromSettings) && (int) $fromSettings >= 1
            ? (int) $fromSettings
            : Question::REQUIRED_VERIFICATIONS;
    }

    /** @return array{created: bool, published: bool, already_published: bool, count: int, remaining: int} */
    public function verify(Question $question, User $verifier, bool $allowAuthor = false): array
    {
        if (! $verifier->hasRole(UserRole::Teacher) && ! ($allowAuthor && $question->author_id === $verifier->id)) {
            throw ValidationException::withMessages([
                'verification' => 'Hanya akun guru yang dapat memverifikasi soal.',
            ]);
        }

        return DB::transaction(function () use ($question, $verifier): array {
            $lockedQuestion = Question::query()->lockForUpdate()->findOrFail($question->id);
            $required = self::requiredFor($lockedQuestion);

            if ((bool) data_get($lockedQuestion->metadata, 'verification_locked', false)) {
                throw ValidationException::withMessages([
                    'verification' => 'Soal masih berupa draft pribadi dan belum diajukan untuk verifikasi.',
                ]);
            }

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
                    'remaining' => max(0, $required - $count),
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
            $published = $count >= $required;

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
                'remaining' => max(0, $required - $count),
            ];
        });
    }
}
