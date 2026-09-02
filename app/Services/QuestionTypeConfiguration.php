<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Models\School;

class QuestionTypeConfiguration
{
    public const SETTINGS_KEY = 'enabled_question_types';

    /** @return array<int, string> */
    public function enabledValues(School $school): array
    {
        $configured = data_get($school->settings, self::SETTINGS_KEY);
        $validValues = array_column(QuestionType::cases(), 'value');

        if (! is_array($configured)) {
            return $validValues;
        }

        $enabled = array_values(array_intersect($validValues, $configured));

        return $enabled !== [] ? $enabled : $validValues;
    }

    /** @return array<int, array{value: string, label: string, description: string, active: bool}> */
    public function options(School $school, ?QuestionType $include = null): array
    {
        $enabled = $this->enabledValues($school);

        return array_values(array_filter(array_map(
            function (QuestionType $type) use ($enabled): array {
                return [
                    'value' => $type->value,
                    'label' => $this->label($type),
                    'description' => $this->description($type),
                    'active' => in_array($type->value, $enabled, true),
                ];
            },
            QuestionType::cases(),
        ), fn (array $option): bool => $option['active'] || $option['value'] === $include?->value));
    }

    public function label(QuestionType $type): string
    {
        return match ($type) {
            QuestionType::SingleChoice => 'Pilihan tunggal',
            QuestionType::MultipleChoice => 'Pilihan kompleks (MCMA)',
            QuestionType::ShortAnswer => 'Isian singkat',
            QuestionType::Matching => 'Menjodohkan',
            QuestionType::CategoryMatrix => 'Pilihan kategori (tabel)',
        };
    }

    public function description(QuestionType $type): string
    {
        return match ($type) {
            QuestionType::SingleChoice => 'Siswa memilih tepat satu jawaban dari beberapa pilihan.',
            QuestionType::MultipleChoice => 'Siswa dapat memilih lebih dari satu jawaban yang benar.',
            QuestionType::ShortAnswer => 'Siswa mengetik jawaban singkat yang akan dicocokkan sistem.',
            QuestionType::Matching => 'Siswa memasangkan pernyataan dengan jawaban yang sesuai.',
            QuestionType::CategoryMatrix => 'Siswa memilih satu kategori untuk setiap pernyataan.',
        };
    }
}
