<?php

namespace Tests\Feature;

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
        [, , $teacher, $subject, $competency, $blueprints] = $this->context();
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
                    ['content' => 'Kegiatan berlangsung malam hari.', 'is_correct' => false],
                ],
                'statements' => [],
            ],
            [
                'prompt' => 'Tentukan benar atau salah berdasarkan bacaan.',
                'explanation' => 'Kunci mengacu langsung pada bacaan.',
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
            'bundle_slots' => $slots,
            'questions' => $questions,
        ])->assertRedirect();

        $generation = AiGeneration::sole();
        $createdQuestions = Question::query()->where('story_generation_id', $generation->id)->orderBy('id')->with('options')->get();

        $this->assertSame('manual', $generation->provider);
        $this->assertSame('manual', $generation->request_payload['source']);
        $this->assertSame(200, $generation->request_payload['max_words']);
        $this->assertSame(3, $createdQuestions->count());
        $this->assertSame(
            [QuestionType::MultipleChoice, QuestionType::CategoryMatrix, QuestionType::SingleChoice],
            $createdQuestions->pluck('type')->all(),
        );
        $this->assertSame([3, 2, 1], $createdQuestions->pluck('difficulty')->all());
        $this->assertSame([$blueprints[1]->id, $blueprints[2]->id, $blueprints[0]->id], $createdQuestions->pluck('question_blueprint_id')->all());
        $this->assertSame('Taman Bersih', $generation->result_payload['title']);
        $this->assertCount(3, $createdQuestions[1]->metadata['matrix_rows']);
        $this->assertCount(4, $createdQuestions[0]->options);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $teacher->id,
            'action' => 'manual_story_bundle.created',
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
