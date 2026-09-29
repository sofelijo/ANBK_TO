<?php

namespace Tests\Feature;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\AiGeneration;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Competency;
use App\Models\Question;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Services\AI\StoryIllustrationService;
use App\Services\StimulusImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuestionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_questions_are_global_across_schools(): void
    {
        [$author, $competency] = $this->teacherAndCompetency();
        $question = $this->question($author, $competency);
        $this->assertNull($question->school_id);
        $otherSchool = School::create(['name' => 'Sekolah Pengakses Global', 'npsn' => '10000999']);
        $otherTeacher = User::create([
            'school_id' => $otherSchool->id,
            'name' => 'Guru Pengakses Global',
            'email' => 'guru-pengakses-global@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'is_active' => true,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);

        $this->actingAs($otherTeacher)
            ->get(route('questions.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('questions.data', fn ($questions): bool => $questions->contains('id', $question->id)));

        $this->actingAs($otherTeacher)->get(route('questions.show', $question))->assertOk();
        $this->actingAs($otherTeacher)->get(route('questions.edit', $question))->assertOk();
    }

    public function test_teacher_can_open_manual_and_ai_question_creation_flows(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();

        $this->actingAs($teacher)
            ->get(route('questions.index'))
            ->assertInertia(fn (Assert $page) => $page->component('Questions/Index'));

        $this->actingAs($teacher)
            ->get(route('questions.create', ['subject_id' => $competency->subject_id]))
            ->assertRedirect(route('manual-story-bundles.create', ['subject_id' => $competency->subject_id]));

        $this->actingAs($teacher)
            ->get(route('manual-story-bundles.create', ['subject_id' => $competency->subject_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/ManualBundleCreate')
                ->where('subject.id', $competency->subject_id));

        $this->actingAs($teacher)
            ->get(route('story-questions.create', ['subject_id' => $competency->subject_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/StoryCreate')
                ->where('selectedSubjectId', $competency->subject_id)
                ->where('creationMode', 'ai'));

        $this->actingAs($teacher)
            ->get(route('json-questions.create', ['subject_id' => $competency->subject_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/StoryCreate')
                ->where('selectedSubjectId', $competency->subject_id)
                ->where('creationMode', 'json'));
    }

    public function test_teacher_can_import_chatgpt_json_as_draft_questions(): void
    {
        [$teacher] = $this->teacherAndCompetency();
        $subject = Subject::create([
            'school_id' => $teacher->school_id,
            'code' => 'MAT',
            'name' => 'Matematika',
            'ai_question_format' => 'direct',
        ]);
        $competency = Competency::create([
            'school_id' => $teacher->school_id,
            'subject_id' => $subject->id,
            'code' => 'MAT6-BIL',
            'domain' => 'Bilangan',
            'name' => 'Operasi bilangan bulat',
            'grade_level' => 6,
        ]);
        $json = json_encode([
            'title' => 'Latihan Bilangan',
            'visual_description' => '',
            'visual_spec' => null,
            'story_paragraphs' => [],
            'questions' => [[
                'competency_code' => $competency->code,
                'type' => 'single_choice',
                'title' => 'Penjumlahan Bilangan',
                'stimulus' => '',
                'prompt' => 'Berapakah hasil 125 + 75?',
                'explanation' => '125 ditambah 75 sama dengan 200.',
                'difficulty' => 1,
                'cognitive_level' => 'penerapan',
                'options' => [
                    ['content' => '175', 'is_correct' => false],
                    ['content' => '200', 'is_correct' => true],
                    ['content' => '225', 'is_correct' => false],
                    ['content' => '250', 'is_correct' => false],
                ],
                'accepted_answers' => [],
                'matching_pairs' => [],
                'matching_distractors' => [],
                'matrix_columns' => [],
                'matrix_rows' => [],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $response = $this->actingAs($teacher)->post(route('json-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            'bundle_slots' => null,
            'theme' => '',
            'answer_format' => 'single_choice',
            'difficulty' => 1,
            'paragraph_count' => 3,
            'max_words' => 200,
            'question_count' => 1,
            'json_payload' => $json,
        ]);

        $response->assertSessionHasNoErrors();
        $generation = AiGeneration::latest('id')->firstOrFail();
        $question = Question::latest('id')->firstOrFail();
        $response->assertRedirect(route('ai-questions.show', $generation));
        $this->assertSame('external-json', $generation->provider);
        $this->assertSame(QuestionStatus::Draft, $question->status);
        $this->assertNull($question->school_id);
        $this->assertTrue((bool) data_get($question->metadata, 'imported_from_external_json'));
        $this->assertCount(4, $question->options);
    }

    public function test_question_is_published_only_after_three_distinct_teacher_verifications(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();

        $response = $this->actingAs($teacher)->post(route('questions.store'), [
            'subject_id' => $competency->subject_id,
            'competency_id' => $competency->id,
            'type' => 'single_choice',
            'title' => 'Soal informasi',
            'stimulus' => 'Perpustakaan buka sampai pukul tiga sore.',
            'prompt' => 'Pukul berapa perpustakaan tutup?',
            'explanation' => 'Informasi tertulis langsung pada stimulus.',
            'difficulty' => 1,
            'grade_level' => 6,
            'cognitive_level' => 'menemukan informasi',
            'options' => [
                ['content' => 'Pukul satu', 'is_correct' => false],
                ['content' => 'Pukul tiga', 'is_correct' => true],
            ],
            'accepted_answers' => [],
        ]);

        $question = Question::firstOrFail();
        $response->assertRedirect(route('questions.show', $question));
        $this->assertNull($question->school_id);
        $this->assertCount(2, $question->options);

        $this->actingAs($teacher)
            ->post(route('questions.approve', $question))
            ->assertRedirect();

        $this->assertSame(QuestionStatus::Review, $question->fresh()->status);
        $this->assertDatabaseCount('question_verifications', 1);

        $this->actingAs($teacher)
            ->post(route('questions.approve', $question))
            ->assertRedirect();

        $this->assertDatabaseCount('question_verifications', 1);

        [$secondTeacher, $thirdTeacher] = $this->additionalVerificationTeachers($teacher);
        $this->actingAs($secondTeacher)->post(route('questions.approve', $question))->assertRedirect();
        $this->assertSame(QuestionStatus::Review, $question->fresh()->status);
        $this->actingAs($thirdTeacher)->post(route('questions.approve', $question))->assertRedirect();

        $this->assertSame(QuestionStatus::Published, $question->fresh()->status);
        $this->assertSame($thirdTeacher->id, $question->fresh()->approved_by);
        $this->assertDatabaseCount('question_verifications', 3);

        $fourthTeacher = $this->verificationTeacher($teacher, 4);
        $this->actingAs($fourthTeacher)->post(route('questions.approve', $question))->assertRedirect();
        $this->assertSame(QuestionStatus::Published, $question->fresh()->status);
        $this->assertSame($thirdTeacher->id, $question->fresh()->approved_by);
        $this->assertDatabaseCount('question_verifications', 4);

        $this->actingAs($fourthTeacher)->post(route('questions.approve', $question))->assertRedirect();
        $this->assertDatabaseCount('question_verifications', 4);
    }

    public function test_question_form_requires_matching_subject_and_competency(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();
        $mathematics = Subject::create([
            'school_id' => $teacher->school_id,
            'code' => 'MAT',
            'name' => 'Matematika',
            'ai_question_format' => 'direct',
        ]);

        $this->actingAs($teacher)
            ->get(route('questions.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/Create')
                ->has('subjects', 2)
                ->where('competencies.0.subject_id', $competency->subject_id));

        $this->actingAs($teacher)
            ->post(route('questions.store'), [
                ...$this->payload($competency, 'Pertanyaan salah klasifikasi?'),
                'subject_id' => $mathematics->id,
            ])
            ->assertSessionHasErrors('competency_id');

        $this->assertDatabaseCount('questions', 0);
    }

    public function test_editing_a_question_resets_existing_teacher_verifications(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();
        $question = $this->question($teacher, $competency);
        $question->update([
            'status' => QuestionStatus::Draft,
            'approved_by' => null,
            'approved_at' => null,
        ]);
        [$secondTeacher] = $this->additionalVerificationTeachers($teacher);

        $this->actingAs($teacher)->post(route('questions.approve', $question))->assertRedirect();
        $this->actingAs($secondTeacher)->post(route('questions.approve', $question))->assertRedirect();
        $this->assertSame(2, $question->verifications()->count());
        $this->assertSame(QuestionStatus::Review, $question->fresh()->status);

        $this->actingAs($teacher)
            ->put(route('questions.update', $question), $this->payload($competency, 'Soal setelah diperbaiki?'))
            ->assertRedirect();

        $this->assertSame(QuestionStatus::Draft, $question->fresh()->status);
        $this->assertSame(0, $question->verifications()->count());
    }

    public function test_teacher_can_upload_stimulus_and_explanation_images_and_see_the_verifier(): void
    {
        Storage::fake('public');
        [$teacher, $competency] = $this->teacherAndCompetency();
        $image = UploadedFile::fake()->image('diagram.png', 1200, 675);
        $explanationImage = UploadedFile::fake()->image('pembahasan.png', 1000, 800);
        file_put_contents($image->getPathname(), random_bytes(300 * 1024), FILE_APPEND);

        $this->assertGreaterThan(StimulusImageService::MAX_BYTES, $image->getSize());

        $this->actingAs($teacher)->post(route('questions.store'), [
            ...$this->payload($competency, 'Apa informasi yang ditunjukkan gambar?'),
            'stimulus_image' => $image,
            'stimulus_image_alt' => 'Diagram jumlah buku yang dibaca siswa',
            'stimulus_upload_zoom' => '1.4',
            'stimulus_upload_offset_x' => '-12.5',
            'stimulus_upload_offset_y' => '8',
            'explanation_image' => $explanationImage,
            'explanation_image_alt' => 'Langkah menghitung jumlah buku',
        ])->assertRedirect();

        $question = Question::firstOrFail();
        $imagePath = data_get($question->metadata, 'illustration.path');
        $explanationImagePath = data_get($question->metadata, 'explanation_illustration.path');

        Storage::disk('public')->assertExists($imagePath);
        $this->assertLessThanOrEqual(StimulusImageService::MAX_BYTES, Storage::disk('public')->size($imagePath));
        $this->assertSame('public', data_get($question->metadata, 'illustration.disk'));
        $this->assertSame('image/jpeg', data_get($question->metadata, 'illustration.mime_type'));
        $this->assertLessThanOrEqual(StimulusImageService::MAX_BYTES, data_get($question->metadata, 'illustration.size_bytes'));
        $this->assertSame('upload', data_get($question->metadata, 'illustration.source'));
        $this->assertSame('Diagram jumlah buku yang dibaca siswa', data_get($question->metadata, 'illustration.alt'));
        $this->assertEquals(1.4, data_get($question->metadata, 'illustration.display_zoom'));
        $this->assertEquals(-12.5, data_get($question->metadata, 'illustration.display_offset_x'));
        $this->assertEquals(8.0, data_get($question->metadata, 'illustration.display_offset_y'));
        Storage::disk('public')->assertExists($explanationImagePath);
        $this->assertStringStartsWith('question-explanations/', $explanationImagePath);
        $this->assertSame('Langkah menghitung jumlah buku', data_get($question->metadata, 'explanation_illustration.alt'));

        $verifiers = $this->verifyQuestionWithThreeTeachers($question, $teacher);

        $this->actingAs($teacher)
            ->get(route('questions.show', $question))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/Show')
                ->where('question.author.name', 'Guru')
                ->where('question.approver.name', $verifiers[2]->name)
                ->where('verification.count', 3)
                ->where('question.illustration_url', "/storage/{$imagePath}")
                ->where('question.explanation_image_url', "/storage/{$explanationImagePath}"));
    }

    public function test_teacher_can_generate_a_custom_svg_from_a_geometry_template(): void
    {
        Storage::fake('public');
        [$teacher, $competency] = $this->teacherAndCompetency();

        $this->actingAs($teacher)->post(route('questions.store'), [
            ...$this->payload($competency, 'Berapakah luas persegi tersebut?'),
            'stimulus_image_source' => 'template',
            'stimulus_svg_template' => 'square',
            'stimulus_svg_dimension_a' => '8',
            'stimulus_svg_unit' => 'cm',
            'stimulus_image_width' => 600,
            'stimulus_image_height' => 360,
            'stimulus_image_alt' => 'Persegi dengan panjang sisi delapan sentimeter',
        ])->assertRedirect();

        $question = Question::firstOrFail();
        $path = data_get($question->metadata, 'illustration.path');
        $this->assertSame('template-svg', data_get($question->metadata, 'illustration.source'));
        $this->assertSame('square', data_get($question->metadata, 'illustration.template'));
        $this->assertEquals(8.0, data_get($question->metadata, 'illustration.dimension_a'));
        $this->assertSame(600, data_get($question->metadata, 'illustration.display_width'));
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString('sisi = 8 cm', Storage::disk('public')->get($path));
    }

    public function test_geometry_templates_are_grouped_and_support_three_dimensions(): void
    {
        Storage::fake('public');
        [$teacher, $competency] = $this->teacherAndCompetency();

        $this->actingAs($teacher)
            ->get(route('questions.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/Create')
                ->has('stimulusSvgTemplates', 88)
                ->where('stimulusSvgTemplates.9.category', '2d')
                ->where('stimulusSvgTemplates.9.family', 'triangle')
                ->where('stimulusSvgTemplates.9.label', 'Siku-siku')
                ->where('stimulusSvgTemplates.29.value', 'cuboid')
                ->where('stimulusSvgTemplates.29.family_label', 'Kubus & balok')
                ->where('stimulusSvgTemplates.29.dimension_c_label', 'Tinggi')
                ->where('stimulusSvgTemplates.30.value', 'triangular_prism')
                ->where('stimulusSvgTemplates.30.family_label', 'Prisma'));

        $this->actingAs($teacher)->post(route('questions.store'), [
            ...$this->payload($competency, 'Berapakah volume balok tersebut?'),
            'stimulus_image_source' => 'template',
            'stimulus_svg_template' => 'cuboid',
            'stimulus_svg_dimension_a' => '12',
            'stimulus_svg_dimension_b' => '8',
            'stimulus_svg_dimension_c' => '5',
            'stimulus_svg_unit' => 'cm',
            'stimulus_svg_zoom' => '0.8',
            'stimulus_svg_offset_x' => '-50',
            'stimulus_svg_offset_y' => '25',
        ])->assertRedirect();

        $question = Question::firstOrFail();
        $path = data_get($question->metadata, 'illustration.path');
        $this->assertSame('cuboid', data_get($question->metadata, 'illustration.template'));
        $this->assertEquals(5.0, data_get($question->metadata, 'illustration.dimension_c'));
        $this->assertEquals(0.8, data_get($question->metadata, 'illustration.zoom'));
        $this->assertEquals(-50.0, data_get($question->metadata, 'illustration.offset_x'));
        $svg = Storage::disk('public')->get($path);
        $this->assertStringContainsString('t = 5 cm', $svg);
        $this->assertStringContainsString('translate(-50 25) scale(0.8)', $svg);
    }

    public function test_fraction_reasoning_template_stores_two_to_four_independent_circles_without_revealing_answers(): void
    {
        Storage::fake('public');
        [$teacher, $competency] = $this->teacherAndCompetency();
        $models = [
            ['numerator' => 1, 'denominator' => 2, 'shaded_parts' => [1]],
            ['numerator' => 2, 'denominator' => 5, 'shaded_parts' => [1, 4]],
            ['numerator' => 3, 'denominator' => 8, 'shaded_parts' => [0, 3, 7]],
            ['numerator' => 4, 'denominator' => 9, 'shaded_parts' => [1, 2, 5, 8]],
        ];

        $this->actingAs($teacher)->post(route('questions.store'), [
            ...$this->payload($competency, 'Bandingkan bagian yang diarsir.'),
            'stimulus_image_source' => 'template',
            'stimulus_svg_template' => 'fraction_equivalent_circles',
            'stimulus_svg_dimension_a' => '1',
            'stimulus_svg_dimension_b' => '2',
            'stimulus_svg_dimension_c' => '4',
            'stimulus_fraction_models' => $models,
        ])->assertRedirect();

        $question = Question::firstOrFail();
        $this->assertSame($models, data_get($question->metadata, 'illustration.fraction_models'));
        $svg = Storage::disk('public')->get(data_get($question->metadata, 'illustration.path'));
        $this->assertStringContainsString('Model 4', $svg);
        $this->assertStringNotContainsString('1/2', $svg);
        $this->assertStringNotContainsString('2/5', $svg);
    }

    public function test_advanced_templates_support_signed_coordinates_and_specific_validation(): void
    {
        Storage::fake('public');
        [$teacher, $competency] = $this->teacherAndCompetency();

        $this->actingAs($teacher)->post(route('questions.store'), [
            ...$this->payload($competency, 'Pada kuadran berapakah titik P berada?'),
            'stimulus_image_source' => 'template',
            'stimulus_svg_template' => 'cartesian_point',
            'stimulus_svg_dimension_a' => '-3',
            'stimulus_svg_dimension_b' => '4',
        ])->assertRedirect();

        $question = Question::firstOrFail();
        $this->assertStringContainsString('Titik P(-3, 4)', Storage::disk('public')->get(data_get($question->metadata, 'illustration.path')));

        $this->actingAs($teacher)->post(route('questions.store'), [
            ...$this->payload($competency, 'Pukul berapakah waktu pada jam?'),
            'stimulus_image_source' => 'template',
            'stimulus_svg_template' => 'clock',
            'stimulus_svg_dimension_a' => '24',
            'stimulus_svg_dimension_b' => '60',
        ])->assertSessionHasErrors('stimulus_svg_dimension_a');
    }

    public function test_teacher_can_create_custom_table_single_and_grouped_bar_pictogram_and_pie_chart_stimuli(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();

        $this->actingAs($teacher)->post(route('questions.store'), [
            ...$this->payload($competency, 'Kelas mana yang memiliki siswa terbanyak?'),
            'title' => 'Data jumlah siswa',
            'stimulus_visual_type' => 'table',
            'stimulus_visual_title' => 'Jumlah Siswa per Kelas',
            'stimulus_table_headers' => ['Kelas', 'Jumlah siswa'],
            'stimulus_table_rows' => [['A', '28'], ['B', '32']],
        ])->assertRedirect();

        $tableQuestion = Question::where('title', 'Data jumlah siswa')->firstOrFail();
        $this->assertSame('table', data_get($tableQuestion->metadata, 'stimulus_visual.type'));
        $this->assertSame(['Kelas', 'Jumlah siswa'], data_get($tableQuestion->metadata, 'stimulus_visual.headers'));
        $this->assertSame([['A', '28'], ['B', '32']], data_get($tableQuestion->metadata, 'stimulus_visual.rows'));

        $this->actingAs($teacher)->post(route('questions.store'), [
            ...$this->payload($competency, 'Berapa selisih penjualan hari Senin dan Selasa?'),
            'title' => 'Diagram penjualan',
            'stimulus_visual_type' => 'bar_chart',
            'stimulus_visual_title' => 'Penjualan Buku Harian',
            'stimulus_chart_x_axis_label' => 'Hari',
            'stimulus_chart_y_axis_label' => 'Jumlah buku',
            'stimulus_chart_maximum' => '50',
            'stimulus_chart_items' => [
                ['label' => 'Senin', 'value' => '25'],
                ['label' => 'Selasa', 'value' => '40'],
            ],
        ])->assertRedirect();

        $chartQuestion = Question::where('title', 'Diagram penjualan')->firstOrFail();
        $this->assertSame('bar_chart', data_get($chartQuestion->metadata, 'stimulus_visual.type'));
        $this->assertSame('Jumlah buku', data_get($chartQuestion->metadata, 'stimulus_visual.y_axis_label'));
        $this->assertEquals(50.0, data_get($chartQuestion->metadata, 'stimulus_visual.maximum'));
        $this->assertEquals(40.0, data_get($chartQuestion->metadata, 'stimulus_visual.items.1.value'));

        $this->actingAs($teacher)->post(route('questions.store'), [
            ...$this->payload($competency, 'Makanan mana yang memiliki kandungan lemak tertinggi?'),
            'title' => 'Diagram kandungan makanan',
            'stimulus_visual_type' => 'bar_chart',
            'stimulus_visual_title' => 'Kandungan dalam 100 Gram Makanan',
            'stimulus_chart_mode' => 'grouped',
            'stimulus_chart_x_axis_label' => 'Nama makanan',
            'stimulus_chart_y_axis_label' => 'Banyak kandungan (gram)',
            'stimulus_chart_series_labels' => ['Lemak', 'Protein'],
            'stimulus_chart_grouped_categories' => [
                ['label' => 'Alpukat', 'values' => ['15', '2']],
                ['label' => 'Daging Sapi', 'values' => ['15', '26']],
                ['label' => 'Keju', 'values' => ['33', '25']],
            ],
        ])->assertRedirect();

        $groupedChartQuestion = Question::where('title', 'Diagram kandungan makanan')->firstOrFail();
        $this->assertSame(['Alpukat', 'Daging Sapi', 'Keju'], data_get($groupedChartQuestion->metadata, 'stimulus_visual.categories'));
        $this->assertSame('Lemak', data_get($groupedChartQuestion->metadata, 'stimulus_visual.series.0.label'));
        $this->assertEquals([15.0, 15.0, 33.0], data_get($groupedChartQuestion->metadata, 'stimulus_visual.series.0.values'));
        $this->assertSame('Protein', data_get($groupedChartQuestion->metadata, 'stimulus_visual.series.1.label'));

        $this->actingAs($teacher)->post(route('questions.store'), [
            ...$this->payload($competency, 'Berapa jumlah buku yang dibaca Beni?'),
            'title' => 'Piktogram buku',
            'stimulus_visual_type' => 'pictogram',
            'stimulus_visual_title' => 'Buku yang Dibaca',
            'stimulus_pictogram_symbol' => '📘',
            'stimulus_pictogram_legend_value' => '2',
            'stimulus_pictogram_unit' => 'buku',
            'stimulus_pictogram_items' => [
                ['label' => 'Ayu', 'value' => '4'],
                ['label' => 'Beni', 'value' => '7'],
            ],
        ])->assertRedirect();

        $pictogramQuestion = Question::where('title', 'Piktogram buku')->firstOrFail();
        $this->assertSame('pictogram', data_get($pictogramQuestion->metadata, 'stimulus_visual.type'));
        $this->assertSame('📘', data_get($pictogramQuestion->metadata, 'stimulus_visual.symbol'));
        $this->assertEquals(2.0, data_get($pictogramQuestion->metadata, 'stimulus_visual.legend_value'));
        $this->assertEquals(7.0, data_get($pictogramQuestion->metadata, 'stimulus_visual.items.1.value'));

        $this->actingAs($teacher)->post(route('questions.store'), [
            ...$this->payload($competency, 'Kategori mana yang memiliki bagian terbesar?'),
            'title' => 'Diagram lingkaran hobi',
            'stimulus_visual_type' => 'pie_chart',
            'stimulus_visual_title' => 'Hobi Siswa',
            'stimulus_pie_unit' => 'siswa',
            'stimulus_pie_show_percentages' => true,
            'stimulus_pie_items' => [
                ['label' => 'Membaca', 'value' => '12'],
                ['label' => 'Olahraga', 'value' => '18'],
                ['label' => 'Musik', 'value' => '10'],
            ],
        ])->assertRedirect();

        $pieQuestion = Question::where('title', 'Diagram lingkaran hobi')->firstOrFail();
        $this->assertSame('pie_chart', data_get($pieQuestion->metadata, 'stimulus_visual.type'));
        $this->assertTrue(data_get($pieQuestion->metadata, 'stimulus_visual.show_percentages'));
        $this->assertSame('Olahraga', data_get($pieQuestion->metadata, 'stimulus_visual.items.1.label'));
        $this->assertEquals(18.0, data_get($pieQuestion->metadata, 'stimulus_visual.items.1.value'));
    }

    public function test_teacher_can_create_a_matching_question_with_distractor(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();

        $response = $this->actingAs($teacher)->post(route('questions.store'), [
            'subject_id' => $competency->subject_id,
            'competency_id' => $competency->id,
            'type' => 'matching',
            'title' => 'Tokoh dalam cerita',
            'stimulus' => 'Kisah Kutu di Negeri Rambut.',
            'prompt' => 'Pasangkan penjelasan dengan tokoh yang tepat.',
            'explanation' => 'Setiap penjelasan memiliki satu pasangan tokoh.',
            'difficulty' => 2,
            'grade_level' => 6,
            'cognitive_level' => 'interpretasi',
            'options' => [],
            'accepted_answers' => [],
            'matching_pairs' => [
                ['left' => 'Memiliki ribuan kutu di rambut.', 'right' => 'Ajeng'],
                ['left' => 'Merasa risih kepada Ajeng.', 'right' => 'Teman-teman'],
                ['left' => 'Berjalan mencari negeri baru.', 'right' => 'Kutu'],
            ],
            'matching_distractors' => [
                ['content' => 'Telur kutu'],
            ],
        ]);

        $question = Question::firstOrFail();
        $response->assertRedirect(route('questions.show', $question));
        $this->assertSame('matching', $question->type->value);
        $this->assertCount(3, $question->metadata['matching_pairs']);
        $this->assertCount(1, $question->metadata['matching_distractors']);
        $this->assertTrue(collect($question->metadata['matching_pairs'])->every(
            fn (array $pair): bool => preg_match('/^[0-9a-f-]{36}$/', $pair['left_id']) === 1
                && preg_match('/^[0-9a-f-]{36}$/', $pair['right_id']) === 1,
        ));
        $this->assertCount(0, $question->options);

        $this->actingAs($teacher)
            ->get(route('questions.edit', $question))
            ->assertOk();

        $this->actingAs($teacher)
            ->post(route('questions.ai-variants.store', $question))
            ->assertSessionHasErrors('ai');
    }

    public function test_teacher_can_create_a_category_matrix_question(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();

        $response = $this->actingAs($teacher)->post(route('questions.store'), [
            'subject_id' => $competency->subject_id,
            'competency_id' => $competency->id,
            'type' => 'category_matrix',
            'title' => 'Kebutuhan gambar pendukung',
            'stimulus' => 'Teks membahas berbagai manfaat rempah.',
            'prompt' => 'Pilih Perlu atau Tidak Perlu untuk setiap pernyataan.',
            'explanation' => 'Setiap pernyataan memiliki tepat satu kategori jawaban.',
            'difficulty' => 2,
            'grade_level' => 6,
            'cognitive_level' => 'interpretasi',
            'options' => [],
            'accepted_answers' => [],
            'matrix_columns' => [
                ['label' => 'Perlu'],
                ['label' => 'Tidak Perlu'],
            ],
            'matrix_rows' => [
                ['statement' => 'Gambar makanan atau minuman dari rempah.', 'correct_column_index' => 0],
                ['statement' => 'Gambar penyakit yang disebabkan rempah.', 'correct_column_index' => 1],
            ],
        ]);

        $question = Question::firstOrFail();
        $response->assertRedirect(route('questions.show', $question));
        $this->assertSame('category_matrix', $question->type->value);
        $this->assertCount(2, $question->metadata['matrix_columns']);
        $this->assertCount(2, $question->metadata['matrix_rows']);
        $this->assertSame(
            $question->metadata['matrix_columns'][0]['id'],
            $question->metadata['matrix_rows'][0]['correct_column_id'],
        );
        $this->assertSame(
            $question->metadata['matrix_columns'][1]['id'],
            $question->metadata['matrix_rows'][1]['correct_column_id'],
        );
        $this->assertCount(0, $question->options);

        $this->actingAs($teacher)
            ->get(route('questions.edit', $question))
            ->assertOk();

        $this->actingAs($teacher)
            ->post(route('questions.ai-variants.store', $question))
            ->assertSessionHasErrors('ai');
    }

    public function test_fake_ai_creates_three_draft_variants(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher, $competency] = $this->teacherAndCompetency();
        $question = $this->question($teacher, $competency);

        $this->actingAs($teacher)
            ->post(route('questions.ai-variants.store', $question))
            ->assertRedirect();

        $this->assertCount(3, $question->variants()->get());
        $this->assertTrue($question->variants()->get()->every(
            fn (Question $variant): bool => $variant->status === QuestionStatus::Draft,
        ));
        $this->assertSame(AiGenerationStatus::Completed, AiGeneration::firstOrFail()->status);
    }

    public function test_fake_ai_creates_the_selected_number_of_story_paragraphs_and_questions(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher, $competency] = $this->teacherAndCompetency();

        $response = $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $competency->subject_id,
            'theme' => 'menjaga kebersihan sungai',
            'paragraph_count' => 4,
            'question_count' => 4,
        ]);

        $generation = AiGeneration::firstOrFail();
        $response->assertRedirect(route('story-questions.show', $generation));
        $this->assertSame(AiGenerationType::StoryQuestions, $generation->type);
        $this->assertSame(AiGenerationStatus::Completed, $generation->status);
        $this->assertSame('menjaga kebersihan sungai', $generation->request_payload['theme']);
        $this->assertSame(4, $generation->request_payload['paragraph_count']);
        $this->assertSame(4, $generation->result_payload['paragraph_count']);

        $questionIds = $generation->result_payload['question_ids'];
        $this->assertCount(4, $questionIds);

        $questions = Question::query()->whereIn('id', $questionIds)->with('options')->get();
        $this->assertCount(count($questionIds), $questions);
        $this->assertCount(1, $questions->pluck('stimulus')->unique());
        $this->assertCount(4, preg_split('/\R\s*\R/u', $questions->first()->stimulus));
        $this->assertTrue($questions->every(
            fn (Question $question): bool => $question->status === QuestionStatus::Draft
                && $question->metadata['story_generation_id'] === $generation->id
                && $question->options->count() >= 2,
        ));

        $this->actingAs($teacher)
            ->get(route('story-questions.show', $generation))
            ->assertOk();
    }

    public function test_bahasa_indonesia_ai_uses_the_main_competency_instead_of_subcompetency(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher, $subcompetency] = $this->teacherAndCompetency();
        $rootCompetency = Competency::create([
            'school_id' => $teacher->school_id,
            'subject_id' => $subcompetency->subject_id,
            'code' => 'LIT6-ROOT',
            'domain' => 'Literasi',
            'name' => 'Memahami teks',
            'grade_level' => 6,
        ]);
        $subcompetency->update(['parent_id' => $rootCompetency->id]);

        $this->actingAs($teacher)
            ->get(route('story-questions.create', ['subject_id' => $subcompetency->subject_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/StoryCreate')
                ->has('competencies', 2));

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $subcompetency->subject_id,
            'root_competency_id' => $rootCompetency->id,
            'competency_id' => $subcompetency->id,
            'theme' => 'membaca informasi jadwal perpustakaan',
            'paragraph_count' => 2,
            'question_count' => 3,
        ])->assertRedirect();

        $generation = AiGeneration::firstOrFail();
        $this->assertSame($rootCompetency->id, $generation->request_payload['root_competency_id']);
        $this->assertSame($rootCompetency->id, $generation->request_payload['competency_id']);
        $this->assertSame($rootCompetency->name, $generation->request_payload['competency_name']);
        $this->assertTrue(Question::query()
            ->whereIn('id', $generation->result_payload['question_ids'])
            ->get()
            ->every(fn (Question $question): bool => $question->competency_id === $rootCompetency->id));
    }

    public function test_mathematics_ai_creates_direct_questions_without_forcing_a_story(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('ai.image.disk', 'public');
        config()->set('queue.default', 'sync');
        Storage::fake('public');
        [$teacher] = $this->teacherAndCompetency();
        $subject = Subject::create([
            'school_id' => $teacher->school_id,
            'code' => 'MAT',
            'name' => 'Matematika',
            'ai_question_format' => 'direct',
        ]);
        $competency = Competency::create([
            'school_id' => $teacher->school_id,
            'subject_id' => $subject->id,
            'code' => 'NUM6-BIL',
            'domain' => 'Bilangan',
            'name' => 'Operasi hitung bilangan',
            'grade_level' => 6,
        ]);

        $this->actingAs($teacher)
            ->get(route('story-questions.create', ['subject_id' => $subject->id]))
            ->assertRedirect(route('ai-questions.create', ['subject_id' => $subject->id]));

        $this->actingAs($teacher)
            ->get(route('ai-questions.create', ['subject_id' => $subject->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/StoryCreate')
                ->where('generationFormat', 'direct')
                ->where('selectedSubjectId', $subject->id));

        $response = $this->actingAs($teacher)->post(route('ai-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            'theme' => 'Jika 3 kotak masing-masing berisi 4 pensil, berapa jumlah seluruh pensil?',
            'question_style' => 'reasoning',
            'difficulty' => 3,
            'use_illustration' => true,
            'question_count' => 1,
        ]);

        $generation = AiGeneration::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('ai-questions.show', $generation));
        $this->assertSame('direct', $generation->request_payload['format']);
        $this->assertSame(0, $generation->request_payload['paragraph_count']);
        $this->assertSame('reasoning', $generation->request_payload['question_style']);
        $this->assertSame(3, $generation->request_payload['difficulty']);
        $this->assertTrue($generation->request_payload['use_illustration']);
        $this->assertSame('lite', $generation->request_payload['illustration_mode']);
        $this->assertSame('Jika 3 kotak masing-masing berisi 4 pensil, berapa jumlah seluruh pensil?', $generation->request_payload['example_question']);
        $this->assertSame('direct', $generation->result_payload['format']);
        $this->assertSame(1, $generation->result_payload['question_count']);
        $this->assertNull($generation->result_payload['story']);
        $this->assertNotEmpty($generation->result_payload['visual_description']);
        $this->assertTrue(Question::query()
            ->whereIn('id', $generation->result_payload['question_ids'])
            ->get()
            ->every(fn (Question $question): bool => $question->competency_id === $competency->id
                && $question->metadata['generation_format'] === 'direct'));

        $this->actingAs($teacher)
            ->post(route('ai-questions.illustration.store', $generation))
            ->assertRedirect();

        $illustration = AiGeneration::query()
            ->where('type', AiGenerationType::StoryIllustration)
            ->firstOrFail();
        $this->assertSame(AiGenerationStatus::Completed, $illustration->status);
        $this->assertSame('direct', $illustration->request_payload['format']);
        $this->assertSame('lite', $illustration->request_payload['illustration_mode']);
        $this->assertTrue(Question::query()
            ->whereIn('id', $generation->result_payload['question_ids'])
            ->get()
            ->every(fn (Question $question): bool => filled(data_get($question->metadata, 'illustration.path'))));

        $this->actingAs($teacher)
            ->get(route('ai-questions.show', $generation))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('generation.model')
                ->missing('generation.input_tokens')
                ->missing('generation.output_tokens')
                ->missing('generation.cost_microusd')
                ->missing('illustration.provider')
                ->missing('illustration.model')
                ->missing('illustration.cost_microusd'));

        $generatedQuestion = Question::query()->findOrFail($generation->result_payload['question_ids'][0]);
        $this->assertSame(3, $generatedQuestion->difficulty);
        $this->actingAs($teacher)
            ->from(route('ai-questions.show', $generation))
            ->put(route('generated-questions.inline-update', [$generation, $generatedQuestion]), [
                'stimulus' => 'Tiga kelompok masing-masing berisi empat benda.',
                'prompt' => 'Berapa hasil perhitungan yang sudah diperbaiki?',
                'explanation' => 'Jumlah benda adalah tiga kali empat, yaitu dua belas.',
                'difficulty' => 1,
                'options' => [
                    ['content' => '12', 'is_correct' => true],
                    ['content' => '7', 'is_correct' => false],
                ],
                'accepted_answers' => [],
                'matching_pairs' => [],
                'matching_distractors' => [],
                'matrix_columns' => [],
                'matrix_rows' => [],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('ai-questions.show', $generation));

        $generatedQuestion->refresh();
        $this->assertSame('Berapa hasil perhitungan yang sudah diperbaiki?', $generatedQuestion->prompt);
        $this->assertSame('Jumlah benda adalah tiga kali empat, yaitu dua belas.', $generatedQuestion->explanation);
        $this->assertSame(1, $generatedQuestion->difficulty);
        $this->assertSame('12', $generatedQuestion->options()->where('is_correct', true)->firstOrFail()->content);

        $this->actingAs($teacher)->post(route('ai-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            // The React form initializes these hidden B. Indonesia bundle slots.
            // Direct-subject requests must ignore them instead of failing invisibly.
            'bundle_slots' => [
                ['question_blueprint_id' => 0, 'answer_format' => 'single_choice', 'cognitive_level' => 'textual'],
                ['question_blueprint_id' => 0, 'answer_format' => 'true_false', 'cognitive_level' => 'inferential'],
                ['question_blueprint_id' => 0, 'answer_format' => 'multiple_choice', 'cognitive_level' => 'evaluation'],
            ],
            'question_style' => 'direct',
            'use_illustration' => false,
            'question_count' => 9,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $withoutExample = AiGeneration::query()
            ->where('type', AiGenerationType::StoryQuestions)
            ->latest('id')
            ->firstOrFail();
        $this->assertNull($withoutExample->request_payload['example_question']);
        $this->assertSame($competency->name, $withoutExample->request_payload['theme']);
        $this->assertSame(9, $withoutExample->result_payload['question_count']);
    }

    public function test_teacher_can_choose_ai_answer_format_for_direct_questions(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher] = $this->teacherAndCompetency();
        $subject = Subject::create([
            'school_id' => $teacher->school_id,
            'code' => 'MAT-FORMAT',
            'name' => 'Matematika Format',
            'ai_question_format' => 'direct',
        ]);
        $competency = Competency::create([
            'school_id' => $teacher->school_id,
            'subject_id' => $subject->id,
            'code' => 'NUM6-FORMAT',
            'domain' => 'Bilangan',
            'name' => 'Operasi bilangan',
            'grade_level' => 6,
        ]);

        $formats = [
            'single_choice' => ['single_choice'],
            'true_false' => ['category_matrix'],
            'multiple_choice' => ['multiple_choice'],
            'mixed' => ['single_choice', 'multiple_choice', 'category_matrix'],
        ];

        foreach ($formats as $answerFormat => $expectedTypes) {
            $questionCount = $answerFormat === 'mixed' ? 3 : 1;
            $this->actingAs($teacher)->post(route('ai-questions.store'), [
                'subject_id' => $subject->id,
                'root_competency_id' => $competency->id,
                'competency_id' => $competency->id,
                'question_style' => 'direct',
                'answer_format' => $answerFormat,
                'use_illustration' => false,
                'question_count' => $questionCount,
            ])->assertSessionHasNoErrors()->assertRedirect();

            $generation = AiGeneration::query()->latest('id')->firstOrFail();
            $actualTypes = Question::query()
                ->whereIn('id', $generation->result_payload['question_ids'])
                ->orderBy('id')
                ->get(['id', 'type'])
                ->pluck('type')
                ->map(fn ($type): string => $type->value)
                ->all();

            $this->assertSame($answerFormat, $generation->request_payload['answer_format']);
            $this->assertSame($expectedTypes, $actualTypes);
        }

        $this->actingAs($teacher)->post(route('ai-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            'answer_format' => 'mixed',
            'question_count' => 1,
        ])->assertSessionHasErrors('answer_format');
    }

    public function test_real_ai_retries_when_generated_question_is_too_similar(): void
    {
        config()->set('ai.driver', 'gemini');
        config()->set('ai.gemini.api_key', 'test-key');
        config()->set('ai.groq.api_key', null);
        config()->set('queue.default', 'sync');
        [$teacher] = $this->teacherAndCompetency();
        $subject = Subject::create([
            'school_id' => $teacher->school_id,
            'code' => 'MAT-UNIK',
            'name' => 'Matematika',
            'ai_question_format' => 'direct',
        ]);
        $competency = Competency::create([
            'school_id' => $teacher->school_id,
            'subject_id' => $subject->id,
            'code' => 'LUAS-UNIK',
            'domain' => 'Pengukuran',
            'name' => 'Keliling dan luas bangun datar',
            'grade_level' => 6,
        ]);
        Question::create([
            'school_id' => $teacher->school_id,
            'author_id' => $teacher->id,
            'competency_id' => $competency->id,
            'type' => QuestionType::SingleChoice,
            'status' => QuestionStatus::Draft,
            'prompt' => 'Sebuah persegi panjang memiliki panjang 12 cm dan lebar 8 cm. Berapakah luas bangun tersebut?',
            'difficulty' => 1,
            'grade_level' => 6,
        ]);

        $response = fn (string $prompt): array => [
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'title' => 'Paket unik',
                    'visual_description' => '',
                    'visual_spec' => null,
                    'story_paragraphs' => [],
                    'questions' => [[
                        'competency_code' => $competency->code,
                        'type' => 'single_choice',
                        'title' => 'Soal luas',
                        'stimulus' => '',
                        'prompt' => $prompt,
                        'explanation' => 'Pembahasan benar.',
                        'difficulty' => 1,
                        'cognitive_level' => 'penerapan',
                        'options' => [
                            ['content' => 'A', 'is_correct' => true],
                            ['content' => 'B', 'is_correct' => false],
                            ['content' => 'C', 'is_correct' => false],
                            ['content' => 'D', 'is_correct' => false],
                        ],
                        'accepted_answers' => [],
                        'matching_pairs' => [],
                        'matching_distractors' => [],
                        'matrix_columns' => [],
                        'matrix_rows' => [],
                    ]],
                ], JSON_UNESCAPED_UNICODE)]]],
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 20],
        ];
        Http::fakeSequence()
            ->push($response('Sebuah persegi panjang mempunyai panjang 12 cm dan lebar 8 cm. Berapakah luas bangun tersebut?'))
            ->push($response('Sebuah segitiga memiliki alas 16 cm dan tinggi 9 cm. Berapakah luas segitiga tersebut?'));

        $this->actingAs($teacher)->post(route('ai-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            'question_style' => 'direct',
            'answer_format' => 'single_choice',
            'use_illustration' => false,
            'question_count' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $generation = AiGeneration::query()->latest('id')->firstOrFail();
        $generated = Question::query()->findOrFail($generation->result_payload['question_ids'][0]);
        $this->assertStringContainsString('segitiga', mb_strtolower($generated->prompt));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($request['contents'][0]['parts'][0]['text'], 'DILARANG dibuat ulang'));
    }

    public function test_direct_ai_questions_are_listed_and_verified_individually(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher] = $this->teacherAndCompetency();
        $subject = Subject::create([
            'school_id' => $teacher->school_id,
            'code' => 'MAT-MANDIRI',
            'name' => 'Matematika Mandiri',
            'ai_question_format' => 'direct',
        ]);
        $competency = Competency::create([
            'school_id' => $teacher->school_id,
            'subject_id' => $subject->id,
            'code' => 'NUM6-MANDIRI',
            'domain' => 'Bilangan',
            'name' => 'Operasi hitung',
            'grade_level' => 6,
        ]);

        $this->actingAs($teacher)->post(route('ai-questions.store'), [
            'subject_id' => $subject->id,
            'root_competency_id' => $competency->id,
            'competency_id' => $competency->id,
            'answer_format' => 'single_choice',
            'question_count' => 3,
        ])->assertSessionHasNoErrors();

        $generation = AiGeneration::query()->latest('id')->firstOrFail();
        $questionIds = $generation->result_payload['question_ids'];

        $this->actingAs($teacher)
            ->get(route('questions.index', ['subject_id' => $subject->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('questions.data', 3)
                ->where('questions.data', fn ($questions): bool => collect($questions)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect($questionIds)->sort()->values()->all()));

        $firstQuestion = Question::query()->findOrFail($questionIds[0]);
        $this->verifyQuestionWithThreeTeachers($firstQuestion, $teacher);

        $this->assertSame(QuestionStatus::Published, $firstQuestion->fresh()->status);
        $this->assertSame(2, Question::query()->whereIn('id', $questionIds)->where('status', QuestionStatus::Draft)->count());

        $deletedQuestion = Question::query()->findOrFail($questionIds[1]);
        $this->actingAs($teacher)
            ->from(route('ai-questions.show', $generation))
            ->delete(route('generated-questions.destroy', [$generation, $deletedQuestion]))
            ->assertRedirect(route('ai-questions.show', $generation));
        $this->assertDatabaseMissing('questions', ['id' => $deletedQuestion->id]);
        $this->assertNotContains($deletedQuestion->id, $generation->fresh()->result_payload['question_ids']);
        $this->assertSame(2, $generation->fresh()->result_payload['question_count']);

        $this->actingAs($teacher)
            ->post(route('ai-questions.publish', $generation))
            ->assertSessionHasErrors('generation');
    }

    public function test_duplicate_check_blocks_verification_of_a_highly_similar_question(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();
        $source = $this->question($teacher, $competency);
        $duplicate = $this->question($teacher, $competency);
        $duplicate->update([
            'status' => QuestionStatus::Draft,
            'title' => 'Soal duplikat',
            'prompt' => $source->prompt,
            'stimulus' => $source->stimulus,
        ]);

        $this->actingAs($teacher)
            ->postJson(route('questions.duplicate-check', $duplicate))
            ->assertOk()
            ->assertJsonPath('blocking', true)
            ->assertJsonPath('candidates.0.id', $source->id)
            ->assertJsonPath('candidates.0.similarity', 100);

        $this->actingAs($teacher)
            ->post(route('questions.approve', $duplicate))
            ->assertSessionHasErrors('duplicate');

        $this->assertSame(QuestionStatus::Draft, $duplicate->fresh()->status);

        // Draft pribadi yang belum diajukan tidak boleh memblokir soal aktif lain.
        $source->update([
            'status' => QuestionStatus::Draft,
            'metadata' => ['verification_locked' => true],
        ]);
        $duplicate->update([
            'status' => QuestionStatus::Review,
            'metadata' => ['verification_locked' => false],
        ]);

        $this->actingAs($teacher)
            ->post(route('questions.approve', $duplicate))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $duplicate->verifications()->count());
    }

    public function test_story_question_request_allows_ai_to_choose_theme_when_left_empty(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();
        Queue::fake();

        $this->actingAs($teacher)
            ->post(route('story-questions.store'), [
                'subject_id' => $competency->subject_id,
                'theme' => '',
                'paragraph_count' => 3,
                'question_count' => 3,
            ])
            ->assertRedirect();

        $generation = AiGeneration::firstOrFail();
        $this->assertSame('', $generation->request_payload['theme']);
        $this->assertSame('ai', $generation->request_payload['theme_source']);
    }

    public function test_teacher_can_publish_all_questions_in_a_story_bundle_at_once(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher, $competency] = $this->teacherAndCompetency();

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $competency->subject_id,
            'theme' => 'hemat energi di sekolah',
            'paragraph_count' => 2,
            'question_count' => 3,
        ]);

        $generation = AiGeneration::firstOrFail();
        $questionIds = $generation->result_payload['question_ids'];

        $this->assertTrue(Question::query()->whereIn('id', $questionIds)->get()->every(
            fn (Question $question): bool => $question->status === QuestionStatus::Draft,
        ));

        $additionalTeachers = $this->additionalVerificationTeachers($teacher);
        foreach ([$teacher, ...$additionalTeachers] as $verifier) {
            $this->actingAs($verifier)
                ->post(route('story-questions.publish', $generation))
                ->assertRedirect()
                ->assertSessionHas('success');
        }

        $publishedQuestions = Question::query()->whereIn('id', $questionIds)->get();
        $this->assertTrue($publishedQuestions->every(
            fn (Question $question): bool => $question->status === QuestionStatus::Published
                && $question->approved_by === $additionalTeachers[1]->id
                && $question->approved_at !== null,
        ));
        $this->actingAs($teacher)
            ->get(route('story-questions.show', $generation))
            ->assertInertia(fn (Assert $page) => $page
                ->where('questions.0.author.name', 'Guru')
                ->where('questions.0.approver.name', $additionalTeachers[1]->name)
                ->where('questions.0.verification.count', 3));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'story_bundle.published',
            'auditable_type' => (new AiGeneration)->getMorphClass(),
            'auditable_id' => $generation->id,
        ]);
        $this->assertSame(3, AuditLog::query()->where('action', 'story_bundle.published')->firstOrFail()->metadata['question_count']);

        $fourthTeacher = $this->verificationTeacher($teacher, 4);
        $this->actingAs($fourthTeacher)
            ->post(route('story-questions.publish', $generation))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertTrue(Question::query()->whereIn('id', $questionIds)->get()->every(
            fn (Question $question): bool => $question->verifications()->count() === 4,
        ));

        $this->actingAs($teacher)
            ->post(route('story-questions.publish', $generation))
            ->assertRedirect()
            ->assertSessionHas('success', 'Anda sudah memverifikasi seluruh soal dalam bundle ini.');
    }

    public function test_story_questions_appear_as_one_searchable_bundle_in_question_bank(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher, $competency] = $this->teacherAndCompetency();
        $standaloneQuestion = $this->question($teacher, $competency);

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $competency->subject_id,
            'theme' => 'kegiatan koperasi sekolah',
            'paragraph_count' => 2,
            'question_count' => 3,
        ]);

        $generation = AiGeneration::query()
            ->where('type', AiGenerationType::StoryQuestions)
            ->firstOrFail();
        $storyQuestions = Question::query()
            ->where('story_generation_id', $generation->id)
            ->get();

        $this->assertCount(3, $storyQuestions);
        $this->actingAs($teacher)
            ->get(route('questions.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/Index')
                ->has('questions.data', 2)
                ->where('questions.data', fn ($questions): bool => collect($questions)
                    ->where('story_generation_id', $generation->id)
                    ->pipe(fn ($matching) => collect($matching->first()['bundle_questions'])->pluck('id')->all()) === $storyQuestions->pluck('id')->all())
                ->where('questions.data', fn ($questions): bool => collect($questions)
                    ->where('story_generation_id', $generation->id)
                    ->count() === 1));

        $storyQuestions->last()->update([
            'prompt' => 'Pertanyaan dengan kata unik delima jingga.',
            'status' => QuestionStatus::Published,
        ]);

        $this->actingAs($teacher)
            ->get(route('questions.index', ['search' => 'delima jingga']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('questions.data', 1)
                ->where('questions.data.0.story_generation_id', $generation->id));

        $this->actingAs($teacher)
            ->get(route('questions.index', ['search' => (string) $storyQuestions->last()->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('questions.data', 1)
                ->where('questions.data.0.story_generation_id', $generation->id));

        $this->actingAs($teacher)
            ->get(route('questions.index', ['search' => (string) $standaloneQuestion->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('questions.data', 1)
                ->where('questions.data.0.id', $standaloneQuestion->id));

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $competency->subject_id,
            'theme' => 'kegiatan berbeda untuk menguji hitungan verifikasi',
            'paragraph_count' => 2,
            'question_count' => 3,
        ]);
        $otherGeneration = AiGeneration::query()->where('id', '!=', $generation->id)->latest('id')->firstOrFail();
        Question::query()
            ->where('story_generation_id', $otherGeneration->id)
            ->each(fn (Question $question) => $question->verifications()->create([
                'verifier_id' => $teacher->id,
                'verified_at' => now(),
            ]));

        $expectedBundleVerifications = $storyQuestions->sum(
            fn (Question $question): int => $question->verifications()->count(),
        );

        $this->actingAs($teacher)
            ->get(route('questions.index', ['search' => 'delima jingga']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('questions.data', 1)
                ->where('questions.data.0.bundle_verifications_count', $expectedBundleVerifications));

        $this->actingAs($teacher)
            ->get(route('questions.index', ['status' => QuestionStatus::Published->value]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('questions.data', 2)
                ->where('questions.data', fn ($questions): bool => collect($questions)
                    ->where('story_generation_id', $generation->id)
                    ->count() === 1));
    }

    public function test_fake_ai_creates_one_shared_illustration_for_story_questions(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('ai.image.disk', 'public');
        config()->set('queue.default', 'sync');
        Storage::fake('public');
        [$teacher, $competency] = $this->teacherAndCompetency();

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'subject_id' => $competency->subject_id,
            'theme' => 'liburan keluarga di Bali',
            'paragraph_count' => 2,
            'question_count' => 3,
        ]);

        $storyGeneration = AiGeneration::query()
            ->where('type', AiGenerationType::StoryQuestions)
            ->firstOrFail();

        $this->actingAs($teacher)
            ->post(route('story-questions.illustration.store', $storyGeneration))
            ->assertRedirect()
            ->assertSessionHas('success');

        $illustration = AiGeneration::query()
            ->where('type', AiGenerationType::StoryIllustration)
            ->firstOrFail();
        $this->assertSame(AiGenerationStatus::Completed, $illustration->status);
        $this->assertSame(0, $illustration->cost_microusd);

        $path = $illustration->result_payload['image_path'];
        Storage::disk('public')->assertExists($path);

        $questions = Question::query()
            ->whereIn('id', $storyGeneration->result_payload['question_ids'])
            ->get();
        $this->assertCount(3, $questions);
        $this->assertTrue($questions->every(
            fn (Question $question): bool => data_get($question->metadata, 'illustration.path') === $path
                && $question->illustration_url !== null,
        ));

        $this->actingAs($teacher)
            ->get(route('story-questions.show', $storyGeneration))
            ->assertInertia(fn (Assert $page) => $page
                ->has('questions', 3)
                ->where('questions', fn ($questions): bool => collect($questions)->every(
                    fn (array $question): bool => filled($question['illustration_url'] ?? null),
                )));
    }

    public function test_gemini_batch_response_is_saved_as_a_shared_illustration(): void
    {
        config()->set('ai.driver', 'gemini');
        config()->set('ai.cloudflare.account_id', null);
        config()->set('ai.cloudflare.api_token', null);
        config()->set('ai.gemini.api_key', 'test-key');
        config()->set('ai.image.disk', 'public');
        config()->set('ai.image.model', 'gemini-3.1-flash-lite-image');
        config()->set('ai.image.batch_cost_microusd', 16800);
        Storage::fake('public');
        [$teacher, $competency] = $this->teacherAndCompetency();
        $question = $this->question($teacher, $competency);
        $generation = AiGeneration::create([
            'school_id' => $teacher->school_id,
            'requested_by' => $teacher->id,
            'source_question_id' => $question->id,
            'type' => AiGenerationType::StoryIllustration,
            'status' => AiGenerationStatus::Pending,
            'provider' => 'gemini',
            'model' => 'gemini-3.1-flash-lite-image',
            'input_hash' => hash('sha256', 'image-batch-test'),
            'request_payload' => [
                'question_ids' => [$question->id],
                'theme' => 'pasar tradisional',
                'prompt' => 'Buat ilustrasi pasar tradisional tanpa tulisan.',
            ],
        ]);

        Http::fakeSequence()
            ->push([
                'name' => 'batches/image-test',
                'metadata' => ['state' => 'JOB_STATE_PENDING'],
            ])
            ->push([
                'done' => true,
                'metadata' => ['state' => 'JOB_STATE_SUCCEEDED'],
                'response' => [
                    'inlinedResponses' => [[
                        'response' => [
                            'candidates' => [[
                                'content' => ['parts' => [[
                                    'inlineData' => [
                                        'mimeType' => 'image/png',
                                        'data' => base64_encode('fake-png-content'),
                                    ],
                                ]]],
                            ]],
                            'usageMetadata' => [
                                'promptTokenCount' => 20,
                                'candidatesTokenCount' => 1120,
                            ],
                        ],
                    ]],
                ],
            ]);

        $service = app(StoryIllustrationService::class);
        $service->submit($generation);
        $this->assertSame(AiGenerationStatus::Processing, $generation->fresh()->status);

        $service->refresh($generation->fresh());
        $generation->refresh();
        $question->refresh();

        $this->assertSame(AiGenerationStatus::Completed, $generation->status);
        $this->assertSame(16800, $generation->cost_microusd);
        $this->assertSame(20, $generation->input_tokens);
        Storage::disk('public')->assertExists($generation->result_payload['image_path']);
        $this->assertSame($generation->result_payload['image_path'], data_get($question->metadata, 'illustration.path'));
        Http::assertSentCount(2);
    }

    public function test_cloudflare_free_image_is_used_before_gemini(): void
    {
        config()->set('ai.driver', 'gemini');
        config()->set('ai.cloudflare.account_id', 'cloudflare-account');
        config()->set('ai.cloudflare.api_token', 'cloudflare-token');
        config()->set('ai.cloudflare.image_model', '@cf/black-forest-labs/flux-1-schnell');
        config()->set('ai.image.disk', 'public');
        Storage::fake('public');
        [$teacher, $competency] = $this->teacherAndCompetency();
        $question = $this->question($teacher, $competency);
        $image = UploadedFile::fake()->image('cloudflare.jpg', 1024, 576);
        $generation = AiGeneration::create([
            'school_id' => $teacher->school_id,
            'requested_by' => $teacher->id,
            'source_question_id' => $question->id,
            'type' => AiGenerationType::StoryIllustration,
            'status' => AiGenerationStatus::Pending,
            'provider' => 'image-router',
            'model' => '@cf/black-forest-labs/flux-1-schnell',
            'input_hash' => hash('sha256', 'cloudflare-image-test'),
            'request_payload' => [
                'question_ids' => [$question->id],
                'theme' => 'pecahan buah',
                'prompt' => 'Buat ilustrasi matematika dengan kelompok buah.',
            ],
        ]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['image' => base64_encode(file_get_contents($image->getPathname()))],
            ]),
        ]);

        app(StoryIllustrationService::class)->submit($generation);
        $generation->refresh();
        $question->refresh();

        $this->assertSame(AiGenerationStatus::Completed, $generation->status);
        $this->assertSame('cloudflare', $generation->provider);
        $this->assertSame(0, $generation->cost_microusd);
        $this->assertFalse($generation->result_payload['fallback_used']);
        Storage::disk('public')->assertExists($generation->result_payload['image_path']);
        $this->assertSame($generation->result_payload['image_path'], data_get($question->metadata, 'illustration.path'));
        Http::assertSentCount(1);
    }

    public function test_precise_math_diagram_is_rendered_locally_before_cloudflare(): void
    {
        config()->set('ai.driver', 'gemini');
        config()->set('ai.cloudflare.account_id', 'cloudflare-account');
        config()->set('ai.cloudflare.api_token', 'cloudflare-token');
        config()->set('ai.image.disk', 'public');
        Storage::fake('public');
        Http::fake();
        [$teacher, $competency] = $this->teacherAndCompetency();
        $question = $this->question($teacher, $competency);
        $generation = AiGeneration::create([
            'school_id' => $teacher->school_id,
            'requested_by' => $teacher->id,
            'source_question_id' => $question->id,
            'type' => AiGenerationType::StoryIllustration,
            'status' => AiGenerationStatus::Pending,
            'provider' => 'image-router',
            'model' => '@cf/black-forest-labs/flux-1-schnell',
            'input_hash' => hash('sha256', 'precise-math-diagram-test'),
            'request_payload' => [
                'question_ids' => [$question->id],
                'theme' => 'pecahan senilai',
                'visual_spec' => [
                    'type' => 'fraction_models',
                    'items' => [
                        ['shape' => 'circle', 'total_parts' => 4, 'shaded_parts' => 2],
                        ['shape' => 'circle', 'total_parts' => 8, 'shaded_parts' => 4],
                    ],
                ],
            ],
        ]);

        app(StoryIllustrationService::class)->submit($generation);
        $generation->refresh();
        $question->refresh();

        $this->assertSame(AiGenerationStatus::Completed, $generation->status);
        $this->assertSame('local-svg', $generation->provider);
        $this->assertSame('deterministic-math-svg-v1', $generation->model);
        $this->assertSame('image/svg+xml', $generation->result_payload['mime_type']);
        $this->assertSame(0, $generation->cost_microusd);
        Storage::disk('public')->assertExists($generation->result_payload['image_path']);
        $svg = Storage::disk('public')->get($generation->result_payload['image_path']);
        $this->assertSame(12, substr_count($svg, '<path'));
        $this->assertSame($generation->result_payload['image_path'], data_get($question->metadata, 'illustration.path'));
        Http::assertNothingSent();
    }

    public function test_direct_generation_with_recoverable_visual_spec_can_create_local_svg(): void
    {
        config()->set('ai.driver', 'gemini');
        config()->set('ai.image.disk', 'public');
        Storage::fake('public');
        Http::fake();
        [$teacher, $competency] = $this->teacherAndCompetency();
        $question = $this->question($teacher, $competency);
        $generation = AiGeneration::create([
            'school_id' => $teacher->school_id,
            'requested_by' => $teacher->id,
            'type' => AiGenerationType::StoryQuestions,
            'status' => AiGenerationStatus::Completed,
            'provider' => 'gemini',
            'model' => 'gemini-test',
            'input_hash' => hash('sha256', 'recoverable-direct-visual-test'),
            'request_payload' => [
                'format' => 'direct',
                'theme' => 'Penjualan buku harian',
                'use_illustration' => false,
                'illustration_mode' => null,
            ],
            'result_payload' => [
                'question_ids' => [$question->id],
                'visual_description' => 'Grafik batang penjualan buku selama tiga hari.',
                'visual_spec' => [
                    'type' => 'data_chart',
                    'style' => 'bar',
                    'title' => 'Penjualan Buku',
                    'items' => [
                        ['label' => 'Senin', 'value' => 25],
                        ['label' => 'Selasa', 'value' => 40],
                        ['label' => 'Rabu', 'value' => 30],
                    ],
                ],
            ],
        ]);

        $this->actingAs($teacher)
            ->post(route('ai-questions.illustration.store', $generation))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $illustration = AiGeneration::query()
            ->where('type', AiGenerationType::StoryIllustration)
            ->firstOrFail();
        $question->refresh();

        $this->assertSame(AiGenerationStatus::Completed, $illustration->status);
        $this->assertSame('lite', $illustration->request_payload['illustration_mode']);
        $this->assertSame('local-svg', $illustration->provider);
        $this->assertSame('image/svg+xml', $illustration->result_payload['mime_type']);
        $this->assertNotNull(data_get($question->metadata, 'illustration.path'));
        Http::assertNothingSent();
    }

    public function test_lite_clock_spec_is_rendered_as_local_svg_without_printing_digital_time(): void
    {
        config()->set('ai.driver', 'gemini');
        config()->set('ai.image.disk', 'public');
        Storage::fake('public');
        Http::fake();
        [$teacher, $competency] = $this->teacherAndCompetency();
        $question = $this->question($teacher, $competency);
        $generation = AiGeneration::create([
            'school_id' => $teacher->school_id,
            'requested_by' => $teacher->id,
            'source_question_id' => $question->id,
            'type' => AiGenerationType::StoryIllustration,
            'status' => AiGenerationStatus::Pending,
            'provider' => 'image-router',
            'model' => 'lite',
            'input_hash' => hash('sha256', 'lite-clock-svg-test'),
            'request_payload' => [
                'question_ids' => [$question->id],
                'theme' => 'membaca waktu',
                'illustration_mode' => 'lite',
                'visual_spec' => ['type' => 'clock', 'hour' => 8, 'minute' => 25],
            ],
        ]);

        app(StoryIllustrationService::class)->submit($generation);
        $generation->refresh();
        $svg = Storage::disk('public')->get($generation->result_payload['image_path']);

        $this->assertSame(AiGenerationStatus::Completed, $generation->status);
        $this->assertSame('local-svg', $generation->provider);
        $this->assertSame('deterministic-math-svg-v2', $generation->model);
        $this->assertStringContainsString('Jam Analog', $svg);
        $this->assertStringNotContainsString('08:25', $svg);
        Http::assertNothingSent();
    }

    public function test_geometry_diagram_uses_specific_visual_description_without_revealing_answer(): void
    {
        config()->set('ai.driver', 'gemini');
        config()->set('ai.image.disk', 'public');
        Storage::fake('public');
        Http::fake();
        [$teacher, $competency] = $this->teacherAndCompetency();
        $question = $this->question($teacher, $competency);
        $storyGeneration = AiGeneration::create([
            'school_id' => $teacher->school_id,
            'requested_by' => $teacher->id,
            'type' => AiGenerationType::StoryQuestions,
            'status' => AiGenerationStatus::Completed,
            'provider' => 'gemini',
            'model' => 'test-model',
            'input_hash' => hash('sha256', 'geometry-source-test'),
            'request_payload' => [],
            'result_payload' => [
                'visual_description' => 'Sebuah persegi panjang dengan panjang 12 cm dan lebar 8 cm.',
                'question_ids' => [$question->id],
            ],
        ]);
        $generation = AiGeneration::create([
            'school_id' => $teacher->school_id,
            'requested_by' => $teacher->id,
            'source_question_id' => $question->id,
            'type' => AiGenerationType::StoryIllustration,
            'status' => AiGenerationStatus::Pending,
            'provider' => 'image-router',
            'model' => 'test-model',
            'input_hash' => hash('sha256', 'geometry-image-test'),
            'request_payload' => [
                'story_generation_id' => $storyGeneration->id,
                'question_ids' => [$question->id],
                'theme' => 'Keliling dan luas bangun datar (segitiga, segiempat, dan segi banyak)',
                'subject' => 'Matematika',
                'competency' => 'Keliling dan luas bangun datar',
            ],
        ]);

        app(StoryIllustrationService::class)->submit($generation);
        $generation->refresh();
        $svg = Storage::disk('public')->get($generation->result_payload['image_path']);

        $this->assertSame('local-svg', $generation->provider);
        $this->assertSame('deterministic-geometry-svg-v3', $generation->model);
        $this->assertStringContainsString('Persegi Panjang', $svg);
        $this->assertStringContainsString('Panjang = 12 cm', $svg);
        $this->assertStringContainsString('Lebar = 8 cm', $svg);
        $this->assertStringNotContainsString('Segitiga', $svg);
        $this->assertStringNotContainsString('Perhitungan', $svg);
        $this->assertStringNotContainsString('96 cm', $svg);
        Http::assertNothingSent();
    }

    public function test_gemini_is_used_when_cloudflare_free_request_fails(): void
    {
        config()->set('ai.driver', 'gemini');
        config()->set('ai.cloudflare.account_id', 'cloudflare-account');
        config()->set('ai.cloudflare.api_token', 'cloudflare-token');
        config()->set('ai.gemini.api_key', 'gemini-key');
        config()->set('ai.image.model', 'gemini-3.1-flash-lite-image');
        [$teacher, $competency] = $this->teacherAndCompetency();
        $question = $this->question($teacher, $competency);
        $generation = AiGeneration::create([
            'school_id' => $teacher->school_id,
            'requested_by' => $teacher->id,
            'source_question_id' => $question->id,
            'type' => AiGenerationType::StoryIllustration,
            'status' => AiGenerationStatus::Pending,
            'provider' => 'image-router',
            'model' => '@cf/black-forest-labs/flux-1-schnell',
            'input_hash' => hash('sha256', 'cloudflare-fallback-test'),
            'request_payload' => [
                'question_ids' => [$question->id],
                'theme' => 'pecahan buah',
                'prompt' => 'Buat ilustrasi matematika dengan kelompok buah.',
            ],
        ]);

        Http::fakeSequence()
            ->push(['success' => false, 'errors' => [['message' => 'Free allocation exhausted']]], 429)
            ->push([
                'name' => 'batches/gemini-fallback',
                'metadata' => ['state' => 'JOB_STATE_PENDING'],
            ]);

        app(StoryIllustrationService::class)->submit($generation);
        $generation->refresh();

        $this->assertSame(AiGenerationStatus::Processing, $generation->status);
        $this->assertSame('gemini', $generation->provider);
        $this->assertTrue($generation->result_payload['fallback_used']);
        $this->assertSame('batches/gemini-fallback', $generation->result_payload['batch_name']);
        Http::assertSentCount(2);
    }

    public function test_story_question_request_rejects_unsupported_counts(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();

        $this->actingAs($teacher)
            ->post(route('story-questions.store'), [
                'subject_id' => $competency->subject_id,
                'theme' => 'kegiatan sekolah',
                'paragraph_count' => 3,
                'question_count' => 5,
            ])
            ->assertSessionHasErrors('question_count');

        $this->actingAs($teacher)
            ->post(route('story-questions.store'), [
                'subject_id' => $competency->subject_id,
                'theme' => 'kegiatan sekolah',
                'paragraph_count' => 6,
                'question_count' => 3,
            ])
            ->assertSessionHasErrors('paragraph_count');

        $this->actingAs($teacher)
            ->post(route('story-questions.store'), [
                'subject_id' => $competency->subject_id,
                'theme' => 'kegiatan sekolah',
                'paragraph_count' => 2,
                'question_count' => 1,
            ])
            ->assertSessionHasErrors('question_count');
    }

    public function test_teacher_can_retry_a_failed_story_question_request(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher] = $this->teacherAndCompetency();
        $generation = AiGeneration::create([
            'school_id' => $teacher->school_id,
            'requested_by' => $teacher->id,
            'type' => AiGenerationType::StoryQuestions,
            'status' => AiGenerationStatus::Failed,
            'provider' => 'fake',
            'model' => 'deterministic-local',
            'input_hash' => hash('sha256', 'retry-story-test'),
            'request_payload' => ['theme' => 'kegiatan pasar tradisional'],
            'error' => 'Antrean terhenti.',
        ]);

        $this->actingAs($teacher)
            ->post(route('story-questions.retry', $generation))
            ->assertRedirect();

        $generation->refresh();
        $this->assertSame(AiGenerationStatus::Completed, $generation->status);
        $this->assertCount(3, $generation->result_payload['question_ids']);
        $this->assertNull($generation->error);
    }

    public function test_fake_ai_reviews_question_quality(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher, $competency] = $this->teacherAndCompetency();
        $question = $this->question($teacher, $competency);

        $this->actingAs($teacher)
            ->post(route('questions.ai-review.store', $question))
            ->assertRedirect();

        $this->assertDatabaseHas('question_reviews', [
            'question_id' => $question->id,
            'source' => 'ai',
            'status' => 'passed',
        ]);
    }

    public function test_editing_a_published_question_creates_an_immutable_revision(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();
        $question = $this->question($teacher, $competency);
        $question->update(['metadata' => [
            'illustration' => ['disk' => 'public', 'path' => 'question-illustrations/test.png'],
        ]]);

        $response = $this->actingAs($teacher)
            ->put(route('questions.update', $question), $this->payload($competency, 'Pertanyaan yang sudah diperbarui?'))
            ->assertRedirect();

        $question->refresh();
        $revision = Question::query()->where('revision_of_id', $question->id)->firstOrFail();
        $response->assertRedirect(route('questions.show', $revision));
        $this->assertSame('Manakah jawaban yang benar?', $question->prompt);
        $this->assertSame(QuestionStatus::Published, $question->status);
        $this->assertSame('Pertanyaan yang sudah diperbarui?', $revision->prompt);
        $this->assertSame(QuestionStatus::Draft, $revision->status);
        $this->assertSame(2, $revision->version);
        $this->assertSame('question-illustrations/test.png', data_get($revision->metadata, 'illustration.path'));

        $this->verifyQuestionWithThreeTeachers($revision, $teacher);
        $this->assertSame(QuestionStatus::Archived, $question->fresh()->status);
        $this->assertSame($revision->id, $question->fresh()->superseded_by_id);
        $this->assertSame(QuestionStatus::Published, $revision->fresh()->status);

        $this->actingAs($teacher)
            ->post(route('questions.duplicate', $revision))
            ->assertRedirect();
        $duplicate = Question::query()->where('parent_id', $revision->id)->firstOrFail();
        $this->assertCount(2, $duplicate->options);
        $this->assertSame(1, $duplicate->version);
        $this->assertNull($duplicate->revision_of_id);

        $this->actingAs($teacher)
            ->post(route('questions.archive', $revision))
            ->assertRedirect(route('questions.index'));
        $this->assertSame(QuestionStatus::Archived, $revision->fresh()->status);
    }

    public function test_teacher_can_import_question_from_csv(): void
    {
        [$teacher] = $this->teacherAndCompetency();
        $csv = implode("\n", [
            'competency_code,type,title,stimulus,prompt,explanation,difficulty,grade_level,cognitive_level,option_a,option_b,option_c,option_d,option_e,option_f,correct_answers,accepted_answers',
            'LIT6-INFO,single_choice,Soal impor,Stimulus impor,Pertanyaan dari impor?,Pembahasan,1,6,informasi,Jawaban A,Jawaban B,,,,,B,',
        ]);

        $this->actingAs($teacher)
            ->post(route('questions.import.store'), [
                'file' => UploadedFile::fake()->createWithContent('questions.csv', $csv),
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('questions', [
            'title' => 'Soal impor',
            'status' => QuestionStatus::Draft->value,
        ]);
    }

    public function test_question_detail_shows_current_and_available_assessment_packages(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();
        $question = $this->question($teacher, $competency);
        $currentPackage = Assessment::create([
            'school_id' => $teacher->school_id,
            'subject_id' => $competency->subject_id,
            'created_by' => $teacher->id,
            'title' => 'Paket Saat Ini',
            'grade_level' => 6,
            'duration_minutes' => 60,
            'status' => 'draft',
        ]);
        $availablePackage = Assessment::create([
            'school_id' => $teacher->school_id,
            'subject_id' => $competency->subject_id,
            'created_by' => $teacher->id,
            'title' => 'Paket Tujuan',
            'grade_level' => 6,
            'duration_minutes' => 60,
            'status' => 'draft',
        ]);
        $currentPackage->questions()->attach($question->id, ['position' => 2, 'points' => 1]);

        $this->actingAs($teacher)
            ->get(route('questions.show', $question))
            ->assertInertia(fn (Assert $page) => $page
                ->where('packageUsage.0.id', $currentPackage->id)
                ->where('packageUsage.0.title', 'Paket Saat Ini')
                ->where('packageUsage.0.position', 2)
                ->where('availablePackages.0.id', $availablePackage->id)
                ->where('availablePackages.0.title', 'Paket Tujuan'));
    }

    public function test_student_cannot_manage_question_bank(): void
    {
        [$teacher] = $this->teacherAndCompetency();
        $student = User::create([
            'school_id' => $teacher->school_id,
            'name' => 'Murid',
            'email' => 'murid-test@example.com',
            'password' => 'password',
            'role' => UserRole::Student,
            'grade_level' => 6,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($student)->get(route('questions.index'))->assertForbidden();
    }

    private function teacherAndCompetency(): array
    {
        $school = School::create(['name' => 'Sekolah Uji', 'npsn' => '10000003']);
        $teacher = User::create([
            'school_id' => $school->id,
            'name' => 'Guru',
            'email' => 'guru-test@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'email_verified_at' => now(),
        ]);
        $competency = Competency::create([
            'school_id' => $school->id,
            'subject_id' => Subject::create([
                'school_id' => $school->id,
                'code' => 'BIND',
                'name' => 'Bahasa Indonesia',
                'ai_question_format' => 'story',
            ])->id,
            'code' => 'LIT6-INFO',
            'domain' => 'Literasi',
            'name' => 'Menemukan informasi',
            'grade_level' => 6,
        ]);

        return [$teacher, $competency];
    }

    private function question(User $teacher, Competency $competency): Question
    {
        $question = Question::create([
            'school_id' => $teacher->school_id,
            'author_id' => $teacher->id,
            'competency_id' => $competency->id,
            'type' => 'single_choice',
            'status' => QuestionStatus::Published,
            'title' => 'Soal sumber',
            'stimulus' => 'Sebuah stimulus singkat.',
            'prompt' => 'Manakah jawaban yang benar?',
            'difficulty' => 1,
            'grade_level' => 6,
        ]);
        $question->options()->createMany([
            ['label' => 'A', 'content' => 'Benar', 'is_correct' => true, 'position' => 1],
            ['label' => 'B', 'content' => 'Salah', 'is_correct' => false, 'position' => 2],
        ]);

        return $question;
    }

    /** @return array{User, User} */
    private function additionalVerificationTeachers(User $teacher): array
    {
        return collect([2, 3])->map(fn (int $number): User => $this->verificationTeacher($teacher, $number))->all();
    }

    private function verificationTeacher(User $teacher, int $number): User
    {
        return User::create([
            'school_id' => $teacher->school_id,
            'name' => "Guru Verifikator {$number}",
            'email' => "guru-verifikator-{$number}@example.com",
            'password' => 'password',
            'role' => UserRole::Teacher,
            'is_active' => true,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
    }

    /** @return array{User, User, User} */
    private function verifyQuestionWithThreeTeachers(Question $question, User $teacher): array
    {
        $verifiers = [$teacher, ...$this->additionalVerificationTeachers($teacher)];

        foreach ($verifiers as $verifier) {
            $this->actingAs($verifier)
                ->post(route('questions.approve', $question))
                ->assertRedirect();
        }

        return $verifiers;
    }

    private function payload(Competency $competency, string $prompt): array
    {
        return [
            'subject_id' => $competency->subject_id,
            'competency_id' => $competency->id,
            'type' => 'single_choice',
            'title' => 'Soal informasi',
            'stimulus' => 'Stimulus yang diperbarui.',
            'prompt' => $prompt,
            'explanation' => 'Pembahasan diperbarui.',
            'difficulty' => 2,
            'grade_level' => 6,
            'cognitive_level' => 'menemukan informasi',
            'options' => [
                ['content' => 'Jawaban benar', 'is_correct' => true],
                ['content' => 'Jawaban salah', 'is_correct' => false],
            ],
            'accepted_answers' => [],
        ];
    }
}
