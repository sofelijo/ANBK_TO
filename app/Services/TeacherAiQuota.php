<?php

namespace App\Services;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\UserRole;
use App\Models\AiGeneration;
use App\Models\School;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TeacherAiQuota
{
    private const SETTINGS_KEY = 'ai_teacher_quotas';

    public function ensureAvailable(User $user, AiGenerationType $type, string $errorKey): void
    {
        if ($user->role !== UserRole::Teacher) {
            return;
        }

        $limit = $this->limit($user, $type);
        $usage = AiGeneration::query()
            ->where('requested_by', $user->id)
            ->where('type', $type)
            ->where('status', '!=', AiGenerationStatus::Failed)
            ->whereDate('created_at', today())
            ->count();

        if ($usage >= $limit) {
            throw ValidationException::withMessages([
                $errorKey => "Kuota {$this->label($type)} guru hari ini (maksimal {$limit} kali/hari) sudah habis. Kuota akan direset otomatis besok pukul 00.00 WIB (tengah malam).",
            ]);
        }
    }

    public function limits(User $user): array
    {
        return $this->limitsForSchool($user->school);
    }

    public function limitsForSchool(?School $school): array
    {
        return [
            'question_variants' => $this->limitForSchool($school, AiGenerationType::QuestionVariants),
            'story_questions' => $this->limitForSchool($school, AiGenerationType::StoryQuestions),
            'story_illustrations' => $this->limitForSchool($school, AiGenerationType::StoryIllustration),
        ];
    }

    private function limit(User $user, AiGenerationType $type): int
    {
        return $this->limitForSchool($user->school, $type);
    }

    private function limitForSchool(?School $school, AiGenerationType $type): int
    {
        $setting = match ($type) {
            AiGenerationType::QuestionVariants => 'question_variants',
            AiGenerationType::StoryQuestions => 'story_questions',
            AiGenerationType::StoryIllustration => 'story_illustrations',
            default => null,
        };
        $default = match ($type) {
            AiGenerationType::QuestionVariants => (int) config('ai.daily_question_limit'),
            AiGenerationType::StoryQuestions => (int) config('ai.daily_story_limit'),
            AiGenerationType::StoryIllustration => (int) config('ai.daily_image_limit'),
            default => 0,
        };

        if ($setting === null) {
            return $default;
        }

        return max(0, (int) data_get($school?->settings, self::SETTINGS_KEY.'.'.$setting, $default));
    }

    private function label(AiGenerationType $type): string
    {
        return match ($type) {
            AiGenerationType::QuestionVariants => 'pembuatan variasi soal AI',
            AiGenerationType::StoryQuestions => 'pembuatan paket soal AI',
            AiGenerationType::StoryIllustration => 'pembuatan ilustrasi AI',
            default => 'AI',
        };
    }
}
