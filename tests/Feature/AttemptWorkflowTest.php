<?php

namespace Tests\Feature;

use App\Enums\AssessmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Competency;
use App\Models\Question;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttemptWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_and_start_published_assessment_from_another_school(): void
    {
        [, $assessment] = $this->scenario();
        $otherSchool = School::create([
            'name' => 'Sekolah Lain',
            'npsn' => '10000002',
        ]);
        $otherStudent = User::create([
            'school_id' => $otherSchool->id,
            'name' => 'Siswa Sekolah Lain',
            'email' => 'siswa-lain@example.com',
            'password' => 'password',
            'role' => UserRole::Student,
            'student_identifier' => '0011111111',
            'grade_level' => 5,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($otherStudent)
            ->get(route('assessments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assessments/Index')
                ->where('assessments.0.id', $assessment->id));

        $this->actingAs($otherStudent)
            ->post(route('attempts.start', $assessment))
            ->assertRedirect();

        $this->assertDatabaseHas('attempts', [
            'assessment_id' => $assessment->id,
            'user_id' => $otherStudent->id,
            'status' => AttemptStatus::InProgress->value,
        ]);
    }

    public function test_student_attempt_is_scored_and_receives_weakness_recommendation(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$student, $assessment, $informationQuestion, $inferenceQuestion, $inferencePractice] = $this->scenario();

        $this->actingAs($student)
            ->post(route('attempts.start', $assessment))
            ->assertRedirect();

        $attempt = Attempt::firstOrFail();
        $correctOption = $informationQuestion->options()->where('is_correct', true)->firstOrFail();
        $wrongOption = $inferenceQuestion->options()->where('is_correct', false)->firstOrFail();

        $this->actingAs($student)->putJson(
            route('attempts.answers.update', [$attempt->public_id, $informationQuestion]),
            ['option_ids' => [$correctOption->id]],
        )->assertOk();

        $this->actingAs($student)->putJson(
            route('attempts.answers.update', [$attempt->public_id, $inferenceQuestion]),
            ['option_ids' => [$wrongOption->id]],
        )->assertOk();

        $this->actingAs($student)
            ->post(route('attempts.submit', $attempt->public_id))
            ->assertRedirect(route('attempts.result', $attempt->public_id));

        $attempt->refresh();
        $this->assertSame(AttemptStatus::Submitted, $attempt->status);
        $this->assertSame('1.00', $attempt->score);
        $this->assertSame('2.00', $attempt->max_score);
        $this->assertGreaterThanOrEqual(0, $attempt->duration_seconds);
        $this->assertDatabaseHas('competency_results', [
            'attempt_id' => $attempt->id,
            'competency_id' => $inferenceQuestion->competency_id,
            'percentage' => 0,
        ]);
        $this->assertDatabaseHas('recommendations', [
            'attempt_id' => $attempt->id,
            'question_id' => $inferencePractice->id,
            'position' => 1,
        ]);
        $this->assertDatabaseHas('chat_rooms', ['student_id' => $student->id]);
        $this->assertDatabaseHas('chat_messages', [
            'attempt_id' => $attempt->id,
            'sender_type' => 'assistant',
            'type' => 'attempt_summary',
            'status' => 'completed',
        ]);

        $this->actingAs($student)
            ->post(route('attempts.practice-chat', $attempt->public_id))
            ->assertRedirect(route('student-chat.show'));

        $practiceRequest = $attempt->student->chatRoom->messages()
            ->where('source_key', "attempt-practice-request:{$attempt->id}")
            ->firstOrFail();
        $practiceReply = $attempt->student->chatRoom->messages()
            ->where('source_key', "attempt-practice-reply:{$attempt->id}")
            ->firstOrFail();

        $this->assertStringContainsString('Membuat inferensi', $practiceRequest->content);
        $this->assertStringContainsString($inferenceQuestion->prompt, $practiceRequest->content);
        $this->assertSame('completed', $practiceReply->status);
        $this->assertStringContainsString('Contoh soal', $practiceReply->content);

        $this->actingAs($student)
            ->post(route('attempts.practice-chat', $attempt->public_id))
            ->assertRedirect(route('student-chat.show'));
        $this->assertSame(1, $attempt->student->chatRoom->messages()
            ->where('source_key', "attempt-practice-request:{$attempt->id}")
            ->count());
    }

    public function test_attempt_integrity_event_is_recorded(): void
    {
        [$student, $assessment] = $this->scenario();
        $this->actingAs($student)->post(route('attempts.start', $assessment));
        $attempt = Attempt::firstOrFail();

        $this->actingAs($student)
            ->postJson(route('attempts.events.store', $attempt->public_id), [
                'event_type' => 'tab_hidden',
                'payload' => ['question_id' => 1],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('attempt_events', [
            'attempt_id' => $attempt->id,
            'event_type' => 'tab_hidden',
        ]);
    }

    public function test_attempt_uses_frozen_question_snapshot_for_display_and_scoring(): void
    {
        config()->set('queue.default', 'sync');
        [$student, $assessment, $informationQuestion] = $this->scenario();
        $originalCorrect = $informationQuestion->options()->where('is_correct', true)->firstOrFail();
        $originalWrong = $informationQuestion->options()->where('is_correct', false)->firstOrFail();

        $this->actingAs($student)
            ->post(route('attempts.start', $assessment))
            ->assertRedirect();

        $snapshot = json_decode(
            $assessment->questions()->whereKey($informationQuestion->id)->firstOrFail()->pivot->snapshot,
            true,
        );
        $this->assertSame('Pilih jawaban yang tepat.', $snapshot['prompt']);
        $this->assertTrue(collect($snapshot['options'])->firstWhere('id', $originalCorrect->id)['is_correct']);

        $informationQuestion->update(['prompt' => 'Pertanyaan hidup sudah berubah.']);
        $originalCorrect->update(['content' => 'Sekarang dianggap salah', 'is_correct' => false]);
        $originalWrong->update(['content' => 'Sekarang dianggap benar', 'is_correct' => true]);
        $attempt = Attempt::firstOrFail();

        $this->actingAs($student)
            ->get(route('attempts.show', $attempt->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('attempt.questions.0.prompt', 'Pilih jawaban yang tepat.')
                ->where('attempt.questions.0.options.0.content', 'Jawaban benar'));

        $this->actingAs($student)->putJson(
            route('attempts.answers.update', [$attempt->public_id, $informationQuestion]),
            ['option_ids' => [$originalCorrect->id]],
        )->assertOk();
        $this->actingAs($student)
            ->post(route('attempts.submit', $attempt->public_id))
            ->assertRedirect(route('attempts.result', $attempt->public_id));

        $this->assertTrue($attempt->answers()->where('question_id', $informationQuestion->id)->firstOrFail()->is_correct);
    }

    public function test_matching_answer_is_autosaved_and_scored_without_exposing_answer_key(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$student, $assessment, $informationQuestion, , , $teacher] = $this->scenario();
        $matchingQuestion = Question::create([
            'school_id' => $teacher->school_id,
            'author_id' => $teacher->id,
            'competency_id' => $informationQuestion->competency_id,
            'type' => 'matching',
            'status' => QuestionStatus::Published,
            'title' => 'Menjodohkan tokoh',
            'prompt' => 'Pasangkan deskripsi dengan tokoh.',
            'difficulty' => 2,
            'grade_level' => 5,
            'metadata' => [
                'matching_pairs' => [
                    ['left_id' => '00000000-0000-4000-8000-000000000001', 'left' => 'Deskripsi satu', 'right_id' => '10000000-0000-4000-8000-000000000001', 'right' => 'Tokoh A'],
                    ['left_id' => '00000000-0000-4000-8000-000000000002', 'left' => 'Deskripsi dua', 'right_id' => '10000000-0000-4000-8000-000000000002', 'right' => 'Tokoh B'],
                    ['left_id' => '00000000-0000-4000-8000-000000000003', 'left' => 'Deskripsi tiga', 'right_id' => '10000000-0000-4000-8000-000000000003', 'right' => 'Tokoh C'],
                ],
                'matching_distractors' => [
                    ['id' => '20000000-0000-4000-8000-000000000001', 'content' => 'Bukan tokoh'],
                ],
            ],
        ]);
        $assessment->questions()->sync([
            $matchingQuestion->id => ['position' => 1, 'points' => 1],
        ]);
        $assessment->update(['settings' => ['require_all_answers' => true]]);

        $this->actingAs($student)->post(route('attempts.start', $assessment));
        $attempt = Attempt::firstOrFail();

        $this->actingAs($student)
            ->get(route('attempts.show', $attempt->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('attempt.questions.0.type', 'matching')
                ->has('attempt.questions.0.matching.left_items', 3)
                ->has('attempt.questions.0.matching.right_items', 4)
                ->missing('attempt.questions.0.matching.answer_key'));

        $matches = [
            '00000000-0000-4000-8000-000000000001' => '10000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002' => '10000000-0000-4000-8000-000000000002',
            '00000000-0000-4000-8000-000000000003' => '10000000-0000-4000-8000-000000000003',
        ];
        $this->actingAs($student)->putJson(
            route('attempts.answers.update', [$attempt->public_id, $matchingQuestion]),
            ['matches' => [
                '00000000-0000-4000-8000-000000000001' => 'right-id-tidak-valid',
            ]],
        )->assertRedirect()->assertSessionHasErrors('matches');
        $this->actingAs($student)->putJson(
            route('attempts.answers.update', [$attempt->public_id, $matchingQuestion]),
            ['matches' => array_slice($matches, 0, 2, true)],
        )->assertOk();
        $this->actingAs($student)
            ->post(route('attempts.submit', $attempt->public_id))
            ->assertSessionHasErrors('attempt');

        $this->actingAs($student)->putJson(
            route('attempts.answers.update', [$attempt->public_id, $matchingQuestion]),
            ['matches' => $matches],
        )->assertOk();
        $this->assertSame($matches, $attempt->answers()->firstOrFail()->response['matches']);

        $this->actingAs($student)
            ->post(route('attempts.submit', $attempt->public_id))
            ->assertRedirect(route('attempts.result', $attempt->public_id));

        $attempt->refresh();
        $this->assertSame('1.00', $attempt->score);
        $this->assertSame('1.00', $attempt->max_score);
        $this->assertTrue($attempt->answers()->firstOrFail()->is_correct);
    }

    public function test_category_matrix_answer_is_autosaved_and_scored_without_exposing_answer_key(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$student, $assessment, $informationQuestion, , , $teacher] = $this->scenario();
        $matrixQuestion = Question::create([
            'school_id' => $teacher->school_id,
            'author_id' => $teacher->id,
            'competency_id' => $informationQuestion->competency_id,
            'type' => 'category_matrix',
            'status' => QuestionStatus::Published,
            'title' => 'Kebutuhan gambar pendukung',
            'prompt' => 'Pilih kategori untuk setiap pernyataan.',
            'difficulty' => 2,
            'grade_level' => 5,
            'metadata' => [
                'matrix_columns' => [
                    ['id' => '30000000-0000-4000-8000-000000000001', 'label' => 'Perlu'],
                    ['id' => '30000000-0000-4000-8000-000000000002', 'label' => 'Tidak Perlu'],
                ],
                'matrix_rows' => [
                    [
                        'id' => '40000000-0000-4000-8000-000000000001',
                        'statement' => 'Gambar makanan dari rempah.',
                        'correct_column_id' => '30000000-0000-4000-8000-000000000001',
                    ],
                    [
                        'id' => '40000000-0000-4000-8000-000000000002',
                        'statement' => 'Gambar penyakit akibat rempah.',
                        'correct_column_id' => '30000000-0000-4000-8000-000000000002',
                    ],
                ],
            ],
        ]);
        $assessment->questions()->sync([
            $matrixQuestion->id => ['position' => 1, 'points' => 1],
        ]);
        $assessment->update(['settings' => ['require_all_answers' => true]]);

        $this->actingAs($student)->post(route('attempts.start', $assessment));
        $attempt = Attempt::firstOrFail();

        $this->actingAs($student)
            ->get(route('attempts.show', $attempt->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('attempt.questions.0.type', 'category_matrix')
                ->has('attempt.questions.0.matrix.columns', 2)
                ->has('attempt.questions.0.matrix.rows', 2)
                ->missing('attempt.questions.0.matrix.answer_key')
                ->missing('attempt.questions.0.matrix.rows.0.correct_column_id'));

        $answers = [
            '40000000-0000-4000-8000-000000000001' => '30000000-0000-4000-8000-000000000001',
            '40000000-0000-4000-8000-000000000002' => '30000000-0000-4000-8000-000000000002',
        ];
        $this->actingAs($student)->putJson(
            route('attempts.answers.update', [$attempt->public_id, $matrixQuestion]),
            ['matrix_answers' => [
                '40000000-0000-4000-8000-000000000001' => 'column-id-tidak-valid',
            ]],
        )->assertRedirect()->assertSessionHasErrors('matrix_answers');
        $this->actingAs($student)->putJson(
            route('attempts.answers.update', [$attempt->public_id, $matrixQuestion]),
            ['matrix_answers' => array_slice($answers, 0, 1, true)],
        )->assertOk();
        $this->actingAs($student)
            ->post(route('attempts.submit', $attempt->public_id))
            ->assertSessionHasErrors('attempt');

        $this->actingAs($student)->putJson(
            route('attempts.answers.update', [$attempt->public_id, $matrixQuestion]),
            ['matrix_answers' => $answers],
        )->assertOk();
        $this->assertSame($answers, $attempt->answers()->firstOrFail()->response['matrix_answers']);

        $this->actingAs($student)
            ->post(route('attempts.submit', $attempt->public_id))
            ->assertRedirect(route('attempts.result', $attempt->public_id));

        $attempt->refresh();
        $this->assertSame('1.00', $attempt->score);
        $this->assertSame('1.00', $attempt->max_score);
        $this->assertTrue($attempt->answers()->firstOrFail()->is_correct);
    }

    public function test_expired_attempt_is_submitted_on_open(): void
    {
        config()->set('queue.default', 'sync');
        [$student, $assessment] = $this->scenario();
        $this->actingAs($student)->post(route('attempts.start', $assessment));
        $attempt = Attempt::firstOrFail();
        $attempt->update(['started_at' => now()->subMinutes(31)]);

        $this->actingAs($student)
            ->get(route('attempts.show', $attempt->public_id))
            ->assertRedirect(route('attempts.result', $attempt->public_id));

        $this->assertSame(AttemptStatus::Submitted, $attempt->fresh()->status);
    }

    public function test_teacher_can_create_a_custom_automatic_assessment_with_exact_settings(): void
    {
        [, , , , , $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->post(route('assessments.store'), [
                'title' => 'Seleksi Literasi Sekolah',
                'description' => 'Paket custom untuk seleksi internal.',
                'grade_level' => 5,
                'duration_minutes' => 45,
                'assessment_type' => 'custom',
                'custom_type_name' => 'Seleksi Literasi',
                'selection_mode' => 'automatic',
                'question_count' => 2,
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'shuffle_questions' => true,
                'shuffle_options' => true,
                'show_navigation' => false,
                'require_all_answers' => true,
            ])
            ->assertRedirect(route('assessments.index'));

        $assessment = Assessment::query()->where('title', 'Seleksi Literasi Sekolah')->firstOrFail();
        $this->assertCount(2, $assessment->questions);
        $this->assertSame(45, $assessment->duration_minutes);
        $this->assertSame('Seleksi Literasi', $assessment->settings['type_label']);
        $this->assertSame('automatic', $assessment->settings['selection_mode']);
        $this->assertTrue($assessment->settings['shuffle_questions']);
        $this->assertTrue($assessment->settings['shuffle_options']);
        $this->assertFalse($assessment->settings['show_navigation']);
        $this->assertTrue($assessment->settings['require_all_answers']);
    }

    public function test_teacher_can_create_assessment_from_exact_blueprint_composition(): void
    {
        [, , $informationQuestion, $inferenceQuestion, $inferencePractice, $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->post(route('assessments.store'), [
                'title' => 'Blueprint Literasi',
                'description' => 'Komposisi kompetensi terstruktur.',
                'grade_level' => 5,
                'duration_minutes' => 45,
                'assessment_type' => 'tryout',
                'selection_mode' => 'blueprint',
                'question_count' => 3,
                'blueprint_rows' => [
                    [
                        'competency_id' => $informationQuestion->competency_id,
                        'type' => 'single_choice',
                        'difficulty' => 1,
                        'count' => 1,
                    ],
                    [
                        'competency_id' => $inferenceQuestion->competency_id,
                        'type' => 'single_choice',
                        'difficulty' => 1,
                        'count' => 2,
                    ],
                ],
                'shuffle_questions' => true,
                'shuffle_options' => true,
                'show_navigation' => true,
                'require_all_answers' => false,
            ])
            ->assertRedirect(route('assessments.index'));

        $assessment = Assessment::query()->where('title', 'Blueprint Literasi')->firstOrFail();
        $this->assertSame('blueprint', $assessment->settings['selection_mode']);
        $this->assertSame(3, $assessment->settings['question_count']);
        $this->assertCount(2, $assessment->settings['blueprint_rows']);
        $this->assertEqualsCanonicalizing(
            [$informationQuestion->id, $inferenceQuestion->id, $inferencePractice->id],
            $assessment->questions->pluck('id')->all(),
        );
    }

    public function test_teacher_can_create_assessment_with_question_count_per_competency(): void
    {
        [, , $informationQuestion, $inferenceQuestion, $inferencePractice, $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->post(route('assessments.store'), [
                'title' => 'Komposisi Kompetensi Literasi',
                'description' => 'Kuota sederhana per kompetensi.',
                'grade_level' => 5,
                'duration_minutes' => 45,
                'assessment_type' => 'tryout',
                'selection_mode' => 'competency',
                'question_count' => 3,
                'competency_rows' => [
                    [
                        'competency_id' => $informationQuestion->competency_id,
                        'count' => 1,
                    ],
                    [
                        'competency_id' => $inferenceQuestion->competency_id,
                        'count' => 2,
                    ],
                ],
                'shuffle_questions' => true,
                'shuffle_options' => true,
                'show_navigation' => true,
                'require_all_answers' => false,
            ])
            ->assertRedirect(route('assessments.index'));

        $assessment = Assessment::query()->where('title', 'Komposisi Kompetensi Literasi')->firstOrFail();
        $this->assertSame('competency', $assessment->settings['selection_mode']);
        $this->assertCount(2, $assessment->settings['competency_rows']);
        $this->assertEqualsCanonicalizing(
            [$informationQuestion->id, $inferenceQuestion->id, $inferencePractice->id],
            $assessment->questions->pluck('id')->all(),
        );
    }

    public function test_manual_assessment_requires_the_selected_question_count_to_match(): void
    {
        [, , $informationQuestion, , , $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->post(route('assessments.store'), [
                'title' => 'Paket manual',
                'grade_level' => 5,
                'duration_minutes' => 30,
                'assessment_type' => 'tryout',
                'selection_mode' => 'manual',
                'question_count' => 2,
                'question_ids' => [$informationQuestion->id],
                'shuffle_questions' => false,
                'shuffle_options' => false,
                'show_navigation' => true,
                'require_all_answers' => false,
            ])
            ->assertSessionHasErrors('question_ids');
    }

    public function test_required_answers_prevent_early_submission_but_not_timeout_submission(): void
    {
        config()->set('queue.default', 'sync');
        [$student, $assessment] = $this->scenario();
        $assessment->update(['settings' => ['require_all_answers' => true]]);
        $this->actingAs($student)->post(route('attempts.start', $assessment));
        $attempt = Attempt::firstOrFail();

        $this->actingAs($student)
            ->post(route('attempts.submit', $attempt->public_id))
            ->assertSessionHasErrors('attempt');
        $this->assertSame(AttemptStatus::InProgress, $attempt->fresh()->status);

        $attempt->update(['started_at' => now()->subMinutes(31)]);
        $this->actingAs($student)
            ->post(route('attempts.submit', $attempt->public_id))
            ->assertRedirect(route('attempts.result', $attempt->public_id));
        $this->assertSame(AttemptStatus::Submitted, $attempt->fresh()->status);
    }

    public function test_teacher_can_edit_an_assessment_before_any_attempt_exists(): void
    {
        [, $assessment, $informationQuestion, , , $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->get(route('assessments.edit', $assessment))
            ->assertOk();

        $this->actingAs($teacher)
            ->put(route('assessments.update', $assessment), [
                'title' => 'Diagnostik Literasi Diperbarui',
                'description' => 'Petunjuk baru.',
                'grade_level' => 5,
                'duration_minutes' => 75,
                'assessment_type' => 'diagnostic',
                'custom_type_name' => '',
                'selection_mode' => 'manual',
                'question_count' => 1,
                'question_ids' => [$informationQuestion->id],
                'starts_at' => null,
                'ends_at' => null,
                'shuffle_questions' => true,
                'shuffle_options' => false,
                'show_navigation' => true,
                'require_all_answers' => false,
            ])
            ->assertRedirect(route('assessments.index'));

        $assessment->refresh();
        $this->assertSame('Diagnostik Literasi Diperbarui', $assessment->title);
        $this->assertSame(75, $assessment->duration_minutes);
        $this->assertSame(AssessmentStatus::Draft, $assessment->status);
        $this->assertSame('Tes Diagnostik', $assessment->settings['type_label']);
        $this->assertCount(1, $assessment->questions);
    }

    public function test_assessment_cannot_be_edited_after_a_student_starts_it(): void
    {
        [$student, $assessment, , , , $teacher] = $this->scenario();
        $this->actingAs($student)->post(route('attempts.start', $assessment));

        $this->actingAs($teacher)
            ->get(route('assessments.edit', $assessment))
            ->assertStatus(409);
    }

    private function scenario(): array
    {
        $school = School::create(['name' => 'Sekolah Uji', 'npsn' => '10000001']);
        $teacher = User::create([
            'school_id' => $school->id,
            'name' => 'Guru',
            'email' => 'guru-attempt@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'email_verified_at' => now(),
        ]);
        $student = User::create([
            'school_id' => $school->id,
            'name' => 'Murid',
            'email' => 'murid-attempt@example.com',
            'password' => 'password',
            'role' => UserRole::Student,
            'grade_level' => 5,
            'email_verified_at' => now(),
        ]);
        $information = Competency::create([
            'school_id' => $school->id,
            'code' => 'LIT5-INFO',
            'domain' => 'Literasi',
            'name' => 'Menemukan informasi',
            'grade_level' => 5,
        ]);
        $inference = Competency::create([
            'school_id' => $school->id,
            'code' => 'LIT5-INFER',
            'domain' => 'Literasi',
            'name' => 'Membuat inferensi',
            'grade_level' => 5,
        ]);

        $informationQuestion = $this->question($teacher, $information, 'Soal informasi');
        $inferenceQuestion = $this->question($teacher, $inference, 'Soal inferensi');
        $inferencePractice = $this->question($teacher, $inference, 'Latihan inferensi');
        $assessment = Assessment::create([
            'school_id' => $school->id,
            'created_by' => $teacher->id,
            'title' => 'Try Out Uji',
            'grade_level' => 5,
            'duration_minutes' => 30,
            'status' => AssessmentStatus::Published,
        ]);
        $assessment->questions()->attach([
            $informationQuestion->id => ['position' => 1, 'points' => 1],
            $inferenceQuestion->id => ['position' => 2, 'points' => 1],
        ]);

        return [$student, $assessment, $informationQuestion, $inferenceQuestion, $inferencePractice, $teacher];
    }

    private function question(User $teacher, Competency $competency, string $title): Question
    {
        $question = Question::create([
            'school_id' => $teacher->school_id,
            'author_id' => $teacher->id,
            'competency_id' => $competency->id,
            'type' => 'single_choice',
            'status' => QuestionStatus::Published,
            'title' => $title,
            'prompt' => 'Pilih jawaban yang tepat.',
            'difficulty' => 1,
            'grade_level' => 5,
        ]);
        $question->options()->createMany([
            ['label' => 'A', 'content' => 'Jawaban benar', 'is_correct' => true, 'position' => 1],
            ['label' => 'B', 'content' => 'Jawaban salah', 'is_correct' => false, 'position' => 2],
        ]);

        return $question;
    }
}
