<?php

namespace Tests\Unit;

use App\Services\QuestionDuplicateDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuestionDuplicateDetectorTest extends TestCase
{
    public function test_identical_math_question_has_full_similarity(): void
    {
        $detector = app(QuestionDuplicateDetector::class);

        $this->assertSame(1.0, $detector->similarity(
            'Hasil dari 2/3 + 3/4 adalah ...',
            'Hasil dari 2/3 + 3/4 adalah...',
        ));
    }

    #[DataProvider('differentFractionQuestions')]
    public function test_different_fraction_expressions_are_not_blocking_duplicates(string $candidate): void
    {
        $detector = app(QuestionDuplicateDetector::class);

        $this->assertLessThan(0.88, $detector->similarity(
            'Hasil dari 2/3 + 3/4 adalah ...',
            $candidate,
        ));
    }

    public static function differentFractionQuestions(): array
    {
        return [
            ['Hasil dari 3/4 + 1/6 adalah...'],
            ['Hasil dari 5/6 - 1/4 adalah ...'],
            ['Hasil dari 2 1/5 + 2 1/3 adalah ...'],
            ['Hasil dari 4 1/2 - 2 3/4 adalah ...'],
            ['Hasil dari 1 1/4 × 8 adalah ...'],
        ];
    }

    public function test_operator_changes_affect_similarity(): void
    {
        $detector = app(QuestionDuplicateDetector::class);

        $this->assertLessThan(1.0, $detector->similarity(
            'Hasil dari 2/3 + 3/4 adalah ...',
            'Hasil dari 2/3 - 3/4 adalah ...',
        ));
    }
}
