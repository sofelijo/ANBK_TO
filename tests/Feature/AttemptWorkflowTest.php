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
use App\Models\Subject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttemptWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-08-08 18:00'));
    }

    public function test_student_can_view_and_start_published_assessment_from_another_school(): void
    {
        [, $assessment] = $this->scenario();
        $this->assertNull($assessment->school_id);
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
            'grade_level' => 6,
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

    public function test_teacher_can_manage_global_assessment_created_from_another_school(): void
    {
        [, $assessment] = $this->scenario();
        $otherSchool = School::create(['name' => 'Sekolah Guru Lain', 'npsn' => '10000012']);
        $otherTeacher = User::create([
            'school_id' => $otherSchool->id,
            'name' => 'Guru Sekolah Lain',
            'email' => 'guru-sekolah-lain@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'email_verified_at' => now(),
        ]);
        $globalCandidate = $assessment->questions()->first()->replicate();
        $globalCandidate->story_generation_id = null;
        $globalCandidate->title = 'Soal Global Lintas Sekolah';
        $globalCandidate->status = QuestionStatus::Published;
        $globalCandidate->save();

        $this->actingAs($otherTeacher)
            ->get(route('assessments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('assessments.0.id', $assessment->id)
                ->where('assessments.0.can_manage', true));

        $this->actingAs($otherTeacher)
            ->get(route('assessments.show', $assessment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('assessment.id', $assessment->id)
                ->where('canManage', true)
                ->where('canPreview', true)
                ->where('availableBankQuestions', fn ($questions): bool => $questions
                    ->contains('id', $globalCandidate->id)));

        $this->actingAs($otherTeacher)
            ->post(route('assessments.questions.attach', $assessment), [
                'question_id' => $globalCandidate->id,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('assessment_question', [
            'assessment_id' => $assessment->id,
            'question_id' => $globalCandidate->id,
        ]);

        $this->actingAs($otherTeacher)
            ->get(route('assessments.preview', $assessment))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attempts/Show')
                ->where('preview', true));

        $this->actingAs($otherTeacher)
            ->get(route('assessments.edit', $assessment))
            ->assertOk();

        $emptyAssessment = Assessment::create([
            'school_id' => $assessment->school_id,
            'created_by' => $assessment->created_by,
            'title' => 'Paket Publik Kosong',
            'grade_level' => 6,
            'duration_minutes' => 30,
            'status' => AssessmentStatus::Published,
        ]);

        $this->actingAs($otherTeacher)
            ->get(route('assessments.show', $emptyAssessment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('assessment.id', $emptyAssessment->id)
                ->where('canManage', true)
                ->where('canPreview', false));

        $this->actingAs($otherTeacher)
            ->get(route('assessments.preview', $emptyAssessment))
            ->assertStatus(409);
    }

    public function test_assessment_list_can_be_filtered_by_single_subject_or_mixed_subjects(): void
    {
        [$student, $indonesianAssessment, $indonesianQuestion, $secondIndonesianQuestion, $mathematicsQuestion, $teacher] = $this->scenario();
        $indonesian = Subject::create(['school_id' => $teacher->school_id, 'code' => 'BIND', 'name' => 'Bahasa Indonesia']);
        $mathematics = Subject::create(['school_id' => $teacher->school_id, 'code' => 'MAT', 'name' => 'Matematika']);
        Subject::create(['school_id' => null, 'code' => 'MAT-MATRIX', 'name' => 'Matematika']);
        $indonesianQuestion->competency()->update(['subject_id' => $indonesian->id]);
        $indonesianAssessment->questions()->detach($secondIndonesianQuestion->id);
        $mathematicsQuestion->competency()->update(['subject_id' => $mathematics->id]);

        $mathematicsAssessment = Assessment::create([
            'school_id' => $teacher->school_id,
            'subject_id' => $mathematics->id,
            'created_by' => $teacher->id,
            'title' => 'Try Out Matematika',
            'grade_level' => 6,
            'duration_minutes' => 30,
            'status' => AssessmentStatus::Published,
        ]);
        $mathematicsAssessment->questions()->attach($mathematicsQuestion->id, ['position' => 1, 'points' => 1]);
        $mixedAssessment = Assessment::create([
            'school_id' => $teacher->school_id,
            'created_by' => $teacher->id,
            'title' => 'Try Out Campuran',
            'grade_level' => 6,
            'duration_minutes' => 30,
            'status' => AssessmentStatus::Published,
        ]);
        $mixedAssessment->questions()->attach([
            $indonesianQuestion->id => ['position' => 1, 'points' => 1],
            $mathematicsQuestion->id => ['position' => 2, 'points' => 1],
        ]);
        $otherSchool = School::create(['name' => 'Sekolah Pengguna Lain', 'npsn' => '90000009']);
        $otherSchoolStudent = User::create([
            'school_id' => $otherSchool->id,
            'name' => 'Siswa Sekolah Pengguna Lain',
            'email' => 'student-global-subject@example.com',
            'password' => 'password',
            'role' => UserRole::Student,
            'grade_level' => 6,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($student)
            ->get(route('assessments.index', ['subject' => $indonesian->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments', 1)
                ->where('assessments.0.id', $indonesianAssessment->id)
                ->where('assessments.0.subject_label', 'Bahasa Indonesia'));
        $this->actingAs($student)
            ->get(route('assessments.index', ['subject' => 'subject:matematika']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments', 1)
                ->where('assessments.0.id', $mathematicsAssessment->id)
                ->has('subjects', 2)
                ->where('subjects.1.value', 'subject:matematika')
                ->where('subjects.1.code', 'MAT'));
        $this->actingAs($student)
            ->get(route('assessments.index', ['subject' => 'mixed']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments', 1)
                ->where('assessments.0.id', $mixedAssessment->id)
                ->where('assessments.0.subject_label', 'Campuran'));
        $this->actingAs($otherSchoolStudent)
            ->get(route('assessments.index', ['subject' => 'subject:matematika']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments', 1)
                ->where('assessments.0.id', $mathematicsAssessment->id)
                ->where('subjects', fn ($subjects): bool => $subjects
                    ->where('value', 'subject:matematika')
                    ->count() === 1));
    }

    public function test_assessment_list_can_be_filtered_by_regular_or_together_type(): void
    {
        [$student, $regularAssessment] = $this->scenario();
        $togetherAssessment = $regularAssessment->replicate();
        $togetherAssessment->title = 'Try Out Bersama';
        $togetherAssessment->settings = [
            'type' => Assessment::TYPE_TOGETHER,
            'type_label' => 'Try Out Bersama',
        ];
        $togetherAssessment->save();

        $this->actingAs($student)
            ->get(route('assessments.index', ['type' => 'regular']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments', 1)
                ->where('assessments.0.id', $regularAssessment->id)
                ->where('filters.type', 'regular'));

        $this->actingAs($student)
            ->get(route('assessments.index', ['type' => 'together']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments', 1)
                ->where('assessments.0.id', $togetherAssessment->id)
                ->where('filters.type', 'together'));
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
        $informationQuestion->update(['explanation' => 'Jawaban benar dipilih berdasarkan informasi pada soal.']);
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
                ->where('attempt.remaining_seconds', fn ($value) => is_int($value))
                ->where('attempt.questions.0.prompt', 'Pilih jawaban yang tepat.')
                ->where('attempt.questions.0.options.0.content', 'Jawaban benar')
                ->missing('attempt.questions.0.title'));

        $this->actingAs($student)->putJson(
            route('attempts.answers.update', [$attempt->public_id, $informationQuestion]),
            ['option_ids' => [$originalCorrect->id]],
        )->assertOk();
        $this->actingAs($student)
            ->post(route('attempts.submit', $attempt->public_id))
            ->assertRedirect(route('attempts.result', $attempt->public_id));

        $this->actingAs($student)
            ->get(route('attempts.result', $attempt->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attempts/Result')
                ->where('questionReviews.0.prompt', 'Pilih jawaban yang tepat.')
                ->where('questionReviews.0.explanation', 'Jawaban benar dipilih berdasarkan informasi pada soal.')
                ->where('questionReviews.0.is_correct', true));

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
            'grade_level' => 6,
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
            'stimulus' => 'Baca setiap pernyataan dengan teliti.',
            'prompt' => 'Pilih kategori untuk setiap pernyataan.',
            'difficulty' => 2,
            'grade_level' => 6,
            'metadata' => [
                'stimulus_text_style' => [
                    'font_family' => 'serif',
                    'font_size' => 'lg',
                    'text_align' => 'justify',
                    'line_spacing' => 'loose',
                ],
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
                ->where('attempt.questions.0.stimulus_text_style.font_family', 'serif')
                ->where('attempt.questions.0.stimulus_text_style.font_size', 'lg')
                ->where('attempt.questions.0.stimulus_text_style.text_align', 'justify')
                ->where('attempt.questions.0.stimulus_text_style.line_spacing', 'loose')
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

    public function test_teacher_can_create_a_together_automatic_assessment_with_exact_settings(): void
    {
        [, , , , , $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->post(route('assessments.store'), [
                'title' => 'Seleksi Literasi Sekolah',
                'description' => 'Paket custom untuk seleksi internal.',
                'grade_level' => 6,
                'duration_minutes' => 45,
                'assessment_type' => 'together',
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
        $this->assertNull($assessment->school_id);
        $this->assertCount(2, $assessment->questions);
        $this->assertSame(45, $assessment->duration_minutes);
        $this->assertSame('Try Out Bersama', $assessment->settings['type_label']);
        $this->assertSame('automatic', $assessment->settings['selection_mode']);
        $this->assertTrue($assessment->settings['shuffle_questions']);
        $this->assertTrue($assessment->settings['shuffle_options']);
        $this->assertFalse($assessment->settings['show_navigation']);
        $this->assertTrue($assessment->settings['require_all_answers']);
    }

    public function test_automatic_assessment_prioritizes_questions_least_shown_to_the_school(): void
    {
        [$student, $existingAssessment, , , $neverShownQuestion, $teacher] = $this->scenario();

        $this->actingAs($student)
            ->post(route('attempts.start', $existingAssessment))
            ->assertRedirect();

        $this->actingAs($teacher)
            ->post(route('assessments.store'), [
                'title' => 'Paket Minim Pengulangan',
                'grade_level' => 6,
                'duration_minutes' => 30,
                'assessment_type' => 'regular',
                'selection_mode' => 'automatic',
                'question_count' => 1,
                'shuffle_questions' => false,
                'shuffle_options' => false,
                'show_navigation' => true,
                'require_all_answers' => false,
            ])
            ->assertRedirect(route('assessments.index'));

        $assessment = Assessment::query()->where('title', 'Paket Minim Pengulangan')->firstOrFail();
        $this->assertSame([$neverShownQuestion->id], $assessment->questions->pluck('id')->all());
    }

    public function test_each_student_receives_questions_based_on_their_own_usage_history(): void
    {
        [$firstStudent, $existingAssessment, $informationQuestion, $inferenceQuestion, $neverShownQuestion, $teacher] = $this->scenario();

        $this->actingAs($firstStudent)
            ->post(route('attempts.start', $existingAssessment))
            ->assertRedirect();

        $this->actingAs($teacher)->post(route('assessments.store'), [
            'title' => 'Paket Personal Siswa',
            'grade_level' => 6,
            'duration_minutes' => 30,
            'assessment_type' => 'regular',
            'selection_mode' => 'automatic',
            'question_count' => 1,
            'shuffle_questions' => false,
            'shuffle_options' => false,
            'show_navigation' => true,
            'require_all_answers' => false,
        ]);
        $personalAssessment = Assessment::query()->where('title', 'Paket Personal Siswa')->firstOrFail();
        $personalAssessment->update(['status' => AssessmentStatus::Published]);

        $secondStudent = User::create([
            'school_id' => $firstStudent->school_id,
            'name' => 'Murid Kedua',
            'email' => 'murid-kedua@example.com',
            'password' => 'password',
            'role' => UserRole::Student,
            'grade_level' => 6,
            'email_verified_at' => now(),
        ]);
        $secondStudentHistory = Assessment::create([
            'school_id' => $teacher->school_id,
            'created_by' => $teacher->id,
            'title' => 'Riwayat Murid Kedua',
            'grade_level' => 6,
            'duration_minutes' => 30,
            'status' => AssessmentStatus::Published,
        ]);
        $secondStudentHistory->questions()->attach([
            $neverShownQuestion->id => ['position' => 1, 'points' => 1],
        ]);
        $this->actingAs($secondStudent)
            ->post(route('attempts.start', $secondStudentHistory))
            ->assertRedirect();

        $this->actingAs($firstStudent)
            ->post(route('attempts.start', $personalAssessment))
            ->assertRedirect();
        $this->actingAs($secondStudent)
            ->post(route('attempts.start', $personalAssessment))
            ->assertRedirect();

        $firstQuestionIds = Attempt::query()
            ->where('assessment_id', $personalAssessment->id)
            ->where('user_id', $firstStudent->id)
            ->firstOrFail()
            ->questions()
            ->pluck('questions.id')
            ->all();
        $secondQuestionIds = Attempt::query()
            ->where('assessment_id', $personalAssessment->id)
            ->where('user_id', $secondStudent->id)
            ->firstOrFail()
            ->questions()
            ->pluck('questions.id')
            ->all();

        $this->assertSame([$neverShownQuestion->id], $firstQuestionIds);
        $this->assertNotContains($neverShownQuestion->id, $secondQuestionIds);
        $this->assertContains($secondQuestionIds[0], [$informationQuestion->id, $inferenceQuestion->id]);
        $this->assertNotSame($firstQuestionIds, $secondQuestionIds);
    }

    public function test_teacher_can_create_assessment_from_exact_blueprint_composition(): void
    {
        [, , $informationQuestion, $inferenceQuestion, $inferencePractice, $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->post(route('assessments.store'), [
                'title' => 'Blueprint Literasi',
                'description' => 'Komposisi kompetensi terstruktur.',
                'grade_level' => 6,
                'duration_minutes' => 45,
                'assessment_type' => 'regular',
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
                'grade_level' => 6,
                'duration_minutes' => 45,
                'assessment_type' => 'regular',
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
                'grade_level' => 6,
                'duration_minutes' => 30,
                'assessment_type' => 'regular',
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
        [$student, $assessment, $informationQuestion, , , $teacher] = $this->scenario();

        $this->actingAs($teacher)
            ->get(route('assessments.edit', $assessment))
            ->assertOk();

        $this->actingAs($teacher)
            ->put(route('assessments.update', $assessment), [
                'title' => 'Diagnostik Literasi Diperbarui',
                'description' => 'Petunjuk baru.',
                'grade_level' => 6,
                'duration_minutes' => 75,
                'assessment_type' => 'regular',
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
        $this->assertSame('Try Out Reguler', $assessment->settings['type_label']);
        $this->assertCount(1, $assessment->questions);
        $this->assertSame('Paket try out sedang diperbarui', $student->notifications()->firstOrFail()->data['title']);
    }

    public function test_teacher_can_edit_assessment_even_after_student_starts_it(): void
    {
        [$student, $assessment, , , , $teacher] = $this->scenario();
        $this->actingAs($student)->post(route('attempts.start', $assessment));

        $this->actingAs($teacher)
            ->get(route('assessments.edit', $assessment))
            ->assertOk();
    }

    public function test_teacher_can_preview_student_assessment_view_without_creating_attempt(): void
    {
        [$student, $assessment, , , , $teacher] = $this->scenario();

        $this->assertDatabaseCount('attempts', 0);

        $this->actingAs($teacher)
            ->get(route('assessments.preview', $assessment))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attempts/Show')
                ->where('preview', true)
                ->where('attempt.public_id', "assessment-preview:{$assessment->id}")
                ->where('attempt.assessment.title', $assessment->title)
                ->where('attempt.remaining_seconds', 1800)
                ->has('attempt.questions', 2)
                ->where('attempt.questions.0.response', null));

        $this->assertDatabaseCount('attempts', 0);
        $this->actingAs($student)
            ->get(route('assessments.preview', $assessment))
            ->assertForbidden();
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
            'grade_level' => 6,
            'email_verified_at' => now(),
        ]);
        $information = Competency::create([
            'school_id' => $school->id,
            'code' => 'LIT6-INFO',
            'domain' => 'Literasi',
            'name' => 'Menemukan informasi',
            'grade_level' => 6,
        ]);
        $inference = Competency::create([
            'school_id' => $school->id,
            'code' => 'LIT6-INFER',
            'domain' => 'Literasi',
            'name' => 'Membuat inferensi',
            'grade_level' => 6,
        ]);

        $informationQuestion = $this->question($teacher, $information, 'Soal informasi');
        $inferenceQuestion = $this->question($teacher, $inference, 'Soal inferensi');
        $inferencePractice = $this->question($teacher, $inference, 'Latihan inferensi');
        $assessment = Assessment::create([
            'school_id' => $school->id,
            'created_by' => $teacher->id,
            'title' => 'Try Out Uji',
            'grade_level' => 6,
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
            'grade_level' => 6,
        ]);
        $question->options()->createMany([
            ['label' => 'A', 'content' => 'Jawaban benar', 'is_correct' => true, 'position' => 1],
            ['label' => 'B', 'content' => 'Jawaban salah', 'is_correct' => false, 'position' => 2],
        ]);

        return $question;
    }
}
