<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class IndonesianBundleConfiguration
{
    public const ANSWER_FORMATS = ['single_choice', 'true_false', 'multiple_choice'];

    public const COGNITIVE_LEVELS = ['textual', 'inferential', 'evaluation'];

    public function defaults(): array
    {
        return [
            ['answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
            ['answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
            ['answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
        ];
    }

    public function forUser(User $user): array
    {
        $slots = data_get($user->school?->settings, 'indonesian_bundle_defaults.slots');

        return $this->isComplete($slots) ? array_values($slots) : $this->defaults();
    }

    public function ensureComplete(mixed $slots, string $key = 'slots'): array
    {
        if (! $this->isComplete($slots)) {
            throw ValidationException::withMessages([
                $key => 'Bundle wajib memuat satu Pilihan Ganda, satu Benar/Salah, satu MCMA, serta Level 1, Level 2, dan Level 3 masing-masing satu kali.',
            ]);
        }

        return array_values($slots);
    }

    public function levelLabel(string $level): string
    {
        return match ($level) {
            'textual' => 'Pemahaman Tekstual (Level 1)',
            'inferential' => 'Pemahaman Inferensial (Level 2)',
            'evaluation' => 'Evaluasi dan Apresiasi (Level 3)',
        };
    }

    private function isComplete(mixed $slots): bool
    {
        if (! is_array($slots) || count($slots) !== 3) {
            return false;
        }

        $formats = collect($slots)->pluck('answer_format')->sort()->values()->all();
        $levels = collect($slots)->pluck('cognitive_level')->sort()->values()->all();
        $expectedFormats = collect(self::ANSWER_FORMATS)->sort()->values()->all();
        $expectedLevels = collect(self::COGNITIVE_LEVELS)->sort()->values()->all();

        return $formats === $expectedFormats && $levels === $expectedLevels;
    }
}
