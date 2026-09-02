<?php

namespace Tests\Feature;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Jobs\GenerateSchoolAssessmentAnalysis;
use App\Models\AiGeneration;
use App\Models\Assessment;
use App\Models\Question;
use App\Models\User;
use App\Services\AI\AiManager;
use App\Services\AI\AiProvider;
use App\Services\AI\AiResponse;
use App\Services\AssessmentAiAnalysisData;
use Database\Seeders\MatrixAnalysisDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MonitoringAiAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_generate_school_level_recommendations_and_practice_questions(): void
    {
        [$operator, $assessment] = $this->matrixScenario();

        $this->actingAs($operator)
            ->post(route('monitoring.ai-analysis.store', $assessment))
            ->assertRedirect()
            ->assertSessionHas('success');

        $generation = AiGeneration::query()->sole();
        $result = $generation->result_payload;
        $requestJson = json_encode($generation->request_payload, JSON_UNESCAPED_UNICODE);

        $this->assertSame($assessment->id, $generation->assessment_id);
        $this->assertSame(AiGenerationType::SchoolAssessmentAnalysis, $generation->type);
        $this->assertSame(AiGenerationStatus::Completed, $generation->status);
        $this->assertSame(30, $generation->request_payload['sample_size']);
        $this->assertCount(2, $generation->request_payload['weak_competencies']);
        $this->assertCount(5, $generation->request_payload['difficult_questions']);
        $this->assertStringNotContainsString('Siswa Demo Matrix', $requestJson);
        $this->assertStringNotContainsString('MATRIX-001', $requestJson);
        $this->assertNotEmpty($result['summary']);
        $this->assertCount(2, $result['teacher_recommendations']);
        $this->assertCount(5, $result['practice_questions']);
        $this->assertTrue(collect($result['practice_questions'])->every(
            fn (array $question): bool => count($question['options']) === 4
                && collect($question['options'])->where('is_correct', true)->count() === 1,
        ));
        $this->assertTrue(collect($result['practice_questions'])->every(function (array $practice): bool {
            $source = Question::query()->findOrFail($practice['source_question_id']);

            return $practice['prompt'] !== $source->prompt;
        }));

        $this->actingAs($operator)
            ->get(route('monitoring.index', ['assessment_id' => $assessment->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Monitoring/Index')
                ->where('aiAnalysis.sample_size', 30)
                ->has('aiAnalysis.weak_competencies', 2)
                ->has('aiAnalysis.difficult_questions', 5)
                ->where('aiAnalysis.generation.status', 'completed')
                ->where('aiAnalysis.generation.provider', 'fake')
                ->where('aiAnalysis.generation.is_stale', false)
                ->has('aiAnalysis.generation.result.teacher_recommendations', 2)
                ->has('aiAnalysis.generation.result.practice_questions', 5));
    }

    public function test_teacher_cannot_request_monitoring_ai_analysis(): void
    {
        [, $assessment] = $this->matrixScenario();
        $teacher = User::query()->where('email', 'guru.matrix.1@toa.local')->firstOrFail();

        $this->actingAs($teacher)
            ->post(route('monitoring.ai-analysis.store', $assessment))
            ->assertForbidden();

        $this->assertDatabaseCount('ai_generations', 0);
    }

    public function test_duplicate_ai_recommendations_are_normalized_and_missing_competencies_are_completed(): void
    {
        [$operator, $assessment] = $this->matrixScenario();
        $analysisData = app(AssessmentAiAnalysisData::class);
        $context = $analysisData->build($assessment, $operator->school_id);
        $generation = AiGeneration::create([
            'school_id' => $operator->school_id,
            'requested_by' => $operator->id,
            'assessment_id' => $assessment->id,
            'type' => AiGenerationType::SchoolAssessmentAnalysis,
            'status' => AiGenerationStatus::Pending,
            'provider' => 'duplicate-test',
            'model' => 'duplicate-test-model',
            'input_hash' => $analysisData->hash($context),
            'request_payload' => $context,
        ]);
        $provider = new class implements AiProvider
        {
            public function name(): string
            {
                return 'duplicate-test';
            }

            public function model(): string
            {
                return 'duplicate-test-model';
            }

            public function generateJson(string $prompt, array $context = []): AiResponse
            {
                $weakest = $context['weak_competencies'][0];

                return new AiResponse([
                    'summary' => 'Ringkasan hasil agregat sekolah.',
                    'teacher_recommendations' => [
                        [
                            'competency_code' => $weakest['code'],
                            'finding' => 'Temuan pertama.',
                            'action' => 'Tindakan pertama.',
                            'suggested_activity' => 'Aktivitas pertama.',
                        ],
                        [
                            'competency_code' => $weakest['code'],
                            'finding' => 'Duplikat yang harus dibuang.',
                            'action' => 'Duplikat yang harus dibuang.',
                            'suggested_activity' => 'Duplikat yang harus dibuang.',
                        ],
                    ],
                    'practice_questions' => collect($context['difficult_questions'])->take(3)->map(fn (array $source, int $index): array => [
                        'source_question_id' => $source['question_id'],
                        'competency_code' => $source['competency_code'],
                        'prompt' => 'Soal latihan baru '.($index + 1),
                        'difficulty' => 2,
                        'options' => [
                            ['content' => 'Jawaban benar', 'is_correct' => true],
                            ['content' => 'Pengecoh satu', 'is_correct' => false],
                            ['content' => 'Pengecoh dua', 'is_correct' => false],
                            ['content' => 'Pengecoh tiga', 'is_correct' => false],
                        ],
                        'explanation' => 'Pembahasan latihan.',
                    ])->all(),
                ]);
            }
        };
        $manager = new class($provider) extends AiManager
        {
            public function __construct(private readonly AiProvider $testProvider) {}

            public function provider(): AiProvider
            {
                return $this->testProvider;
            }

            public function costMicrousd(AiResponse $response): int
            {
                return 0;
            }
        };

        (new GenerateSchoolAssessmentAnalysis($generation->id))->handle($manager);

        $generation->refresh();
        $recommendations = collect($generation->result_payload['teacher_recommendations']);
        $this->assertSame(AiGenerationStatus::Completed, $generation->status);
        $this->assertCount(count($context['weak_competencies']), $recommendations);
        $this->assertCount($recommendations->count(), $recommendations->pluck('competency_code')->unique());
        $this->assertEqualsCanonicalizing(
            collect($context['weak_competencies'])->pluck('code')->all(),
            $recommendations->pluck('competency_code')->all(),
        );
    }

    private function matrixScenario(): array
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        config()->set('demo.matrix.operator_email', 'operator.matrix.analysis@toa.local');
        config()->set('demo.matrix.operator_password', 'local-test-password');
        $this->seed(MatrixAnalysisDemoSeeder::class);

        return [
            User::query()->where('email', 'operator.matrix.analysis@toa.local')->firstOrFail(),
            Assessment::query()->where('title', 'Paket Demo Analisis Matriks Matematika')->firstOrFail(),
        ];
    }
}
