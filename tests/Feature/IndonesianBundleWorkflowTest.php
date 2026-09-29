<?php

namespace Tests\Feature;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\AiGeneration;
use App\Models\Competency;
use App\Models\Question;
use App\Models\QuestionBlueprint;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IndonesianBundleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_set_global_bundle_defaults_and_teacher_cannot(): void
    {
        [$school, $admin, $teacher] = $this->context();
        $slots = [
            ['answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ['answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
            ['answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
        ];

        $this->actingAs($admin)
            ->get(route('admin.indonesian-bundles.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/IndonesianBundles/Edit')
                ->where('slots.0.answer_format', 'single_choice')
                ->where('slots.2.cognitive_level', 'evaluation'));

        $this->actingAs($admin)
            ->patch(route('admin.indonesian-bundles.update'), ['slots' => $slots])
            ->assertRedirect();

        $this->assertSame($slots, data_get($school->fresh()->settings, 'indonesian_bundle_defaults.slots'));
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'indonesian_bundle_defaults.updated',
        ]);
        $this->actingAs($teacher)->get(route('admin.indonesian-bundles.edit'))->assertForbidden();
    }

    public function test_duplicate_formats_or_levels_are_rejected(): void
    {
        [, $admin] = $this->context();

        $this->actingAs($admin)->patch(route('admin.indonesian-bundles.update'), ['slots' => [
            ['answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
            ['answer_format' => 'single_choice', 'cognitive_level' => 'inferential'],
            ['answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
        ]])->assertSessionHasErrors('slots');
    }

    public function test_teacher_can_customize_three_complete_slots_for_one_story_bundle(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [, , $teacher, $subject, $competency, $blueprints] = $this->context();
        $slots = [
            ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
            ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
        ];

        $this->actingAs($teacher)
            ->get(route('story-questions.create', ['subject_id' => $subject->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/StoryCreate')
                ->has('indonesianBundleDefaults', 3)
                ->where('questionBlueprints', fn ($items): bool => collect($items)
                    ->firstWhere('code', 'OBJEK-KOSAKATA')['competency_positions'][$competency->id] === 1));

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            'bundle_slots' => $slots,
            'theme' => '',
            'paragraph_count' => 3,
            'question_count' => 3,
        ])->assertRedirect();

        $generation = AiGeneration::firstOrFail();
        $questions = Question::query()->whereIn('id', $generation->result_payload['question_ids'])->orderBy('id')->get();

        $this->assertSame($slots, collect($generation->request_payload['bundle_slots'])->map(fn (array $slot): array => collect($slot)->only(['question_blueprint_id', 'answer_format', 'cognitive_level'])->all())->all());
        $this->assertSame(3, $generation->request_payload['question_count']);
        $this->assertSame(200, $generation->request_payload['max_words']);
        $this->assertSame('ai', $generation->request_payload['theme_source']);
        $this->assertSame('Cerita Kegiatan Sekolah Yang Menarik', $generation->result_payload['title']);
        $this->assertSame(
            [QuestionType::MultipleChoice, QuestionType::SingleChoice, QuestionType::CategoryMatrix],
            $questions->pluck('type')->all(),
        );
        $this->assertSame(
            [$blueprints[1]->id, $blueprints[0]->id, $blueprints[2]->id],
            $questions->pluck('question_blueprint_id')->all(),
        );
        $this->assertSame(
            ['Evaluasi dan Apresiasi (Level 3)', 'Pemahaman Tekstual (Level 1)', 'Pemahaman Inferensial (Level 2)'],
            $questions->pluck('cognitive_level')->all(),
        );
        $this->assertSame([3, 1, 2], $questions->pluck('difficulty')->all());
        $this->assertCount(3, $questions[2]->metadata['matrix_rows']);
    }

    public function test_teacher_can_create_manual_indonesian_bundle_without_ai_or_quota(): void
    {
        Storage::fake('public');
        [, , $teacher, $subject, $competency, $blueprints] = $this->context();
        $stimulusImage = UploadedFile::fake()->image('taman.png', 1200, 675);
        $slots = [
            ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
            ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
        ];

        $this->actingAs($teacher)
            ->get(route('questions.create', ['subject_id' => $subject->id]))
            ->assertRedirect(route('manual-story-bundles.create', ['subject_id' => $subject->id]));

        $this->actingAs($teacher)
            ->get(route('manual-story-bundles.create', ['subject_id' => $subject->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/ManualBundleCreate')
                ->where('subject.code', 'BIND')
                ->has('competencies.0.question_blueprints', 3)
                ->has('bundleDefaults', 3));

        $questions = [
            [
                'prompt' => 'Pernyataan mana yang didukung oleh bacaan?',
                'explanation' => 'Dua jawaban didukung langsung oleh isi bacaan.',
                'options' => [
                    ['content' => 'Warga membersihkan taman.', 'is_correct' => true],
                    ['content' => 'Anak-anak menanam bunga.', 'is_correct' => true],
                    ['content' => 'Taman ditutup permanen.', 'is_correct' => false],
                ],
                'statements' => [],
            ],
            [
                'prompt' => 'Tentukan benar atau salah berdasarkan bacaan.',
                'explanation' => 'Kunci mengacu langsung pada bacaan.',
                'matrix_labels' => ['true_label' => 'Sesuai', 'false_label' => 'Tidak Sesuai'],
                'options' => [],
                'statements' => [
                    ['content' => 'Warga bekerja sama membersihkan taman.', 'is_true' => true],
                    ['content' => 'Tidak ada bunga yang ditanam.', 'is_true' => false],
                    ['content' => 'Anak-anak ikut menanam bunga.', 'is_true' => true],
                ],
            ],
            [
                'prompt' => 'Apa kegiatan utama dalam bacaan?',
                'explanation' => 'Kegiatan utamanya adalah membersihkan taman.',
                'options' => [
                    ['content' => 'Membersihkan taman', 'is_correct' => true],
                    ['content' => 'Menutup sekolah', 'is_correct' => false],
                    ['content' => 'Bermain di sungai', 'is_correct' => false],
                    ['content' => 'Mengadakan lomba', 'is_correct' => false],
                ],
                'statements' => [],
            ],
        ];

        $this->actingAs($teacher)->post(route('manual-story-bundles.store'), [
            'subject_id' => $subject->id,
            'competency_id' => $competency->id,
            'title' => 'Taman Bersih',
            'story' => "Warga sekolah bekerja sama membersihkan taman.\n\nAnak-anak kemudian menanam bunga.",
            'stimulus_image' => $stimulusImage,
            'stimulus_image_alt' => 'Kondisi taman sekolah setelah dibersihkan',
            'bundle_slots' => $slots,
            'questions' => $questions,
        ])->assertRedirect();

        $generation = AiGeneration::sole();
        $createdQuestions = Question::query()->where('story_generation_id', $generation->id)->orderBy('id')->with('options')->get();

        $this->assertSame('manual', $generation->provider);
        $this->assertSame('manual', $generation->request_payload['source']);
        $this->assertSame('review', $generation->request_payload['submission_mode']);
        $this->assertTrue($generation->request_payload['has_stimulus_image']);
        $this->assertSame(200, $generation->request_payload['max_words']);
        $this->assertSame(3, $createdQuestions->count());
        $this->assertTrue($createdQuestions->every(fn (Question $question): bool => $question->status === QuestionStatus::Review));
        $this->assertSame(
            [QuestionType::MultipleChoice, QuestionType::CategoryMatrix, QuestionType::SingleChoice],
            $createdQuestions->pluck('type')->all(),
        );
        $this->assertSame([3, 2, 1], $createdQuestions->pluck('difficulty')->all());
        $this->assertSame([$blueprints[1]->id, $blueprints[2]->id, $blueprints[0]->id], $createdQuestions->pluck('question_blueprint_id')->all());
        $this->assertSame('Taman Bersih', $generation->result_payload['title']);
        $this->assertCount(3, $createdQuestions[1]->metadata['matrix_rows']);
        $this->assertSame(['Sesuai', 'Tidak Sesuai'], collect($createdQuestions[1]->metadata['matrix_columns'])->pluck('label')->all());
        $this->assertCount(3, $createdQuestions[0]->options);
        $imagePath = data_get($createdQuestions[0]->metadata, 'illustration.path');
        Storage::disk('public')->assertExists($imagePath);
        $this->assertTrue($createdQuestions->every(fn (Question $question): bool => data_get($question->metadata, 'illustration.path') === $imagePath));
        $this->assertTrue($createdQuestions->every(fn (Question $question): bool => data_get($question->metadata, 'illustration.alt') === 'Kondisi taman sekolah setelah dibersihkan'));
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $teacher->id,
            'action' => 'manual_story_bundle.created',
        ]);
    }

    public function test_private_manual_draft_cannot_be_verified_until_author_submits_it(): void
    {
        [$school, , $author, $subject, $competency, $blueprints] = $this->context();
        $otherTeacher = $this->user($school, 'Guru Lain', 'guru-lain-bundle@example.com', UserRole::Teacher);

        $this->actingAs($author)->post(route('manual-story-bundles.store'), [
            'subject_id' => $subject->id,
            'competency_id' => $competency->id,
            'title' => 'Draft Pribadi',
            'story' => 'Bacaan lengkap untuk menguji penyimpanan draft pribadi.',
            'submission_mode' => 'draft',
            'bundle_slots' => [
                ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
                ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
                ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ],
            'questions' => [
                [
                    'prompt' => 'Apa isi utama bacaan?',
                    'explanation' => '',
                    'options' => [
                        ['content' => 'Jawaban benar', 'is_correct' => true],
                        ['content' => 'Pengecoh satu', 'is_correct' => false],
                        ['content' => 'Pengecoh dua', 'is_correct' => false],
                        ['content' => 'Pengecoh tiga', 'is_correct' => false],
                    ],
                    'statements' => [],
                ],
                [
                    'prompt' => 'Tentukan benar atau salah.',
                    'explanation' => '',
                    'options' => [],
                    'statements' => [
                        ['content' => 'Pernyataan pertama.', 'is_true' => true],
                        ['content' => 'Pernyataan kedua.', 'is_true' => false],
                        ['content' => 'Pernyataan ketiga.', 'is_true' => true],
                    ],
                ],
                [
                    'prompt' => 'Pilih semua pernyataan yang sesuai.',
                    'explanation' => '',
                    'options' => [
                        ['content' => 'Pernyataan benar satu', 'is_correct' => true],
                        ['content' => 'Pernyataan benar dua', 'is_correct' => true],
                        ['content' => 'Pernyataan salah', 'is_correct' => false],
                    ],
                    'statements' => [],
                ],
            ],
        ])->assertRedirect();

        $generation = AiGeneration::sole();
        $questions = Question::query()->where('story_generation_id', $generation->id)->get();
        $this->assertSame('draft', $generation->request_payload['submission_mode']);
        $this->assertTrue($questions->every(fn (Question $question): bool => $question->status === QuestionStatus::Draft));
        $this->assertTrue($questions->every(fn (Question $question): bool => data_get($question->metadata, 'verification_locked') === true));

        $this->actingAs($author)
            ->get(route('story-questions.show', $generation))
            ->assertOk();

        $this->actingAs($otherTeacher)
            ->get(route('questions.index'))
            ->assertInertia(fn ($page) => $page->has('questions.data', 0));
        $this->actingAs($otherTeacher)
            ->get(route('story-questions.show', $generation))
            ->assertNotFound();
        $this->actingAs($otherTeacher)
            ->get(route('questions.show', $questions->first()))
            ->assertNotFound();

        $this->actingAs($otherTeacher)
            ->post(route('story-questions.publish', $generation))
            ->assertNotFound();
        $this->assertDatabaseCount('question_verifications', 0);

        $this->actingAs($otherTeacher)
            ->post(route('story-questions.submit-review', $generation))
            ->assertNotFound();

        $this->actingAs($author)
            ->post(route('story-questions.submit-review', $generation))
            ->assertRedirect()
            ->assertSessionHas('success');

        $generation->refresh();
        $questions = Question::query()->where('story_generation_id', $generation->id)->get();
        $this->assertSame('review', $generation->request_payload['submission_mode']);
        $this->assertTrue($questions->every(fn (Question $question): bool => $question->status === QuestionStatus::Review));
        $this->assertTrue($questions->every(fn (Question $question): bool => data_get($question->metadata, 'verification_locked') === false));
        $this->assertTrue($questions->every(fn (Question $question): bool => $question->verifications()->where('verifier_id', $author->id)->exists()));
        $this->assertTrue($questions->every(fn (Question $question): bool => $question->verifications()->count() === 1));

        $this->actingAs($otherTeacher)
            ->get(route('story-questions.show', $generation))
            ->assertOk();
    }

    public function test_teacher_can_save_and_resume_a_manual_bundle_draft_with_only_one_text_field(): void
    {
        [$school, , $teacher, $subject, $competency, $blueprints] = $this->context();
        $otherTeacher = $this->user($school, 'Guru Lain', 'guru-lain-partial@example.com', UserRole::Teacher);
        $slots = [
            ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
            ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
            ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
        ];
        $blankQuestion = [
            'prompt' => '',
            'explanation' => '',
            'matrix_labels' => ['true_label' => 'Benar', 'false_label' => 'Salah'],
            'options' => [],
            'statements' => [],
        ];

        $this->actingAs($teacher)->post(route('manual-story-bundles.store'), [
            'subject_id' => $subject->id,
            'competency_id' => $competency->id,
            'title' => 'Baru judul saja',
            'story' => '',
            'submission_mode' => 'draft',
            'bundle_slots' => $slots,
            'questions' => [$blankQuestion, $blankQuestion, $blankQuestion],
        ])->assertRedirect();

        $generation = AiGeneration::sole();
        $this->assertFalse($generation->request_payload['draft_complete']);
        $this->assertSame(3, Question::query()->where('story_generation_id', $generation->id)->count());

        $this->actingAs($teacher)
            ->get(route('manual-story-bundles.create', ['subject_id' => $subject->id, 'draft_id' => $generation->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/ManualBundleCreate')
                ->where('draft.generation_id', $generation->id)
                ->where('draft.title', 'Baru judul saja')
                ->where('draft.story', ''));

        $this->actingAs($teacher)
            ->post(route('story-questions.submit-review', $generation))
            ->assertSessionHasErrors('generation');
        $this->actingAs($otherTeacher)
            ->get(route('manual-story-bundles.create', ['subject_id' => $subject->id, 'draft_id' => $generation->id]))
            ->assertNotFound();
    }

    public function test_teacher_can_update_bundle_stimulus(): void
    {
        Storage::fake('public');
        [, , $teacher, $subject, $competency, $blueprints] = $this->context();
        $slots = [
            ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
            ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
        ];

        $this->actingAs($teacher)->post(route('manual-story-bundles.store'), [
            'subject_id' => $subject->id,
            'competency_id' => $competency->id,
            'title' => 'Judul Asli',
            'story' => 'Paragraf cerita asli sebelum diedit.',
            'bundle_slots' => $slots,
            'questions' => [
                [
                    'prompt' => 'Pernyataan mana yang benar?',
                    'explanation' => '',
                    'options' => [
                        ['content' => 'Opsi 1', 'is_correct' => true],
                        ['content' => 'Opsi 2', 'is_correct' => true],
                        ['content' => 'Opsi 3', 'is_correct' => false],
                    ],
                    'statements' => [],
                ],
                [
                    'prompt' => 'Benar atau salah?',
                    'explanation' => '',
                    'matrix_labels' => ['true_label' => 'Benar', 'false_label' => 'Salah'],
                    'options' => [],
                    'statements' => [
                        ['content' => 'Pernyataan 1', 'is_true' => true],
                        ['content' => 'Pernyataan 2', 'is_true' => false],
                        ['content' => 'Pernyataan 3', 'is_true' => true],
                    ],
                ],
                [
                    'prompt' => 'Apa ide pokoknya?',
                    'explanation' => '',
                    'options' => [
                        ['content' => 'Opsi A', 'is_correct' => true],
                        ['content' => 'Opsi B', 'is_correct' => false],
                        ['content' => 'Opsi C', 'is_correct' => false],
                        ['content' => 'Opsi D', 'is_correct' => false],
                    ],
                    'statements' => [],
                ],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $generation = AiGeneration::sole();

        $this->actingAs($teacher)
            ->put(route('story-questions.update-stimulus', $generation), [
                'title' => 'Judul Baru yang Diedit',
                'story' => "Paragraf pertama yang baru.\n\nParagraf kedua yang baru.",
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Stimulus berhasil diperbarui untuk seluruh soal dalam bundel.');

        $generation->refresh();
        $this->assertSame('Judul Baru yang Diedit', $generation->result_payload['title']);
        $this->assertSame("Paragraf pertama yang baru.\n\nParagraf kedua yang baru.", $generation->result_payload['story']);

        $questions = Question::query()->where('story_generation_id', $generation->id)->get();
        $this->assertCount(3, $questions);
        foreach ($questions as $question) {
            $this->assertSame("Paragraf pertama yang baru.\n\nParagraf kedua yang baru.", $question->stimulus);
        }

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $teacher->id,
            'action' => 'story_generation.stimulus_updated',
        ]);
    }

    public function test_manual_indonesian_bundle_rejects_incomplete_answer_and_level_composition(): void
    {
        [, , $teacher, $subject, $competency, $blueprints] = $this->context();

        $this->actingAs($teacher)->post(route('manual-story-bundles.store'), [
            'subject_id' => $subject->id,
            'competency_id' => $competency->id,
            'title' => 'Judul Bacaan',
            'story' => 'Isi bacaan yang menjadi dasar soal.',
            'bundle_slots' => [
                ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
                ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'inferential'],
                ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ],
            'questions' => array_fill(0, 3, [
                'prompt' => 'Pertanyaan?',
                'explanation' => '',
                'options' => array_fill(0, 4, ['content' => 'Pilihan', 'is_correct' => false]),
                'statements' => [],
            ]),
        ])->assertSessionHasErrors('bundle_slots');

        $this->assertDatabaseCount('ai_generations', 0);
    }

    public function test_ai_story_bundle_draft_and_review_lifecycle(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$school, , $teacher, $subject, $competency, $blueprints] = $this->context();
        $otherTeacher = $this->user($school, 'Guru Lain', 'guru-lain@example.com', UserRole::Teacher);

        $slots = [
            ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
            ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
        ];

        // 1. Buat sebagai draft pribadi
        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            'bundle_slots' => $slots,
            'theme' => 'Kegiatan Sekolah',
            'paragraph_count' => 3,
            'question_count' => 3,
            'submission_mode' => 'draft',
        ])->assertRedirect();

        $generation = AiGeneration::latest('id')->firstOrFail();
        $questions = Question::query()->whereIn('id', $generation->result_payload['question_ids'])->get();

        $this->assertTrue($questions->every(fn (Question $q): bool => $q->status === QuestionStatus::Draft));
        $this->assertTrue($questions->every(fn (Question $q): bool => data_get($q->metadata, 'verification_locked') === true));

        // Pembuat bisa melihat, guru lain mendapat 404
        $this->actingAs($teacher)->get(route('story-questions.show', $generation))
            ->assertInertia(fn (Assert $page) => $page
                ->where('verificationLocked', true)
                ->where('canSubmitForReview', true)
                ->where('canRevertToDraft', false));

        $this->actingAs($otherTeacher)->get(route('story-questions.show', $generation))->assertNotFound();

        // 2. Pembuat mengajukan verifikasi
        $this->actingAs($teacher)
            ->post(route('story-questions.submit-review', $generation))
            ->assertRedirect();

        $questions = $questions->fresh();
        $this->assertTrue($questions->every(fn (Question $q): bool => $q->status === QuestionStatus::Review));
        $this->assertTrue($questions->every(fn (Question $q): bool => data_get($q->metadata, 'verification_locked') === false));

        // Guru lain sekarang bisa melihat dan memverifikasi
        $this->actingAs($otherTeacher)->get(route('story-questions.show', $generation))
            ->assertInertia(fn (Assert $page) => $page
                ->where('verificationLocked', false)
                ->where('canVerify', true));

        // Verifikasi kedua oleh guru lain (pengaju sudah dihitung sebagai verifikator pertama)
        $this->actingAs($otherTeacher)->post(route('story-questions.publish', $generation))->assertRedirect();
        $this->assertSame(2, $questions[0]->verifications()->count());

        // 3. Pembuat mengembalikan ke draft pribadi
        $this->actingAs($teacher)
            ->post(route('story-questions.revert-draft', $generation))
            ->assertRedirect();

        $questions = $questions->fresh();
        $this->assertTrue($questions->every(fn (Question $q): bool => $q->status === QuestionStatus::Draft));
        $this->assertTrue($questions->every(fn (Question $q): bool => data_get($q->metadata, 'verification_locked') === true));
        $this->assertSame(0, $questions[0]->verifications()->count());
    }

    public function test_author_can_toggle_individual_question_status(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$school, , $teacher, $subject, $competency] = $this->context();
        $otherTeacher = $this->user($school, 'Guru Lain 2', 'guru-lain2@example.com', UserRole::Teacher);

        $question = Question::create([
            'school_id' => $school->id,
            'author_id' => $teacher->id,
            'competency_id' => $competency->id,
            'type' => QuestionType::SingleChoice,
            'status' => QuestionStatus::Draft,
            'prompt' => 'Soal uji toggle status',
            'explanation' => 'Pembahasan',
            'difficulty' => 1,
            'grade_level' => 6,
            'metadata' => ['verification_locked' => true],
        ]);

        // Guru lain tidak boleh melihat/mengubah draft pribadi (404)
        $this->actingAs($otherTeacher)
            ->post(route('questions.update-status', $question), ['status' => 'review'])
            ->assertNotFound();

        // Pembuat mengajukan soal ke review
        $this->actingAs($teacher)
            ->post(route('questions.update-status', $question), ['status' => 'review'])
            ->assertRedirect();

        $this->assertSame(QuestionStatus::Review, $question->fresh()->status);
        $this->assertFalse((bool) data_get($question->fresh()->metadata, 'verification_locked'));

        // Guru lain tidak boleh mengubah status soal review milik guru lain (403)
        $this->actingAs($otherTeacher)
            ->post(route('questions.update-status', $question), ['status' => 'draft'])
            ->assertForbidden();

        // Pembuat mengembalikan ke draft
        $this->actingAs($teacher)
            ->post(route('questions.update-status', $question), ['status' => 'draft'])
            ->assertRedirect();

        $this->assertSame(QuestionStatus::Draft, $question->fresh()->status);
        $this->assertTrue((bool) data_get($question->fresh()->metadata, 'verification_locked'));
    }

    public function test_ai_story_question_creation_defaults_to_draft_for_inertia_requests(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [, , $teacher, $subject, $competency, $blueprints] = $this->context();

        $slots = [
            ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
            ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
        ];

        // Inertia request tanpa submission_mode eksplisit -> harus jadi draft
        $this->actingAs($teacher)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('story-questions.store'), [
                'subject_id' => $subject->id,
                'root_competency_id' => $competency->id,
                'competency_id' => $competency->id,
                'bundle_slots' => $slots,
                'theme' => 'Kegiatan Sekolah',
                'paragraph_count' => 3,
                'question_count' => 3,
            ])->assertRedirect();

        $generation = AiGeneration::latest('id')->firstOrFail();
        $this->assertSame('draft', data_get($generation->request_payload, 'submission_mode'));

        $question = Question::query()->whereIn('id', $generation->result_payload['question_ids'])->firstOrFail();
        $this->assertSame(QuestionStatus::Draft, $question->status);
        $this->assertTrue((bool) data_get($question->metadata, 'verification_locked'));
    }

    public function test_author_can_delete_entire_bundle(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$school, , $teacher, $subject, $competency, $blueprints] = $this->context();
        $otherTeacher = $this->user($school, 'Guru Lain', 'guru-lain@example.com', UserRole::Teacher);

        $slots = [
            ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
            ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
        ];

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            'bundle_slots' => $slots,
            'submission_mode' => 'draft',
            'theme' => 'Hapus Bundle Test',
            'paragraph_count' => 3,
            'question_count' => 3,
        ]);

        $generation = AiGeneration::latest('id')->firstOrFail();
        $questionId = $generation->result_payload['question_ids'][0];

        // Guru lain tidak boleh mengakses/menghapus bundle draft pribadi (404)
        $this->actingAs($otherTeacher)
            ->delete(route('story-questions.destroy', $generation))
            ->assertNotFound();

        // Pembuat dapat menghapus seluruh bundle
        $this->actingAs($teacher)
            ->delete(route('story-questions.destroy', $generation))
            ->assertRedirect(route('questions.index'));

        $this->assertDatabaseMissing('ai_generations', ['id' => $generation->id]);
        $this->assertDatabaseMissing('questions', ['id' => $questionId]);
    }

    public function test_bundle_cannot_be_deleted_if_any_question_is_published(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [, , $teacher, $subject, $competency, $blueprints] = $this->context();

        $slots = [
            ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
            ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
        ];

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            'bundle_slots' => $slots,
            'submission_mode' => 'draft',
            'theme' => 'Published Test',
            'paragraph_count' => 3,
            'question_count' => 3,
        ]);

        $generation = AiGeneration::latest('id')->firstOrFail();
        $question = Question::find($generation->result_payload['question_ids'][0]);
        $question->update(['status' => QuestionStatus::Published]);

        $this->actingAs($teacher)
            ->delete(route('story-questions.destroy', $generation))
            ->assertSessionHasErrors('generation');

        $this->assertDatabaseHas('ai_generations', ['id' => $generation->id]);
        $this->assertDatabaseHas('questions', ['id' => $question->id]);
    }

    public function test_author_can_add_question_manually_to_bundle(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$school, , $teacher, $subject, $competency, $blueprints] = $this->context();
        $otherTeacher = $this->user($school, 'Guru Lain 2', 'guru-lain-2@example.com', UserRole::Teacher);

        $slots = [
            ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
            ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
        ];

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            'bundle_slots' => $slots,
            'submission_mode' => 'draft',
            'theme' => 'Tambah Manual Test',
            'paragraph_count' => 3,
            'question_count' => 3,
        ]);

        $generation = AiGeneration::latest('id')->firstOrFail();

        // Guru lain tidak boleh menambah soal ke draft pribadi bundle (404)
        $this->actingAs($otherTeacher)->post(route('story-questions.questions.store', $generation), [
            'competency_id' => $competency->id,
            'type' => 'single_choice',
            'prompt' => 'Soal manual yang dicegah',
            'explanation' => 'Penjelasan manual',
            'difficulty' => 2,
            'options' => [
                ['content' => 'Opsi A', 'is_correct' => true],
                ['content' => 'Opsi B', 'is_correct' => false],
            ],
        ])->assertNotFound();

        // Pembuat berhasil menambah soal manual ke bundle
        $this->actingAs($teacher)->post(route('story-questions.questions.store', $generation), [
            'competency_id' => $competency->id,
            'question_blueprint_id' => $blueprints[1]->id,
            'type' => 'single_choice',
            'prompt' => 'Berdasarkan cerita di atas, apakah objek utama?',
            'explanation' => 'Penjelasan jawaban benar sesuai cerita stimulus.',
            'difficulty' => 2,
            'options' => [
                ['content' => 'Jawaban Benar', 'is_correct' => true],
                ['content' => 'Jawaban Salah', 'is_correct' => false],
            ],
        ])->assertRedirect();

        $freshGen = $generation->fresh();
        $this->assertCount(4, $freshGen->result_payload['question_ids']);
        $this->assertSame(4, $freshGen->result_payload['question_count']);

        $newQuestion = Question::latest('id')->firstOrFail();
        $this->assertSame('Berdasarkan cerita di atas, apakah objek utama?', $newQuestion->prompt);
        $this->assertSame($generation->id, $newQuestion->story_generation_id);
        $this->assertSame($generation->result_payload['story'], $newQuestion->stimulus);
        $this->assertSame(QuestionStatus::Draft, $newQuestion->status);
    }

    public function test_author_can_add_question_via_ai_to_bundle(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [, , $teacher, $subject, $competency, $blueprints] = $this->context();

        $slots = [
            ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
            ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
        ];

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            'bundle_slots' => $slots,
            'submission_mode' => 'draft',
            'theme' => 'Tambah AI Test',
            'paragraph_count' => 3,
            'question_count' => 3,
        ]);

        $generation = AiGeneration::latest('id')->firstOrFail();
        $initialCount = count($generation->result_payload['question_ids']);

        $this->actingAs($teacher)->post(route('story-questions.questions.generate-ai', $generation), [
            'competency_id' => $competency->id,
            'question_blueprint_id' => $blueprints[1]->id,
            'answer_format' => 'single_choice',
            'difficulty' => 3,
            'cognitive_level' => 'Penalaran dan Refleksi (Level 3)',
            'instruction' => 'Fokus pada watak tokoh utama',
        ])->assertRedirect();

        $freshGen = $generation->fresh();
        $this->assertCount($initialCount + 1, $freshGen->result_payload['question_ids']);

        $newQuestion = Question::latest('id')->firstOrFail();
        $this->assertSame($generation->id, $newQuestion->story_generation_id);
        $this->assertSame($generation->result_payload['story'], $newQuestion->stimulus);
        $this->assertSame($competency->id, $newQuestion->competency_id);
    }

    public function test_author_can_regenerate_single_question_with_ai(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$school, , $teacher, $subject, $competency, $blueprints] = $this->context();
        $otherTeacher = $this->user($school, 'Guru Lain 3', 'guru-lain-3@example.com', UserRole::Teacher);

        $slots = [
            ['question_blueprint_id' => $blueprints[0]->id, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
            ['question_blueprint_id' => $blueprints[1]->id, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ['question_blueprint_id' => $blueprints[2]->id, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
        ];

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            'bundle_slots' => $slots,
            'submission_mode' => 'draft',
            'theme' => 'Regenerate Test',
            'paragraph_count' => 3,
            'question_count' => 3,
        ]);

        $generation = AiGeneration::latest('id')->firstOrFail();
        $question = Question::find($generation->result_payload['question_ids'][0]);
        $question->update(['prompt' => 'Prompt Lama Yang Akan Diganti']);

        // Guru lain tidak boleh mengakses/generate ulang draft pribadi (404)
        $this->actingAs($otherTeacher)
            ->post(route('story-questions.questions.regenerate', [$generation, $question]), [
                'instruction' => 'Petunjuk guru lain',
            ])->assertNotFound();

        // Pembuat berhasil generate ulang
        $this->actingAs($teacher)
            ->post(route('story-questions.questions.regenerate', [$generation, $question]), [
                'instruction' => 'Buat sudut pandang berbeda',
            ])->assertRedirect();

        $updatedQuestion = $question->fresh();
        $this->assertNotSame('Prompt Lama Yang Akan Diganti', $updatedQuestion->prompt);
        $this->assertNotEmpty($updatedQuestion->prompt);
        $this->assertTrue($updatedQuestion->options()->exists());

        // Soal yang sudah published tidak boleh di-regenerate (409)
        $updatedQuestion->update(['status' => QuestionStatus::Published]);
        $this->actingAs($teacher)
            ->post(route('story-questions.questions.regenerate', [$generation, $updatedQuestion]))
            ->assertStatus(409);
    }

    public function test_disabled_question_types_are_filtered_and_rejected_in_story_bundle(): void
    {
        config()->set('ai.driver', 'fake');
        [$school, , $teacher, $subject, $competency] = $this->context();

        // Admin menonaktifkan tipe matching dan short_answer di sekolah
        $school->update([
            'settings' => [
                'enabled_question_types' => ['single_choice', 'multiple_choice', 'category_matrix'],
            ],
        ]);

        $generation = AiGeneration::create([
            'school_id' => $school->id,
            'requested_by' => $teacher->id,
            'provider' => 'fake',
            'model' => 'fake-model',
            'input_hash' => hash('sha256', 'bundle-test-disabled-types'),
            'type' => \App\Enums\AiGenerationType::StoryQuestions,
            'status' => \App\Enums\AiGenerationStatus::Completed,
            'request_payload' => [
                'subject_id' => $subject->id,
                'competency_id' => $competency->id,
                'submission_mode' => 'draft',
            ],
            'result_payload' => [
                'title' => 'Cerita Berkesan',
                'story' => 'Ada sebuah desa kecil yang damai di lembah gunung.',
                'question_ids' => [],
            ],
        ]);

        // 1. Pada halaman show, questionTypes yang aktif hanya 3 bentuk soal tersebut
        $this->actingAs($teacher)
            ->get(route('story-questions.show', $generation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/StoryShow')
                ->has('questionTypes', 3)
                ->where('questionTypes.0.value', 'single_choice')
                ->where('questionTypes.1.value', 'multiple_choice')
                ->where('questionTypes.2.value', 'category_matrix'));

        // 2. Tambah soal manual dengan tipe yang dinonaktifkan (matching) ditolak
        $this->actingAs($teacher)
            ->post(route('story-questions.questions.store', $generation), [
                'competency_id' => $competency->id,
                'type' => 'matching',
                'prompt' => 'Pasangkan pernyataan berikut',
                'explanation' => 'Pembahasan',
                'difficulty' => 2,
                'matching_pairs' => [
                    ['left' => 'A', 'right' => 'B'],
                ],
            ])
            ->assertSessionHasErrors('type');

        // 3. Generate soal AI dengan tipe yang dinonaktifkan (matching) ditolak
        $this->actingAs($teacher)
            ->post(route('story-questions.questions.generate-ai', $generation), [
                'competency_id' => $competency->id,
                'answer_format' => 'matching',
                'difficulty' => 2,
            ])
            ->assertSessionHasErrors('answer_format');
    }

    private function context(): array
    {
        $school = School::create(['name' => 'Sekolah Bundle Bahasa', 'npsn' => '10000777']);
        $admin = $this->user($school, 'Admin Bundle', 'admin-bundle@example.com', UserRole::Admin);
        $teacher = $this->user($school, 'Guru Bundle', 'guru-bundle@example.com', UserRole::Teacher);
        $subject = Subject::create([
            'school_id' => $school->id,
            'code' => 'BIND',
            'name' => 'Bahasa Indonesia',
            'ai_question_format' => 'story',
        ]);
        $competency = Competency::create([
            'school_id' => $school->id,
            'subject_id' => $subject->id,
            'code' => 'BIND-INFO-DESKRIPSI',
            'domain' => 'Informasi',
            'name' => 'Informasi – Teks Deskripsi',
            'grade_level' => 6,
        ]);
        $blueprints = collect([
            ['OBJEK-KOSAKATA', 'Menentukan objek berdasarkan kosakata'],
            ['INFO-TERSURAT', 'Menemukan informasi tersurat'],
            ['IDE-POKOK', 'Menentukan ide pokok'],
        ])->map(function (array $data, int $index) use ($school, $subject, $competency): QuestionBlueprint {
            $blueprint = QuestionBlueprint::create([
                'school_id' => $school->id,
                'subject_id' => $subject->id,
                'code' => $data[0],
                'name' => $data[1],
            ]);
            $blueprint->competencies()->attach($competency->id, ['position' => $index + 1]);

            return $blueprint;
        })->values();

        return [$school, $admin, $teacher, $subject, $competency, $blueprints];
    }

    private function user(School $school, string $name, string $email, UserRole $role): User
    {
        return User::create([
            'school_id' => $school->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
