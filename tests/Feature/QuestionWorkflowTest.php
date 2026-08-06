<?php

namespace Tests\Feature;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Models\AiGeneration;
use App\Models\AuditLog;
use App\Models\Competency;
use App\Models\Question;
use App\Models\School;
use App\Models\User;
use App\Services\AI\StoryIllustrationService;
use App\Services\StimulusImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuestionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_create_and_publish_a_question(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();

        $response = $this->actingAs($teacher)->post(route('questions.store'), [
            'competency_id' => $competency->id,
            'type' => 'single_choice',
            'title' => 'Soal informasi',
            'stimulus' => 'Perpustakaan buka sampai pukul tiga sore.',
            'prompt' => 'Pukul berapa perpustakaan tutup?',
            'explanation' => 'Informasi tertulis langsung pada stimulus.',
            'difficulty' => 1,
            'grade_level' => 5,
            'cognitive_level' => 'menemukan informasi',
            'options' => [
                ['content' => 'Pukul satu', 'is_correct' => false],
                ['content' => 'Pukul tiga', 'is_correct' => true],
            ],
            'accepted_answers' => [],
        ]);

        $question = Question::firstOrFail();
        $response->assertRedirect(route('questions.show', $question));
        $this->assertCount(2, $question->options);

        $this->actingAs($teacher)
            ->post(route('questions.approve', $question))
            ->assertRedirect();

        $this->assertSame(QuestionStatus::Published, $question->fresh()->status);
    }

    public function test_teacher_can_upload_a_stimulus_image_and_see_the_verifier(): void
    {
        Storage::fake('public');
        [$teacher, $competency] = $this->teacherAndCompetency();
        $image = UploadedFile::fake()->image('diagram.png', 1200, 675);
        file_put_contents($image->getPathname(), random_bytes(300 * 1024), FILE_APPEND);

        $this->assertGreaterThan(StimulusImageService::MAX_BYTES, $image->getSize());

        $this->actingAs($teacher)->post(route('questions.store'), [
            ...$this->payload($competency, 'Apa informasi yang ditunjukkan gambar?'),
            'stimulus_image' => $image,
            'stimulus_image_alt' => 'Diagram jumlah buku yang dibaca siswa',
        ])->assertRedirect();

        $question = Question::firstOrFail();
        $imagePath = data_get($question->metadata, 'illustration.path');

        Storage::disk('public')->assertExists($imagePath);
        $this->assertLessThanOrEqual(StimulusImageService::MAX_BYTES, Storage::disk('public')->size($imagePath));
        $this->assertSame('public', data_get($question->metadata, 'illustration.disk'));
        $this->assertSame('image/jpeg', data_get($question->metadata, 'illustration.mime_type'));
        $this->assertLessThanOrEqual(StimulusImageService::MAX_BYTES, data_get($question->metadata, 'illustration.size_bytes'));
        $this->assertSame('upload', data_get($question->metadata, 'illustration.source'));
        $this->assertSame('Diagram jumlah buku yang dibaca siswa', data_get($question->metadata, 'illustration.alt'));

        $this->actingAs($teacher)
            ->post(route('questions.approve', $question))
            ->assertRedirect();

        $this->actingAs($teacher)
            ->get(route('questions.show', $question))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/Show')
                ->where('question.author.name', 'Guru')
                ->where('question.approver.name', 'Guru')
                ->where('question.illustration_url', fn ($url): bool => is_string($url) && str_contains($url, $imagePath)));
    }

    public function test_teacher_can_create_a_matching_question_with_distractor(): void
    {
        [$teacher, $competency] = $this->teacherAndCompetency();

        $response = $this->actingAs($teacher)->post(route('questions.store'), [
            'competency_id' => $competency->id,
            'type' => 'matching',
            'title' => 'Tokoh dalam cerita',
            'stimulus' => 'Kisah Kutu di Negeri Rambut.',
            'prompt' => 'Pasangkan penjelasan dengan tokoh yang tepat.',
            'explanation' => 'Setiap penjelasan memiliki satu pasangan tokoh.',
            'difficulty' => 2,
            'grade_level' => 5,
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
            'competency_id' => $competency->id,
            'type' => 'category_matrix',
            'title' => 'Kebutuhan gambar pendukung',
            'stimulus' => 'Teks membahas berbagai manfaat rempah.',
            'prompt' => 'Pilih Perlu atau Tidak Perlu untuk setiap pernyataan.',
            'explanation' => 'Setiap pernyataan memiliki tepat satu kategori jawaban.',
            'difficulty' => 2,
            'grade_level' => 5,
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
        [$teacher] = $this->teacherAndCompetency();

        $response = $this->actingAs($teacher)->post(route('story-questions.store'), [
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

    public function test_story_question_request_requires_a_theme(): void
    {
        [$teacher] = $this->teacherAndCompetency();

        $this->actingAs($teacher)
            ->post(route('story-questions.store'), ['theme' => ''])
            ->assertSessionHasErrors('theme');
    }

    public function test_teacher_can_publish_all_questions_in_a_story_bundle_at_once(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher] = $this->teacherAndCompetency();

        $this->actingAs($teacher)->post(route('story-questions.store'), [
            'theme' => 'hemat energi di sekolah',
            'paragraph_count' => 2,
            'question_count' => 3,
        ]);

        $generation = AiGeneration::firstOrFail();
        $questionIds = $generation->result_payload['question_ids'];

        $this->assertTrue(Question::query()->whereIn('id', $questionIds)->get()->every(
            fn (Question $question): bool => $question->status === QuestionStatus::Draft,
        ));

        $this->actingAs($teacher)
            ->post(route('story-questions.publish', $generation))
            ->assertRedirect()
            ->assertSessionHas('success');

        $publishedQuestions = Question::query()->whereIn('id', $questionIds)->get();
        $this->assertTrue($publishedQuestions->every(
            fn (Question $question): bool => $question->status === QuestionStatus::Published
                && $question->approved_by === $teacher->id
                && $question->approved_at !== null,
        ));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'story_bundle.published',
            'auditable_type' => (new AiGeneration)->getMorphClass(),
            'auditable_id' => $generation->id,
        ]);
        $this->assertSame(3, AuditLog::query()->where('action', 'story_bundle.published')->firstOrFail()->metadata['question_count']);

        $this->actingAs($teacher)
            ->post(route('story-questions.publish', $generation))
            ->assertRedirect()
            ->assertSessionHas('success', 'Seluruh soal dalam bundle sudah terbit.');
    }

    public function test_story_questions_appear_as_one_searchable_bundle_in_question_bank(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher, $competency] = $this->teacherAndCompetency();
        $this->question($teacher, $competency);

        $this->actingAs($teacher)->post(route('story-questions.store'), [
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
        [$teacher] = $this->teacherAndCompetency();

        $this->actingAs($teacher)->post(route('story-questions.store'), [
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
    }

    public function test_gemini_batch_response_is_saved_as_a_shared_illustration(): void
    {
        config()->set('ai.driver', 'gemini');
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

    public function test_story_question_request_rejects_unsupported_counts(): void
    {
        [$teacher] = $this->teacherAndCompetency();

        $this->actingAs($teacher)
            ->post(route('story-questions.store'), [
                'theme' => 'kegiatan sekolah',
                'paragraph_count' => 6,
                'question_count' => 5,
            ])
            ->assertSessionHasErrors(['paragraph_count', 'question_count']);
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

        $this->actingAs($teacher)
            ->post(route('questions.approve', $revision))
            ->assertRedirect();
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
            'LIT5-INFO,single_choice,Soal impor,Stimulus impor,Pertanyaan dari impor?,Pembahasan,1,5,informasi,Jawaban A,Jawaban B,,,,,B,',
        ]);

        $this->actingAs($teacher)
            ->post(route('questions.import.store'), [
                'file' => UploadedFile::fake()->createWithContent('questions.csv', $csv),
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('questions', [
            'school_id' => $teacher->school_id,
            'title' => 'Soal impor',
            'status' => QuestionStatus::Draft->value,
        ]);
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
            'grade_level' => 5,
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
            'code' => 'LIT5-INFO',
            'domain' => 'Literasi',
            'name' => 'Menemukan informasi',
            'grade_level' => 5,
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
            'grade_level' => 5,
        ]);
        $question->options()->createMany([
            ['label' => 'A', 'content' => 'Benar', 'is_correct' => true, 'position' => 1],
            ['label' => 'B', 'content' => 'Salah', 'is_correct' => false, 'position' => 2],
        ]);

        return $question;
    }

    private function payload(Competency $competency, string $prompt): array
    {
        return [
            'competency_id' => $competency->id,
            'type' => 'single_choice',
            'title' => 'Soal informasi',
            'stimulus' => 'Stimulus yang diperbarui.',
            'prompt' => $prompt,
            'explanation' => 'Pembahasan diperbarui.',
            'difficulty' => 2,
            'grade_level' => 5,
            'cognitive_level' => 'menemukan informasi',
            'options' => [
                ['content' => 'Jawaban benar', 'is_correct' => true],
                ['content' => 'Jawaban salah', 'is_correct' => false],
            ],
            'accepted_answers' => [],
        ];
    }
}
