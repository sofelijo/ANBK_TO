<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AiGeneration;
use App\Models\Competency;
use App\Models\Question;
use App\Models\QuestionBlueprint;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\BahasaIndonesiaQuestionTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionBlueprintWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_bahasa_indonesia_catalog_is_seeded_idempotently_with_reusable_types(): void
    {
        $school = School::create(['name' => 'Sekolah Katalog Bahasa', 'npsn' => '10000888']);
        $subject = Subject::create([
            'school_id' => $school->id,
            'code' => 'BIND',
            'name' => 'Bahasa Indonesia',
            'ai_question_format' => 'story',
        ]);
        Competency::create([
            'school_id' => $school->id,
            'subject_id' => $subject->id,
            'code' => 'LIT6-INFO',
            'domain' => 'Literasi',
            'name' => 'Informasi terkait deskripsi',
            'grade_level' => 6,
        ]);
        Competency::create([
            'school_id' => $school->id,
            'subject_id' => $subject->id,
            'code' => 'LIT6-INFER',
            'domain' => 'Literasi',
            'name' => 'Membuat inferensi',
            'grade_level' => 6,
        ]);

        $this->seed(BahasaIndonesiaQuestionTypeSeeder::class);
        $this->seed(BahasaIndonesiaQuestionTypeSeeder::class);

        $this->assertSame(10, Competency::query()->where('subject_id', $subject->id)->count());
        $this->assertSame(19, QuestionBlueprint::query()->where('subject_id', $subject->id)->count());
        $this->assertSame(
            6,
            QuestionBlueprint::query()
                ->where('subject_id', $subject->id)
                ->where('code', 'INFO-TERSURAT')
                ->firstOrFail()
                ->competencies()
                ->count(),
        );
        $this->assertSame(
            4,
            QuestionBlueprint::query()
                ->where('subject_id', $subject->id)
                ->where('code', 'MAKNA-UNGKAPAN')
                ->firstOrFail()
                ->competencies()
                ->count(),
        );
        $this->assertDatabaseMissing('competencies', ['subject_id' => $subject->id, 'code' => 'LIT6-INFO']);
        $this->assertDatabaseMissing('competencies', ['subject_id' => $subject->id, 'code' => 'LIT6-INFER']);
    }

    public function test_teacher_can_reuse_a_question_type_as_default_for_multiple_competencies(): void
    {
        [$teacher, $subject, $description, $exposition] = $this->context();

        $this->actingAs($teacher)->post(route('question-types.store'), [
            'subject_id' => $subject->id,
            'code' => 'IDE-POKOK',
            'name' => 'Ide pokok',
            'description' => 'Menentukan gagasan utama teks.',
            'competency_ids' => [$description->id, $exposition->id],
        ])->assertRedirect(route('question-types.index'));

        $blueprint = QuestionBlueprint::firstOrFail();
        $this->assertEqualsCanonicalizing(
            [$description->id, $exposition->id],
            $blueprint->competencies()->pluck('competencies.id')->all(),
        );

        $this->actingAs($teacher)
            ->get(route('question-types.edit', $blueprint))
            ->assertOk();
    }

    public function test_teacher_can_customize_global_question_type_for_their_school(): void
    {
        [$teacher, $subject, $description] = $this->context();

        $globalBlueprint = QuestionBlueprint::create([
            'school_id' => null,
            'subject_id' => $subject->id,
            'code' => 'KOSAKATA-GLOBAL',
            'name' => 'Menentukan arti kata',
            'description' => 'Definisi standar nasional.',
        ]);

        $this->actingAs($teacher)
            ->get(route('question-types.edit', $globalBlueprint))
            ->assertOk();

        $this->actingAs($teacher)
            ->put(route('question-types.update', $globalBlueprint), [
                'subject_id' => $subject->id,
                'code' => 'KOSAKATA-GLOBAL',
                'name' => 'Menentukan makna kata kontekstual (Kustom)',
                'description' => 'Disesuaikan dengan modul ajar sekolah.',
                'competency_ids' => [$description->id],
            ])
            ->assertRedirect(route('question-types.index'))
            ->assertSessionHas('success', 'Tipe soal berhasil disesuaikan untuk sekolah Anda.');

        $schoolBlueprint = QuestionBlueprint::query()
            ->where('school_id', $teacher->school_id)
            ->where('code', 'KOSAKATA-GLOBAL')
            ->firstOrFail();

        $this->assertSame('Menentukan makna kata kontekstual (Kustom)', $schoolBlueprint->name);
        $this->assertSame('Disesuaikan dengan modul ajar sekolah.', $schoolBlueprint->description);
        $this->assertTrue($schoolBlueprint->competencies->contains($description));

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $teacher->id,
            'action' => 'question_blueprint.customized',
        ]);
    }

    public function test_competency_defaults_can_be_adjusted_and_manual_question_stores_selected_type(): void
    {
        [$teacher, $subject, $description] = $this->context();
        $blueprint = QuestionBlueprint::create([
            'school_id' => $teacher->school_id,
            'subject_id' => $subject->id,
            'code' => 'INFO-TERSURAT',
            'name' => 'Informasi tersurat',
        ]);

        $this->actingAs($teacher)->put(route('competencies.update', $description), [
            'subject_id' => $subject->id,
            'code' => $description->code,
            'domain' => $description->domain,
            'name' => $description->name,
            'description' => null,
            'grade_level' => 6,
            'parent_id' => null,
            'question_blueprint_ids' => [$blueprint->id],
        ])->assertRedirect(route('competencies.index'));
        $this->assertTrue($description->fresh()->questionBlueprints->contains($blueprint));

        $this->actingAs($teacher)->post(route('questions.store'), [
            'subject_id' => $subject->id,
            'competency_id' => $description->id,
            'question_blueprint_id' => $blueprint->id,
            'type' => 'single_choice',
            'prompt' => 'Informasi apa yang tertulis langsung dalam teks?',
            'difficulty' => 1,
            'grade_level' => 6,
            'options' => [
                ['content' => 'Jawaban benar', 'is_correct' => true],
                ['content' => 'Pengecoh', 'is_correct' => false],
            ],
        ])->assertRedirect();

        $this->assertSame($blueprint->id, Question::firstOrFail()->question_blueprint_id);
    }

    public function test_ai_uses_selected_question_types_for_generated_questions(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher, $subject, $description] = $this->context();
        $blueprints = collect(['INFO-TERSURAT' => 'Informasi tersurat', 'IDE-POKOK' => 'Ide pokok'])
            ->map(fn (string $name, string $code) => QuestionBlueprint::create([
                'school_id' => $teacher->school_id,
                'subject_id' => $subject->id,
                'code' => $code,
                'name' => $name,
            ]));

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $description->id,
            'competency_id' => $description->id,
            'question_blueprint_ids' => $blueprints->pluck('id')->all(),
            'theme' => 'kegiatan perpustakaan sekolah',
            'paragraph_count' => 2,
            'question_count' => 3,
        ])->assertRedirect();

        $generation = AiGeneration::firstOrFail();
        $questions = Question::query()->whereIn('id', $generation->result_payload['question_ids'])->orderBy('id')->get();
        $this->assertSame(
            [$blueprints->first()->id, $blueprints->last()->id, $blueprints->first()->id],
            $questions->pluck('question_blueprint_id')->all(),
        );
    }

    private function context(): array
    {
        $school = School::create(['name' => 'Sekolah Tipe Soal', 'npsn' => '10000999']);
        $teacher = User::create([
            'school_id' => $school->id,
            'name' => 'Guru Bahasa',
            'email' => 'guru-tipe-soal@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'email_verified_at' => now(),
        ]);
        $subject = Subject::create([
            'school_id' => $school->id,
            'code' => 'BIND',
            'name' => 'Bahasa Indonesia',
            'ai_question_format' => 'story',
        ]);
        $description = Competency::create([
            'school_id' => $school->id,
            'subject_id' => $subject->id,
            'code' => 'LIT6-DESK',
            'domain' => 'Literasi',
            'name' => 'Informasi Deskripsi',
            'grade_level' => 6,
        ]);
        $exposition = Competency::create([
            'school_id' => $school->id,
            'subject_id' => $subject->id,
            'code' => 'LIT6-EKSP',
            'domain' => 'Literasi',
            'name' => 'Informasi Eksposisi',
            'grade_level' => 6,
        ]);

        return [$teacher, $subject, $description, $exposition];
    }
}
