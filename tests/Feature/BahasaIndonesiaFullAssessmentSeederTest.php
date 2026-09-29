<?php

namespace Tests\Feature;

use App\Enums\AssessmentStatus;
use App\Enums\AiGenerationType;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AiGeneration;
use App\Models\Assessment;
use App\Models\User;
use Database\Seeders\BahasaIndonesiaFullAssessmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BahasaIndonesiaFullAssessmentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_complete_indonesian_assessment_without_duplicates(): void
    {
        $this->seed();
        $this->seed(BahasaIndonesiaFullAssessmentSeeder::class);

        $assessment = Assessment::query()
            ->where('title', 'Try Out Bahasa Indonesia Kelas 6 – Paket Lengkap')
            ->with('questions')
            ->firstOrFail();

        $this->assertSame(75, $assessment->duration_minutes);
        $this->assertSame(AssessmentStatus::Published, $assessment->status);
        $this->assertCount(30, $assessment->questions);
        $this->assertCount(10, $assessment->questions->pluck('stimulus')->unique());
        $this->assertCount(10, $assessment->questions->pluck('competency_id')->unique());
        $this->assertTrue($assessment->questions->every(fn ($question) => $question->status === QuestionStatus::Published));
        $this->assertSame([
            QuestionType::CategoryMatrix->value => 10,
            QuestionType::MultipleChoice->value => 10,
            QuestionType::SingleChoice->value => 10,
        ], $assessment->questions->countBy(fn ($question) => $question->type->value)->sortKeys()->all());
        $this->assertTrue($assessment->questions
            ->groupBy('competency_id')
            ->every(fn ($questions) => $questions->count() === 3));
        $this->assertCount(10, $assessment->questions->pluck('story_generation_id')->unique());
        $this->assertTrue($assessment->questions->every(fn ($question) => $question->story_generation_id !== null));
        $this->assertSame(10, AiGeneration::query()
            ->where('provider', 'seeder')
            ->where('type', AiGenerationType::StoryQuestions)
            ->count());

        $viewer = User::findOrFail($assessment->created_by);
        $generationId = $assessment->questions->first()->story_generation_id;

        $this->actingAs($viewer)
            ->get(route('questions.index', [
                'subject_id' => $assessment->subject_id,
                'search' => 'Jalur Mangrove Teluk Hijau',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('questions.data', 1)
                ->where('questions.data.0.story_generation_id', $generationId)
                ->where('questions.data.0.bundle_question_count', 3));

        $this->actingAs($viewer)
            ->get(route('story-questions.show', $generationId))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/StoryShow')
                ->has('questions', 3));

        $targetAssessment = Assessment::create([
            'school_id' => $assessment->school_id,
            'subject_id' => $assessment->subject_id,
            'created_by' => $viewer->id,
            'title' => 'Paket Tujuan Bundel',
            'grade_level' => 6,
            'duration_minutes' => 60,
            'status' => AssessmentStatus::Draft,
            'settings' => [],
            'competency_slots' => [],
        ]);

        $this->actingAs($viewer)
            ->get(route('assessments.show', $targetAssessment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('availableBankQuestions', function ($questions) use ($generationId): bool {
                    $bundleItems = collect($questions)->where('story_generation_id', $generationId);

                    return $bundleItems->count() === 3
                        && collect($bundleItems->first()['bundle_questions'])->count() === 3;
                }));

        $this->actingAs($viewer)
            ->post(route('assessments.questions.attach', $targetAssessment), [
                'story_generation_id' => $generationId,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $assessment->questions->where('story_generation_id', $generationId)->pluck('id')->sort()->values()->all(),
            $targetAssessment->questions()->pluck('questions.id')->sort()->values()->all(),
        );

        $this->assertSame(1, Assessment::query()
            ->where('title', 'Try Out Bahasa Indonesia Kelas 6 – Paket Lengkap')
            ->count());
    }
}
