<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\AssessmentStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\AiGeneration;
use App\Models\Assessment;
use App\Models\Competency;
use App\Models\Question;
use App\Models\QuestionBlueprint;
use App\Models\Subject;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EducationalGeometryTemplateSvgRenderer;
use App\Services\QuestionDuplicateDetector;
use App\Services\QuestionTypeConfiguration;
use App\Services\QuestionVerificationService;
use App\Services\StimulusImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class QuestionController extends Controller
{
    public function __construct(
        private QuestionTypeConfiguration $questionTypeConfiguration,
        private EducationalGeometryTemplateSvgRenderer $geometryTemplateRenderer,
    ) {}

    public function index(Request $request): Response
    {
        $questions = Question::query()
            ->where('school_id', $request->user()->school_id)
            ->whereNull('superseded_by_id')
            ->where(function ($query) {
                $query->whereNull('questions.story_generation_id')
                    ->orWhereHas('storyGeneration', fn ($generation) => $generation
                        ->where('request_payload->format', 'direct'))
                    ->orWhereNotExists(function ($subquery) {
                        $subquery->selectRaw('1')
                            ->from('questions as earlier_bundle_questions')
                            ->whereColumn('earlier_bundle_questions.story_generation_id', 'questions.story_generation_id')
                            ->whereNull('earlier_bundle_questions.superseded_by_id')
                            ->whereColumn('earlier_bundle_questions.id', '<', 'questions.id');
                    });
            })
            ->with([
                'competency:id,subject_id,code,name',
                'competency.subject:id,code,name',
                'author:id,name',
                'storyGeneration:id,request_payload,result_payload',
            ])
            ->withCount([
                'variants',
                'verifications',
                'bundleQuestions as bundle_question_count',
                'bundleQuestions as bundle_draft_count' => fn ($query) => $query->where('status', QuestionStatus::Draft),
                'bundleQuestions as bundle_review_count' => fn ($query) => $query->where('status', QuestionStatus::Review),
                'bundleQuestions as bundle_published_count' => fn ($query) => $query->where('status', QuestionStatus::Published),
                'bundleQuestions as bundle_archived_count' => fn ($query) => $query->where('status', QuestionStatus::Archived),
            ])
            ->when($request->string('search')->toString(), function ($query, string $search) {
                $query->where(fn ($nested) => $nested
                    ->where('questions.title', 'like', "%{$search}%")
                    ->orWhere('questions.prompt', 'like', "%{$search}%")
                    ->orWhere(fn ($storyBundle) => $storyBundle
                        ->whereHas('storyGeneration', fn ($generation) => $generation
                            ->where('request_payload->format', '!=', 'direct'))
                        ->whereHas('bundleQuestions', fn ($bundleQuestion) => $bundleQuestion
                            ->where('title', 'like', "%{$search}%")
                            ->orWhere('prompt', 'like', "%{$search}%"))));
            })
            ->when($request->string('status')->toString(), function ($query, string $status) {
                $query->where(fn ($filtered) => $filtered
                    ->where(fn ($standalone) => $standalone
                        ->where(fn ($source) => $source
                            ->whereNull('questions.story_generation_id')
                            ->orWhereHas('storyGeneration', fn ($generation) => $generation
                                ->where('request_payload->format', 'direct')))
                        ->where('questions.status', $status))
                    ->orWhere(fn ($bundle) => $bundle
                        ->whereNotNull('questions.story_generation_id')
                        ->whereHas('storyGeneration', fn ($generation) => $generation
                            ->where('request_payload->format', '!=', 'direct'))
                        ->whereHas('bundleQuestions', fn ($bundleQuestion) => $bundleQuestion->where('status', $status))));
            })
            ->when($request->integer('subject_id'), fn ($query, int $subjectId) => $query
                ->whereHas('competency', fn ($competency) => $competency->where('subject_id', $subjectId)))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Questions/Index', [
            'questions' => $questions,
            'subjects' => $this->subjects($request),
            'filters' => $request->only(['search', 'status', 'subject_id']),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $subjects = $this->subjects($request);
        $selectedSubjectId = $subjects->contains('id', $request->integer('subject_id'))
            ? $request->integer('subject_id')
            : null;

        if ($subjects->firstWhere('id', $selectedSubjectId)?->code === 'BIND') {
            return to_route('manual-story-bundles.create', ['subject_id' => $selectedSubjectId]);
        }

        return Inertia::render('Questions/Create', [
            'subjects' => $subjects,
            'competencies' => $this->competencies($request),
            'questionBlueprints' => $this->questionBlueprints($request),
            'assessments' => $this->assessmentsForQuestion($request),
            'questionTypes' => $this->questionTypeConfiguration->options($request->user()->school),
            'selectedSubjectId' => $selectedSubjectId,
            'stimulusSvgTemplates' => $this->stimulusSvgTemplates(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger, StimulusImageService $imageService): RedirectResponse
    {
        $data = $this->validatedData($request);
        $illustration = null;
        $explanationIllustration = null;

        try {
            $illustration = $this->storeStimulusImage($request, $data, $imageService);
            $explanationIllustration = $this->storeExplanationImage($request, $data, $imageService);
            $metadata = $this->withExplanationImage(
                $this->withStimulusImage([], $data, $illustration),
                $data,
                $explanationIllustration,
            );
            $question = DB::transaction(function () use ($data, $request, $metadata): Question {
                $question = Question::create([
                    ...$this->attributes($data, $metadata),
                    'school_id' => $request->user()->school_id,
                    'author_id' => $request->user()->id,
                    'status' => QuestionStatus::Draft,
                ]);
                $this->syncOptions($question, $data['options'] ?? []);

                return $question;
            });
        } catch (Throwable $exception) {
            $this->deleteStoredIllustration($illustration);
            $this->deleteStoredIllustration($explanationIllustration);

            throw $exception;
        }
        $auditLogger->log($request, 'question.created', $question);

        // Attach question to target assessment if specified
        $this->attachToAssessment($request, $question);

        return to_route('questions.show', $question)->with('success', 'Soal berhasil disimpan sebagai draft.');
    }

    public function show(Request $request, Question $question): Response
    {
        $this->ensureSameSchool($request, $question);
        $question->load([
            'competency:id,subject_id,code,domain,name',
            'competency.subject:id,code,name',
            'questionBlueprint:id,code,name',
            'author:id,name',
            'approver:id,name',
            'options',
            'reviews.reviewer:id,name',
            'verifications.verifier:id,name',
            'variants' => fn ($query) => $query->with('competency:id,code,name')->latest(),
            'revisionOf:id,title,version,status',
            'supersededBy:id,title,version,status',
        ]);

        return Inertia::render('Questions/Show', [
            'question' => $question,
            'stimulusSvgTemplates' => $this->stimulusSvgTemplates(),
            'verification' => $this->verificationSummary($question, $request->user()),
            'latestGeneration' => ($latestGeneration = AiGeneration::query()
                ->where('source_question_id', $question->id)
                ->where('type', AiGenerationType::QuestionVariants)
                ->latest()
                ->first()) ? [
                    'status' => $latestGeneration->status,
                    'error' => $latestGeneration->status === AiGenerationStatus::Failed
                        ? 'Variasi soal belum berhasil dibuat. Silakan coba kembali.'
                        : null,
                ] : null,
        ]);
    }

    public function edit(Request $request, Question $question): Response
    {
        $this->ensureSameSchool($request, $question);
        abort_if($question->superseded_by_id !== null, 409, 'Versi soal ini sudah digantikan oleh revisi yang lebih baru.');
        $question->load('options');
        $returnGeneration = $this->returnGeneration($request, $question);

        return Inertia::render('Questions/Create', [
            'subjects' => $this->subjects($request),
            'competencies' => $this->competencies($request),
            'questionBlueprints' => $this->questionBlueprints($request),
            'assessments' => $this->assessmentsForQuestion($request),
            'questionTypes' => $this->questionTypeConfiguration->options($request->user()->school, $question->type),
            'question' => $question,
            'returnGeneration' => $returnGeneration ? [
                'id' => $returnGeneration->id,
                'format' => data_get($returnGeneration->request_payload, 'format') === 'direct' ? 'direct' : 'story',
            ] : null,
        ]);
    }

    public function update(Request $request, Question $question, AuditLogger $auditLogger, StimulusImageService $imageService): RedirectResponse
    {
        $this->ensureSameSchool($request, $question);
        abort_if($question->superseded_by_id !== null, 409, 'Versi soal ini sudah digantikan oleh revisi yang lebih baru.');
        $returnGeneration = $this->returnGeneration($request, $question);
        $data = $this->validatedData($request, $question);
        $illustration = null;
        $explanationIllustration = null;
        $createRevision = $question->status === QuestionStatus::Published
            || $question->assessments()->exists();

        try {
            $illustration = $this->storeStimulusImage($request, $data, $imageService);
            $explanationIllustration = $this->storeExplanationImage($request, $data, $imageService);
            $metadata = $this->withExplanationImage(
                $this->withStimulusImage($question->metadata ?? [], $data, $illustration),
                $data,
                $explanationIllustration,
            );
            $savedQuestion = DB::transaction(function () use ($question, $data, $request, $createRevision, $metadata): Question {
                if ($createRevision) {
                    $revision = Question::create([
                        ...$this->attributes($data, $metadata),
                        'school_id' => $question->school_id,
                        'author_id' => $request->user()->id,
                        'parent_id' => $question->parent_id,
                        'revision_of_id' => $question->id,
                        'version' => $question->version + 1,
                        'story_generation_id' => $question->story_generation_id,
                        'status' => QuestionStatus::Draft,
                    ]);
                    $this->syncOptions($revision, $data['options'] ?? []);

                    return $revision;
                }

                $question->update([
                    ...$this->attributes($data, $metadata),
                    'status' => QuestionStatus::Draft,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);
                $question->verifications()->delete();
                $this->syncOptions($question, $data['options'] ?? []);

                return $question;
            });
        } catch (Throwable $exception) {
            $this->deleteStoredIllustration($illustration);
            $this->deleteStoredIllustration($explanationIllustration);

            throw $exception;
        }
        $auditLogger->log($request, $createRevision ? 'question.revision_created' : 'question.updated', $savedQuestion, [
            'revision_of_id' => $createRevision ? $question->id : null,
        ]);

        // Attach (revised) question to target assessment if specified
        $this->attachToAssessment($request, $savedQuestion);

        $redirect = $returnGeneration
            ? to_route(
                data_get($returnGeneration->request_payload, 'format') === 'direct' ? 'ai-questions.show' : 'story-questions.show',
                $returnGeneration,
            )
            : to_route('questions.show', $savedQuestion);

        return $redirect->with(
            'success',
            $createRevision
                ? "Revisi versi {$savedQuestion->version} disimpan sebagai draft. Versi lama tetap aman untuk paket yang sudah terbit."
                : 'Perubahan disimpan sebagai draft dan perlu diterbitkan ulang.',
        );
    }

    public function inlineUpdate(
        Request $request,
        AiGeneration $generation,
        Question $question,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->ensureSameSchool($request, $question);
        abort_unless(
            $generation->school_id === $request->user()->school_id
            && $generation->type === AiGenerationType::StoryQuestions
            && $question->story_generation_id === $generation->id
            && in_array($question->id, data_get($generation->result_payload, 'question_ids', []), true),
            404,
        );
        abort_unless(
            in_array($question->status, [QuestionStatus::Draft, QuestionStatus::Review], true),
            409,
            'Hanya soal draft atau yang menunggu verifikasi yang dapat diedit langsung dari paket AI.',
        );

        $question->loadMissing('competency');
        $irrelevantAnswerFields = match ($question->type) {
            QuestionType::SingleChoice, QuestionType::MultipleChoice => ['matching_pairs', 'matching_distractors', 'matrix_columns', 'matrix_rows'],
            QuestionType::ShortAnswer => ['options', 'matching_pairs', 'matching_distractors', 'matrix_columns', 'matrix_rows'],
            QuestionType::Matching => ['options', 'accepted_answers', 'matrix_columns', 'matrix_rows'],
            QuestionType::CategoryMatrix => ['options', 'accepted_answers', 'matching_pairs', 'matching_distractors'],
        };
        foreach ($irrelevantAnswerFields as $field) {
            $request->request->remove($field);
        }
        $request->merge([
            'subject_id' => $question->competency->subject_id,
            'competency_id' => $question->competency_id,
            'question_blueprint_id' => $question->question_blueprint_id,
            'type' => $question->type->value,
            'title' => $question->title,
            'difficulty' => $request->input('difficulty', $question->difficulty),
            'grade_level' => $question->grade_level,
            'cognitive_level' => $question->cognitive_level,
        ]);
        $data = $this->validatedData($request, $question);

        DB::transaction(function () use ($question, $data): void {
            $question->update([
                ...$this->attributes($data, $question->metadata ?? []),
                'status' => QuestionStatus::Draft,
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $question->verifications()->delete();
            $this->syncOptions($question, $data['options'] ?? []);
        });

        $auditLogger->log($request, 'question.inline_updated', $question, [
            'story_generation_id' => $generation->id,
        ]);

        return back()->with('success', 'Level soal, isi, jawaban, dan pembahasan berhasil diperbarui.');
    }

    public function destroyGenerated(
        Request $request,
        AiGeneration $generation,
        Question $question,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->ensureGeneratedQuestion($request, $generation, $question);
        abort_unless(
            in_array($question->status, [QuestionStatus::Draft, QuestionStatus::Review], true),
            409,
            'Hanya soal draft atau yang menunggu verifikasi yang dapat dihapus.',
        );
        abort_if($question->assessments()->exists(), 409, 'Soal yang sudah digunakan pada paket ujian tidak dapat dihapus.');

        DB::transaction(function () use ($generation, $question, $request, $auditLogger): void {
            $auditLogger->log($request, 'question.deleted_from_generation', $question, [
                'story_generation_id' => $generation->id,
            ]);
            $resultPayload = $generation->result_payload ?? [];
            $resultPayload['question_ids'] = collect(data_get($resultPayload, 'question_ids', []))
                ->reject(fn ($id): bool => (int) $id === $question->id)
                ->values()
                ->all();
            $resultPayload['question_count'] = count($resultPayload['question_ids']);
            $generation->update(['result_payload' => $resultPayload]);
            $question->delete();
        });

        return back()->with('success', 'Soal dihapus dari hasil pembuatan AI.');
    }

    public function duplicateCheck(Request $request, Question $question, QuestionDuplicateDetector $detector): JsonResponse
    {
        $this->ensureSameSchool($request, $question);
        $candidates = $detector->candidates($question);

        return response()->json([
            'blocking' => $candidates->contains('blocking', true),
            'candidates' => $candidates,
        ]);
    }

    public function duplicate(Request $request, Question $question, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSameSchool($request, $question);
        $question->load('options');

        $duplicate = DB::transaction(function () use ($question, $request): Question {
            $duplicate = $question->replicate([
                'revision_of_id', 'version', 'superseded_by_id', 'status', 'approved_by', 'approved_at', 'created_at', 'updated_at',
            ]);
            $duplicate->parent_id = $question->id;
            $duplicate->story_generation_id = null;
            $duplicate->author_id = $request->user()->id;
            $duplicate->revision_of_id = null;
            $duplicate->version = 1;
            $duplicate->superseded_by_id = null;
            $duplicate->status = QuestionStatus::Draft;
            $duplicate->title = trim(($question->title ?: 'Salinan soal').' - salinan');
            $duplicate->metadata = [
                ...($question->metadata ?? []),
                'duplicated_from' => $question->id,
            ];
            $duplicate->save();

            foreach ($question->options as $option) {
                $duplicate->options()->create($option->only(['label', 'content', 'is_correct', 'position']));
            }

            return $duplicate;
        });
        $auditLogger->log($request, 'question.duplicated', $duplicate, ['source_question_id' => $question->id]);

        return to_route('questions.edit', $duplicate)->with('success', 'Salinan soal dibuat sebagai draft.');
    }

    public function archive(Request $request, Question $question, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSameSchool($request, $question);
        $question->update(['status' => QuestionStatus::Archived]);
        $auditLogger->log($request, 'question.archived', $question);

        return to_route('questions.index')->with('success', 'Soal dipindahkan ke arsip.');
    }

    public function approve(
        Request $request,
        Question $question,
        AuditLogger $auditLogger,
        QuestionDuplicateDetector $duplicateDetector,
        QuestionVerificationService $verificationService,
    ): RedirectResponse {
        $this->ensureSameSchool($request, $question);
        abort_if($question->superseded_by_id !== null, 409, 'Versi soal ini sudah digantikan.');
        if ($question->status !== QuestionStatus::Published && $duplicateDetector->hasBlockingDuplicate($question)) {
            throw ValidationException::withMessages([
                'duplicate' => 'Soal belum dapat diverifikasi karena ditemukan soal lain yang sangat mirip. Edit atau hapus salah satunya terlebih dahulu.',
            ]);
        }

        $result = $verificationService->verify($question, $request->user());

        if ($result['created']) {
            $auditLogger->log($request, 'question.verified', $question, [
                'verification_count' => $result['count'],
                'published' => $result['published'],
            ]);
        }

        if ($result['published']) {
            $auditLogger->log($request, 'question.published', $question, [
                'verification_count' => $result['count'],
            ]);

            return back()->with('success', 'Verifikasi guru ke-3 tercatat. Soal otomatis diterbitkan dan siap masuk paket ujian.');
        }

        if ($result['already_published']) {
            return back()->with(
                'success',
                $result['created']
                    ? "Verifikasi tambahan Anda tercatat. Soal tetap terbit dengan total {$result['count']} verifikator guru."
                    : "Anda sudah memverifikasi soal ini. Total verifikator tetap {$result['count']} guru.",
            );
        }

        if (! $result['created']) {
            return back()->with('success', "Anda sudah memverifikasi soal ini. Progres tetap {$result['count']}/".Question::REQUIRED_VERIFICATIONS.'.');
        }

        return back()->with(
            'success',
            "Verifikasi Anda tercatat ({$result['count']}/".Question::REQUIRED_VERIFICATIONS."). Masih diperlukan {$result['remaining']} guru lagi.",
        );
    }

    private function verificationSummary(Question $question, User $user): array
    {
        $count = $question->verifications->count();

        return [
            'required' => Question::REQUIRED_VERIFICATIONS,
            'count' => $count,
            'remaining' => max(0, Question::REQUIRED_VERIFICATIONS - $count),
            'currentUserVerified' => $question->verifications->contains('verifier_id', $user->id),
            'canVerify' => $user->hasRole(UserRole::Teacher)
                && $question->status !== QuestionStatus::Archived
                && $question->superseded_by_id === null,
            'verifiers' => $question->verifications->map(fn ($verification): array => [
                'id' => $verification->verifier_id,
                'name' => $verification->verifier?->name ?? 'Guru tidak aktif',
                'verifiedAt' => $verification->verified_at,
            ])->values(),
        ];
    }

    private function validatedData(Request $request, ?Question $existingQuestion = null): array
    {
        $allowedQuestionTypes = $this->questionTypeConfiguration->enabledValues($request->user()->school);
        if ($existingQuestion) {
            $allowedQuestionTypes[] = $existingQuestion->type->value;
        }

        $data = $request->validate([
            'return_generation_id' => ['nullable', 'integer'],
            'subject_id' => ['required', 'integer'],
            'competency_id' => ['required', 'integer'],
            'question_blueprint_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::in(array_unique($allowedQuestionTypes))],
            'title' => ['nullable', 'string', 'max:255'],
            'stimulus' => ['nullable', 'string', 'max:20000'],
            'stimulus_visual_type' => ['nullable', Rule::in(['none', 'table', 'bar_chart', 'pictogram', 'pie_chart'])],
            'stimulus_visual_title' => ['exclude_if:stimulus_visual_type,none', 'nullable', 'string', 'max:255'],
            'stimulus_table_headers' => ['exclude_unless:stimulus_visual_type,table', 'required', 'array', 'between:2,6'],
            'stimulus_table_headers.*' => ['required', 'string', 'max:100'],
            'stimulus_table_rows' => ['exclude_unless:stimulus_visual_type,table', 'required', 'array', 'between:1,15'],
            'stimulus_table_rows.*' => ['required', 'array'],
            'stimulus_table_rows.*.*' => ['required', 'string', 'max:500'],
            'stimulus_chart_mode' => ['exclude_unless:stimulus_visual_type,bar_chart', 'nullable', Rule::in(['single', 'grouped'])],
            'stimulus_chart_items' => ['exclude_if:stimulus_chart_mode,grouped', 'exclude_unless:stimulus_visual_type,bar_chart', 'required', 'array', 'between:2,12'],
            'stimulus_chart_items.*.label' => ['exclude_if:stimulus_chart_mode,grouped', 'required', 'string', 'max:60'],
            'stimulus_chart_items.*.value' => ['exclude_if:stimulus_chart_mode,grouped', 'required', 'numeric', 'min:0', 'max:1000000000'],
            'stimulus_chart_series_labels' => ['exclude_unless:stimulus_chart_mode,grouped', 'required', 'array', 'between:2,4'],
            'stimulus_chart_series_labels.*' => ['required', 'string', 'max:60'],
            'stimulus_chart_grouped_categories' => ['exclude_unless:stimulus_chart_mode,grouped', 'required', 'array', 'between:2,8'],
            'stimulus_chart_grouped_categories.*.label' => ['required', 'string', 'max:60'],
            'stimulus_chart_grouped_categories.*.values' => ['required', 'array'],
            'stimulus_chart_grouped_categories.*.values.*' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'stimulus_chart_x_axis_label' => ['exclude_unless:stimulus_visual_type,bar_chart', 'nullable', 'string', 'max:100'],
            'stimulus_chart_y_axis_label' => ['exclude_unless:stimulus_visual_type,bar_chart', 'nullable', 'string', 'max:100'],
            'stimulus_chart_maximum' => ['exclude_unless:stimulus_visual_type,bar_chart', 'nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'stimulus_pictogram_symbol' => ['exclude_unless:stimulus_visual_type,pictogram', 'required', 'string', 'max:20'],
            'stimulus_pictogram_legend_value' => ['exclude_unless:stimulus_visual_type,pictogram', 'required', 'numeric', 'gt:0', 'max:1000000000'],
            'stimulus_pictogram_unit' => ['exclude_unless:stimulus_visual_type,pictogram', 'nullable', 'string', 'max:50'],
            'stimulus_pictogram_items' => ['exclude_unless:stimulus_visual_type,pictogram', 'required', 'array', 'between:2,12'],
            'stimulus_pictogram_items.*.label' => ['required', 'string', 'max:60'],
            'stimulus_pictogram_items.*.value' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'stimulus_pie_unit' => ['exclude_unless:stimulus_visual_type,pie_chart', 'nullable', 'string', 'max:50'],
            'stimulus_pie_show_percentages' => ['exclude_unless:stimulus_visual_type,pie_chart', 'required', 'boolean'],
            'stimulus_pie_items' => ['exclude_unless:stimulus_visual_type,pie_chart', 'required', 'array', 'between:2,12'],
            'stimulus_pie_items.*.label' => ['required', 'string', 'max:60'],
            'stimulus_pie_items.*.value' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'stimulus_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=5000,max_height=5000'],
            'stimulus_image_source' => ['nullable', Rule::in(['upload', 'template'])],
            'stimulus_svg_template' => ['nullable', Rule::in(EducationalGeometryTemplateSvgRenderer::TEMPLATES)],
            'stimulus_svg_dimension_a' => ['nullable', 'numeric', 'between:-1000000,1000000'],
            'stimulus_svg_dimension_b' => ['nullable', 'numeric', 'between:-1000000,1000000'],
            'stimulus_svg_dimension_c' => ['nullable', 'numeric', 'between:-1000000,1000000'],
            'stimulus_svg_unit' => ['nullable', 'string', 'max:20'],
            'stimulus_svg_zoom' => ['nullable', 'numeric', 'between:0.25,3'],
            'stimulus_svg_offset_x' => ['nullable', 'numeric', 'between:-500,500'],
            'stimulus_svg_offset_y' => ['nullable', 'numeric', 'between:-300,300'],
            'stimulus_fraction_models' => ['nullable', 'array', 'between:2,4'],
            'stimulus_fraction_models.*.numerator' => ['required_with:stimulus_fraction_models', 'integer', 'between:0,24'],
            'stimulus_fraction_models.*.denominator' => ['required_with:stimulus_fraction_models', 'integer', 'between:1,24'],
            'stimulus_fraction_models.*.shaded_parts' => ['nullable', 'array', 'max:24'],
            'stimulus_fraction_models.*.shaded_parts.*' => ['integer', 'between:0,23'],
            'stimulus_image_width' => ['nullable', 'integer', 'between:100,1600'],
            'stimulus_image_height' => ['nullable', 'integer', 'between:100,1200'],
            'stimulus_upload_zoom' => ['nullable', 'numeric', 'between:0.25,3'],
            'stimulus_upload_offset_x' => ['nullable', 'numeric', 'between:-100,100'],
            'stimulus_upload_offset_y' => ['nullable', 'numeric', 'between:-100,100'],
            'stimulus_image_alt' => ['nullable', 'string', 'max:255'],
            'remove_stimulus_image' => ['nullable', 'boolean'],
            'prompt' => ['required', 'string', 'max:10000'],
            'explanation' => ['nullable', 'string', 'max:10000'],
            'explanation_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=5000,max_height=5000'],
            'explanation_image_alt' => ['nullable', 'string', 'max:255'],
            'remove_explanation_image' => ['nullable', 'boolean'],
            'difficulty' => ['required', 'integer', 'between:1,3'],
            'grade_level' => ['required', 'integer', Rule::in([6, 9, 12])],
            'cognitive_level' => ['nullable', 'string', 'max:100'],
            'options' => ['array'],
            'options.*.content' => ['required_with:options', 'string', 'max:3000'],
            'options.*.is_correct' => ['required_with:options', 'boolean'],
            'accepted_answers' => ['array'],
            'accepted_answers.*' => ['nullable', 'string', 'max:500'],
            'matching_pairs' => ['required_if:type,matching', 'array', 'between:2,8'],
            'matching_pairs.*.left_id' => ['nullable', 'uuid', 'distinct'],
            'matching_pairs.*.left' => ['required_if:type,matching', 'string', 'max:1000'],
            'matching_pairs.*.right_id' => ['nullable', 'uuid', 'distinct'],
            'matching_pairs.*.right' => ['required_if:type,matching', 'string', 'max:1000'],
            'matching_distractors' => ['array', 'max:4'],
            'matching_distractors.*.id' => ['nullable', 'uuid', 'distinct'],
            'matching_distractors.*.content' => ['required_with:matching_distractors', 'string', 'max:1000'],
            'matrix_columns' => ['required_if:type,category_matrix', 'array', 'between:2,4'],
            'matrix_columns.*.id' => ['nullable', 'uuid', 'distinct'],
            'matrix_columns.*.label' => ['required_if:type,category_matrix', 'string', 'max:255'],
            'matrix_rows' => ['required_if:type,category_matrix', 'array', 'between:2,10'],
            'matrix_rows.*.id' => ['nullable', 'uuid', 'distinct'],
            'matrix_rows.*.statement' => ['required_if:type,category_matrix', 'string', 'max:1000'],
            'matrix_rows.*.correct_column_index' => ['required_if:type,category_matrix', 'integer', 'between:0,3'],
            'target_assessment_id' => ['nullable', 'integer', 'exists:assessments,id'],
        ]);

        $data['stimulus_visual_type'] ??= 'none';
        if ($data['stimulus_visual_type'] === 'bar_chart') {
            $data['stimulus_chart_mode'] ??= 'single';
        }

        if (($data['stimulus_image_source'] ?? null) === 'template') {
            $template = $data['stimulus_svg_template'] ?? null;
            if (! $template || ! isset($data['stimulus_svg_dimension_a'])) {
                throw ValidationException::withMessages([
                    'stimulus_svg_template' => 'Pilih template dan isi ukuran utamanya.',
                ]);
            }
            $templateConfig = collect($this->stimulusSvgTemplates())->firstWhere('value', $template);
            foreach (['b', 'c'] as $dimension) {
                if (isset($templateConfig["dimension_{$dimension}_label"]) && ! isset($data["stimulus_svg_dimension_{$dimension}"])) {
                    throw ValidationException::withMessages([
                        "stimulus_svg_dimension_{$dimension}" => 'Lengkapi semua nilai yang dibutuhkan template ini.',
                    ]);
                }
            }
            if (! ($templateConfig['allow_signed_dimensions'] ?? false)) {
                foreach (['a', 'b', 'c'] as $dimension) {
                    $key = "stimulus_svg_dimension_{$dimension}";
                    $allowsZeroNumerator = $template === 'fraction_equivalent_circles' && $dimension === 'a' && (float) ($data[$key] ?? 0) === 0.0;
                    if (isset($data[$key]) && (float) $data[$key] <= 0 && ! $allowsZeroNumerator) {
                        throw ValidationException::withMessages([$key => 'Nilai harus lebih besar dari nol.']);
                    }
                }
            }
            if (in_array($template, ['circle_sector', 'shaded_circle_sector'], true) && (float) ($data['stimulus_svg_dimension_b'] ?? 0) > 360) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_b' => 'Sudut pusat maksimal 360 derajat.',
                ]);
            }
            if (in_array($template, ['annulus', 'shaded_annulus'], true) && (float) ($data['stimulus_svg_dimension_b'] ?? 0) >= (float) $data['stimulus_svg_dimension_a']) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_b' => 'Jari-jari dalam harus lebih kecil dari jari-jari luar.',
                ]);
            }
            if ($template === 'composite_square_quarter_circle' && (float) ($data['stimulus_svg_dimension_b'] ?? 0) > (float) $data['stimulus_svg_dimension_a']) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_b' => 'Jari-jari seperempat lingkaran tidak boleh melebihi sisi persegi.',
                ]);
            }
            if ($template === 'shaded_square_circle' && (2 * (float) ($data['stimulus_svg_dimension_b'] ?? 0)) > (float) $data['stimulus_svg_dimension_a']) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_b' => 'Diameter lingkaran tidak boleh melebihi sisi persegi.',
                ]);
            }
            if ($template === 'composite_rectangle_two_quarters' && (float) ($data['stimulus_svg_dimension_b'] ?? 0) > (float) $data['stimulus_svg_dimension_a']) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_b' => 'Lebar tidak boleh melebihi panjang untuk susunan dua seperempat lingkaran ini.',
                ]);
            }
            if ($template === 'composite_l_shape' && (float) ($data['stimulus_svg_dimension_c'] ?? 0) >= min((float) $data['stimulus_svg_dimension_a'], (float) $data['stimulus_svg_dimension_b'])) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_c' => 'Ukuran lekukan harus lebih kecil dari panjang dan tinggi total.',
                ]);
            }
            if ($template === 'shaded_rectangle_circle' && (2 * (float) ($data['stimulus_svg_dimension_c'] ?? 0)) > min((float) $data['stimulus_svg_dimension_a'], (float) $data['stimulus_svg_dimension_b'])) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_c' => 'Diameter lingkaran tidak boleh melebihi sisi terpendek persegi panjang.',
                ]);
            }
            if ($template === 'circle_chord' && (float) ($data['stimulus_svg_dimension_b'] ?? 0) > (2 * (float) $data['stimulus_svg_dimension_a'])) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_b' => 'Panjang tali busur tidak boleh melebihi diameter lingkaran.',
                ]);
            }
            if ($template === 'cone_net' && (float) ($data['stimulus_svg_dimension_b'] ?? 0) < (float) $data['stimulus_svg_dimension_a']) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_b' => 'Garis pelukis kerucut tidak boleh lebih pendek dari jari-jari alas.',
                ]);
            }
            if ($template === 'fraction_equivalent_circles') {
                $fractionModels = $data['stimulus_fraction_models'] ?? [];
                if (count($fractionModels) < 2 || count($fractionModels) > 4) {
                    throw ValidationException::withMessages([
                        'stimulus_fraction_models' => 'Pilih 2–4 lingkaran pecahan.',
                    ]);
                }
                foreach ($fractionModels as $index => $model) {
                    if ((int) $model['numerator'] > (int) $model['denominator']) {
                        throw ValidationException::withMessages([
                            "stimulus_fraction_models.{$index}.numerator" => 'Bagian yang diarsir tidak boleh melebihi total bagian.',
                        ]);
                    }
                    $shadedParts = array_values(array_unique($model['shaded_parts'] ?? []));
                    if (count($shadedParts) !== (int) $model['numerator'] || collect($shadedParts)->contains(fn ($part): bool => (int) $part >= (int) $model['denominator'])) {
                        throw ValidationException::withMessages([
                            "stimulus_fraction_models.{$index}.shaded_parts" => 'Pilihan sektor arsiran tidak sesuai dengan jumlah bagian lingkaran.',
                        ]);
                    }
                }
            } elseif (str_starts_with((string) $template, 'fraction_')) {
                $numerator = (float) $data['stimulus_svg_dimension_a'];
                $denominator = (float) $data['stimulus_svg_dimension_b'];
                $factor = (float) ($data['stimulus_svg_dimension_c'] ?? 2);
                if (floor($numerator) !== $numerator || floor($denominator) !== $denominator || $numerator > $denominator || $denominator > 24) {
                    throw ValidationException::withMessages([
                        'stimulus_svg_dimension_a' => 'Pembilang dan penyebut harus bilangan bulat, pembilang tidak melebihi penyebut, dan penyebut maksimal 24.',
                    ]);
                }
                if (str_starts_with((string) $template, 'fraction_equivalent_') && (floor($factor) !== $factor || $factor < 2 || $factor > 4 || ($denominator * $factor) > 24)) {
                    throw ValidationException::withMessages([
                        'stimulus_svg_dimension_c' => 'Faktor harus bilangan bulat 2–4 dan hasil penyebut maksimal 24 bagian.',
                    ]);
                }
            }
            $angle = (float) ($data['stimulus_svg_dimension_a'] ?? 0);
            $invalidAngle = match ($template) {
                'angle_acute' => $angle >= 90,
                'angle_right' => $angle !== 90.0,
                'angle_obtuse' => $angle <= 90 || $angle >= 180,
                'angle_straight' => $angle !== 180.0,
                'angle_reflex' => $angle <= 180 || $angle >= 360,
                'intersecting_lines', 'protractor' => $angle >= 180,
                'rotation' => $angle > 360,
                default => false,
            };
            if ($invalidAngle) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_a' => 'Besar sudut tidak sesuai dengan variasi yang dipilih.',
                ]);
            }
            if ($template === 'clock' && ((float) $data['stimulus_svg_dimension_a'] < 0 || (float) $data['stimulus_svg_dimension_a'] > 23 || (float) $data['stimulus_svg_dimension_b'] < 0 || (float) $data['stimulus_svg_dimension_b'] > 59)) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_a' => 'Jam harus 0–23 dan menit harus 0–59.',
                ]);
            }
            if ($template === 'number_line' && (float) $data['stimulus_svg_dimension_b'] <= (float) $data['stimulus_svg_dimension_a']) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_b' => 'Nilai maksimum harus lebih besar dari nilai minimum.',
                ]);
            }
            if ($template === 'scale_bar' && ((float) $data['stimulus_svg_dimension_b'] < 1 || (float) $data['stimulus_svg_dimension_b'] > 12 || floor((float) $data['stimulus_svg_dimension_b']) !== (float) $data['stimulus_svg_dimension_b'])) {
                throw ValidationException::withMessages([
                    'stimulus_svg_dimension_b' => 'Jumlah interval harus bilangan bulat antara 1–12.',
                ]);
            }
        }

        if (($data['stimulus_visual_type'] ?? 'none') === 'table') {
            $columnCount = count($data['stimulus_table_headers']);
            $hasInvalidRow = collect($data['stimulus_table_rows'])->contains(
                fn (array $row): bool => count($row) !== $columnCount,
            );

            if ($hasInvalidRow) {
                throw ValidationException::withMessages([
                    'stimulus_table_rows' => 'Setiap baris tabel harus memiliki jumlah sel yang sama dengan jumlah kolom.',
                ]);
            }
        }

        if (($data['stimulus_visual_type'] ?? 'none') === 'bar_chart' && ($data['stimulus_chart_mode'] ?? 'single') === 'grouped') {
            $seriesCount = count($data['stimulus_chart_series_labels']);
            $hasInvalidCategory = collect($data['stimulus_chart_grouped_categories'])->contains(
                fn (array $category): bool => count($category['values']) !== $seriesCount,
            );
            $seriesLabels = collect($data['stimulus_chart_series_labels'])->map(fn (string $label): string => mb_strtolower(trim($label)));
            $categoryLabels = collect($data['stimulus_chart_grouped_categories'])->pluck('label')->map(fn (string $label): string => mb_strtolower(trim($label)));

            if ($hasInvalidCategory || $seriesLabels->unique()->count() !== $seriesLabels->count() || $categoryLabels->unique()->count() !== $categoryLabels->count()) {
                throw ValidationException::withMessages([
                    'stimulus_chart_grouped_categories' => 'Nama seri dan kategori harus unik, serta setiap kategori harus memiliki nilai untuk seluruh seri.',
                ]);
            }
        }

        if (($data['stimulus_visual_type'] ?? 'none') === 'bar_chart' && isset($data['stimulus_chart_maximum'])) {
            $largestValue = ($data['stimulus_chart_mode'] ?? 'single') === 'grouped'
                ? collect($data['stimulus_chart_grouped_categories'])->flatMap(fn (array $category): array => $category['values'])->max(fn (mixed $value): float => (float) $value)
                : collect($data['stimulus_chart_items'])->max(fn (array $item): float => (float) $item['value']);
            if ((float) $data['stimulus_chart_maximum'] < $largestValue) {
                throw ValidationException::withMessages([
                    'stimulus_chart_maximum' => 'Batas maksimum sumbu Y tidak boleh lebih kecil dari nilai data terbesar.',
                ]);
            }
        }

        if (($data['stimulus_visual_type'] ?? 'none') === 'pictogram') {
            $legendValue = (float) $data['stimulus_pictogram_legend_value'];
            $tooManySymbols = collect($data['stimulus_pictogram_items'])->contains(
                fn (array $item): bool => ((float) $item['value'] / $legendValue) > 40,
            );

            if ($tooManySymbols) {
                throw ValidationException::withMessages([
                    'stimulus_pictogram_items' => 'Setiap kategori maksimal menampilkan 40 simbol. Perbesar nilai tiap simbol.',
                ]);
            }
        }

        if (($data['stimulus_visual_type'] ?? 'none') === 'pie_chart') {
            $total = collect($data['stimulus_pie_items'])->sum(fn (array $item): float => (float) $item['value']);
            if ($total <= 0) {
                throw ValidationException::withMessages([
                    'stimulus_pie_items' => 'Diagram lingkaran membutuhkan minimal satu nilai yang lebih besar dari nol.',
                ]);
            }
        }

        $subjectExists = Subject::query()
            ->whereKey($data['subject_id'])
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->exists();

        if (! $subjectExists) {
            throw ValidationException::withMessages([
                'subject_id' => 'Mata pelajaran tidak tersedia.',
            ]);
        }

        $competency = Competency::query()
            ->whereKey($data['competency_id'])
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->firstOrFail();

        if ($competency->subject_id !== (int) $data['subject_id']) {
            throw ValidationException::withMessages([
                'competency_id' => 'Kompetensi harus berasal dari mata pelajaran yang dipilih.',
            ]);
        }

        if ($competency->grade_level !== (int) $data['grade_level']) {
            throw ValidationException::withMessages([
                'grade_level' => 'Jenjang soal harus sama dengan jenjang kompetensi.',
            ]);
        }

        $blueprintId = (int) ($data['question_blueprint_id'] ?? 0);
        if ($blueprintId > 0) {
            $blueprintExists = QuestionBlueprint::query()
                ->whereKey($blueprintId)
                ->where('subject_id', $competency->subject_id)
                ->where(fn ($query) => $query
                    ->whereNull('school_id')
                    ->orWhere('school_id', $request->user()->school_id))
                ->exists();
            if (! $blueprintExists) {
                throw ValidationException::withMessages([
                    'question_blueprint_id' => 'Tipe soal tidak tersedia untuk kompetensi ini.',
                ]);
            }
        }

        $type = QuestionType::from($data['type']);
        $data['accepted_answers'] = array_values(array_filter($data['accepted_answers'] ?? []));
        $correctCount = collect($data['options'] ?? [])->where('is_correct', true)->count();

        if ($type === QuestionType::SingleChoice && (count($data['options'] ?? []) < 2 || $correctCount !== 1)) {
            throw ValidationException::withMessages([
                'options' => 'Pilihan tunggal membutuhkan minimal dua opsi dan tepat satu jawaban benar.',
            ]);
        }

        if ($type === QuestionType::MultipleChoice && (count($data['options'] ?? []) < 2 || $correctCount < 1)) {
            throw ValidationException::withMessages([
                'options' => 'Pilihan kompleks membutuhkan minimal dua opsi dan satu jawaban benar.',
            ]);
        }

        if ($type === QuestionType::ShortAnswer && empty($data['accepted_answers'])) {
            throw ValidationException::withMessages([
                'accepted_answers' => 'Isian singkat membutuhkan minimal satu jawaban yang diterima.',
            ]);
        }

        if ($type === QuestionType::Matching) {
            $leftItems = collect($data['matching_pairs'])->pluck('left')->map(fn (string $value): string => mb_strtolower(trim($value)));
            $rightItems = collect($data['matching_pairs'])->pluck('right')
                ->merge(collect($data['matching_distractors'] ?? [])->pluck('content'))
                ->map(fn (string $value): string => mb_strtolower(trim($value)));

            if ($leftItems->unique()->count() !== $leftItems->count() || $rightItems->unique()->count() !== $rightItems->count()) {
                throw ValidationException::withMessages([
                    'matching_pairs' => 'Isi pada setiap lajur harus unik agar pasangan tidak ambigu.',
                ]);
            }
        }

        if ($type === QuestionType::CategoryMatrix) {
            $columnLabels = collect($data['matrix_columns'])->pluck('label')->map(fn (string $value): string => mb_strtolower(trim($value)));
            $statements = collect($data['matrix_rows'])->pluck('statement')->map(fn (string $value): string => mb_strtolower(trim($value)));
            $invalidColumn = collect($data['matrix_rows'])->contains(
                fn (array $row): bool => $row['correct_column_index'] >= count($data['matrix_columns']),
            );

            if ($columnLabels->unique()->count() !== $columnLabels->count()
                || $statements->unique()->count() !== $statements->count()
                || $invalidColumn) {
                throw ValidationException::withMessages([
                    'matrix_rows' => 'Kategori dan pernyataan harus unik serta setiap kunci harus memilih kategori yang tersedia.',
                ]);
            }
        }

        if (! in_array($type, [QuestionType::SingleChoice, QuestionType::MultipleChoice], true)) {
            $data['options'] = [];
        }

        return $data;
    }

    private function returnGeneration(Request $request, Question $question): ?AiGeneration
    {
        $generationId = $request->integer('return_generation_id');
        if ($generationId === 0 || $question->story_generation_id !== $generationId) {
            return null;
        }

        return AiGeneration::query()
            ->whereKey($generationId)
            ->where('school_id', $request->user()->school_id)
            ->where('type', AiGenerationType::StoryQuestions)
            ->first();
    }

    private function ensureGeneratedQuestion(Request $request, AiGeneration $generation, Question $question): void
    {
        $this->ensureSameSchool($request, $question);
        abort_unless(
            $generation->school_id === $request->user()->school_id
            && $generation->type === AiGenerationType::StoryQuestions
            && $question->story_generation_id === $generation->id
            && in_array($question->id, data_get($generation->result_payload, 'question_ids', []), true),
            404,
        );
    }

    private function attributes(array $data, array $existingMetadata = []): array
    {
        $type = QuestionType::from($data['type']);
        $metadata = $existingMetadata;

        if ($type === QuestionType::ShortAnswer) {
            $metadata['accepted_answers'] = $data['accepted_answers'];
        } else {
            unset($metadata['accepted_answers']);
        }

        if ($type === QuestionType::Matching) {
            $metadata['matching_pairs'] = collect($data['matching_pairs'])->map(fn (array $pair): array => [
                'left_id' => $pair['left_id'] ?? (string) Str::uuid(),
                'left' => trim($pair['left']),
                'right_id' => $pair['right_id'] ?? (string) Str::uuid(),
                'right' => trim($pair['right']),
            ])->all();
            $metadata['matching_distractors'] = collect($data['matching_distractors'] ?? [])->map(fn (array $distractor): array => [
                'id' => $distractor['id'] ?? (string) Str::uuid(),
                'content' => trim($distractor['content']),
            ])->all();
        } else {
            unset($metadata['matching_pairs'], $metadata['matching_distractors']);
        }

        if ($type === QuestionType::CategoryMatrix) {
            $columns = collect($data['matrix_columns'])->map(fn (array $column): array => [
                'id' => $column['id'] ?? (string) Str::uuid(),
                'label' => trim($column['label']),
            ])->values();
            $metadata['matrix_columns'] = $columns->all();
            $metadata['matrix_rows'] = collect($data['matrix_rows'])->map(fn (array $row): array => [
                'id' => $row['id'] ?? (string) Str::uuid(),
                'statement' => trim($row['statement']),
                'correct_column_id' => $columns[$row['correct_column_index']]['id'],
            ])->all();
        } else {
            unset($metadata['matrix_columns'], $metadata['matrix_rows']);
        }

        if (($data['stimulus_visual_type'] ?? 'none') === 'table') {
            $metadata['stimulus_visual'] = [
                'type' => 'table',
                'title' => trim($data['stimulus_visual_title'] ?? ''),
                'headers' => collect($data['stimulus_table_headers'])->map(fn (string $header): string => trim($header))->all(),
                'rows' => collect($data['stimulus_table_rows'])->map(
                    fn (array $row): array => collect($row)->map(fn (string $cell): string => trim($cell))->all(),
                )->all(),
            ];
        } elseif (($data['stimulus_visual_type'] ?? 'none') === 'bar_chart') {
            $barChart = [
                'type' => 'bar_chart',
                'title' => trim($data['stimulus_visual_title'] ?? ''),
                'x_axis_label' => trim($data['stimulus_chart_x_axis_label'] ?? ''),
                'y_axis_label' => trim($data['stimulus_chart_y_axis_label'] ?? ''),
                ...(isset($data['stimulus_chart_maximum']) ? ['maximum' => (float) $data['stimulus_chart_maximum']] : []),
            ];
            if (($data['stimulus_chart_mode'] ?? 'single') === 'grouped') {
                $categories = collect($data['stimulus_chart_grouped_categories']);
                $barChart['categories'] = $categories->pluck('label')->map(fn (string $label): string => trim($label))->all();
                $barChart['series'] = collect($data['stimulus_chart_series_labels'])->map(fn (string $label, int $seriesIndex): array => [
                    'label' => trim($label),
                    'values' => $categories->map(fn (array $category): float => (float) $category['values'][$seriesIndex])->all(),
                ])->all();
            } else {
                $barChart['items'] = collect($data['stimulus_chart_items'])->map(fn (array $item): array => [
                    'label' => trim($item['label']),
                    'value' => (float) $item['value'],
                ])->all();
            }
            $metadata['stimulus_visual'] = $barChart;
        } elseif (($data['stimulus_visual_type'] ?? 'none') === 'pictogram') {
            $metadata['stimulus_visual'] = [
                'type' => 'pictogram',
                'title' => trim($data['stimulus_visual_title'] ?? ''),
                'symbol' => trim($data['stimulus_pictogram_symbol']),
                'legend_value' => (float) $data['stimulus_pictogram_legend_value'],
                'unit' => trim($data['stimulus_pictogram_unit'] ?? ''),
                'items' => collect($data['stimulus_pictogram_items'])->map(fn (array $item): array => [
                    'label' => trim($item['label']),
                    'value' => (float) $item['value'],
                ])->all(),
            ];
        } elseif (($data['stimulus_visual_type'] ?? 'none') === 'pie_chart') {
            $metadata['stimulus_visual'] = [
                'type' => 'pie_chart',
                'title' => trim($data['stimulus_visual_title'] ?? ''),
                'unit' => trim($data['stimulus_pie_unit'] ?? ''),
                'show_percentages' => (bool) $data['stimulus_pie_show_percentages'],
                'items' => collect($data['stimulus_pie_items'])->map(fn (array $item): array => [
                    'label' => trim($item['label']),
                    'value' => (float) $item['value'],
                ])->all(),
            ];
        } else {
            unset($metadata['stimulus_visual']);
        }

        return [
            'competency_id' => $data['competency_id'],
            'question_blueprint_id' => $data['question_blueprint_id'] ?? null,
            'type' => $type,
            'title' => $data['title'] ?? null,
            'stimulus' => $data['stimulus'] ?? null,
            'prompt' => $data['prompt'],
            'explanation' => $data['explanation'] ?? null,
            'difficulty' => $data['difficulty'],
            'grade_level' => $data['grade_level'],
            'cognitive_level' => $data['cognitive_level'] ?? null,
            'metadata' => $metadata ?: null,
        ];
    }

    private function storeStimulusImage(Request $request, array $data, StimulusImageService $imageService): ?array
    {
        if (($data['stimulus_image_source'] ?? null) === 'template') {
            $svg = $this->geometryTemplateRenderer->render(
                $data['stimulus_svg_template'],
                (float) $data['stimulus_svg_dimension_a'],
                isset($data['stimulus_svg_dimension_b']) ? (float) $data['stimulus_svg_dimension_b'] : null,
                trim($data['stimulus_svg_unit'] ?? '') ?: 'cm',
                isset($data['stimulus_svg_dimension_c']) ? (float) $data['stimulus_svg_dimension_c'] : null,
                (float) ($data['stimulus_svg_zoom'] ?? 1),
                (float) ($data['stimulus_svg_offset_x'] ?? 0),
                (float) ($data['stimulus_svg_offset_y'] ?? 0),
                ['fraction_models' => $data['stimulus_fraction_models'] ?? []],
            );
            $path = 'question-stimuli/'.$request->user()->school_id.'/'.Str::uuid().'.svg';
            Storage::disk('public')->put($path, $svg, ['visibility' => 'public']);

            return [
                'disk' => 'public',
                'path' => $path,
                'mime_type' => 'image/svg+xml',
                'alt' => trim($data['stimulus_image_alt'] ?? '') ?: 'Diagram geometri soal',
                'source' => 'template-svg',
                'template' => $data['stimulus_svg_template'],
                'dimension_a' => (float) $data['stimulus_svg_dimension_a'],
                'dimension_b' => isset($data['stimulus_svg_dimension_b']) ? (float) $data['stimulus_svg_dimension_b'] : null,
                'dimension_c' => isset($data['stimulus_svg_dimension_c']) ? (float) $data['stimulus_svg_dimension_c'] : null,
                'unit' => trim($data['stimulus_svg_unit'] ?? '') ?: 'cm',
                'zoom' => (float) ($data['stimulus_svg_zoom'] ?? 1),
                'offset_x' => (float) ($data['stimulus_svg_offset_x'] ?? 0),
                'offset_y' => (float) ($data['stimulus_svg_offset_y'] ?? 0),
                'fraction_models' => $data['stimulus_fraction_models'] ?? null,
                'display_width' => (int) ($data['stimulus_image_width'] ?? 800),
                'display_height' => (int) ($data['stimulus_image_height'] ?? 450),
            ];
        }

        $file = $request->file('stimulus_image');

        if ($file === null) {
            return null;
        }

        return [
            ...$imageService->store(
                $file,
                $request->user()->school_id,
                trim($data['stimulus_image_alt'] ?? '') ?: trim($data['title'] ?? '') ?: 'Gambar stimulus soal',
            ),
            'display_width' => (int) ($data['stimulus_image_width'] ?? 800),
            'display_height' => (int) ($data['stimulus_image_height'] ?? 450),
            'display_zoom' => (float) ($data['stimulus_upload_zoom'] ?? 1),
            'display_offset_x' => (float) ($data['stimulus_upload_offset_x'] ?? 0),
            'display_offset_y' => (float) ($data['stimulus_upload_offset_y'] ?? 0),
        ];
    }

    private function withStimulusImage(array $metadata, array $data, ?array $illustration): array
    {
        if ($illustration !== null) {
            $metadata['illustration'] = $illustration;
        } elseif ($data['remove_stimulus_image'] ?? false) {
            unset($metadata['illustration']);
        } elseif (isset($metadata['illustration']) && trim($data['stimulus_image_alt'] ?? '') !== '') {
            $metadata['illustration']['alt'] = trim($data['stimulus_image_alt']);
        }

        if (isset($metadata['illustration'])) {
            $metadata['illustration']['display_width'] = (int) ($data['stimulus_image_width'] ?? data_get($metadata, 'illustration.display_width', 800));
            $metadata['illustration']['display_height'] = (int) ($data['stimulus_image_height'] ?? data_get($metadata, 'illustration.display_height', 450));
            if (($metadata['illustration']['source'] ?? null) !== 'template-svg') {
                $metadata['illustration']['display_zoom'] = (float) ($data['stimulus_upload_zoom'] ?? data_get($metadata, 'illustration.display_zoom', 1));
                $metadata['illustration']['display_offset_x'] = (float) ($data['stimulus_upload_offset_x'] ?? data_get($metadata, 'illustration.display_offset_x', 0));
                $metadata['illustration']['display_offset_y'] = (float) ($data['stimulus_upload_offset_y'] ?? data_get($metadata, 'illustration.display_offset_y', 0));
            }
        }

        return $metadata;
    }

    private function storeExplanationImage(Request $request, array $data, StimulusImageService $imageService): ?array
    {
        $file = $request->file('explanation_image');

        if ($file === null) {
            return null;
        }

        return $imageService->store(
            $file,
            $request->user()->school_id,
            trim($data['explanation_image_alt'] ?? '') ?: 'Gambar pembahasan soal',
            'explanation_image',
            'question-explanations',
        );
    }

    private function withExplanationImage(array $metadata, array $data, ?array $illustration): array
    {
        if ($illustration !== null) {
            $metadata['explanation_illustration'] = $illustration;
        } elseif ($data['remove_explanation_image'] ?? false) {
            unset($metadata['explanation_illustration']);
        } elseif (isset($metadata['explanation_illustration']) && trim($data['explanation_image_alt'] ?? '') !== '') {
            $metadata['explanation_illustration']['alt'] = trim($data['explanation_image_alt']);
        }

        return $metadata;
    }

    private function deleteStoredIllustration(?array $illustration): void
    {
        if ($illustration !== null && ($illustration['source'] ?? null) !== 'library-svg') {
            Storage::disk($illustration['disk'])->delete($illustration['path']);
        }
    }

    private function stimulusSvgTemplates(): array
    {
        return [
            ['value' => 'square', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'quadrilateral', 'family_label' => 'Segi empat', 'label' => 'Persegi', 'dimension_a_label' => 'Panjang sisi'],
            ['value' => 'rectangle', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'quadrilateral', 'family_label' => 'Segi empat', 'label' => 'Persegi panjang', 'dimension_a_label' => 'Panjang', 'dimension_b_label' => 'Lebar'],
            ['value' => 'parallelogram', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'quadrilateral', 'family_label' => 'Segi empat', 'label' => 'Jajar genjang', 'dimension_a_label' => 'Alas', 'dimension_b_label' => 'Tinggi'],
            ['value' => 'trapezoid', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'quadrilateral', 'family_label' => 'Segi empat', 'label' => 'Trapesium', 'dimension_a_label' => 'Sisi sejajar atas', 'dimension_b_label' => 'Sisi sejajar bawah', 'dimension_c_label' => 'Tinggi'],
            ['value' => 'trapezoid_right', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'quadrilateral', 'family_label' => 'Segi empat', 'label' => 'Trapesium siku-siku', 'dimension_a_label' => 'Sisi sejajar atas', 'dimension_b_label' => 'Sisi sejajar bawah', 'dimension_c_label' => 'Tinggi'],
            ['value' => 'trapezoid_isosceles', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'quadrilateral', 'family_label' => 'Segi empat', 'label' => 'Trapesium sama kaki', 'dimension_a_label' => 'Sisi sejajar atas', 'dimension_b_label' => 'Sisi sejajar bawah', 'dimension_c_label' => 'Tinggi'],
            ['value' => 'rhombus', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'quadrilateral', 'family_label' => 'Segi empat', 'label' => 'Belah ketupat', 'dimension_a_label' => 'Diagonal 1', 'dimension_b_label' => 'Diagonal 2'],
            ['value' => 'kite', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'quadrilateral', 'family_label' => 'Segi empat', 'label' => 'Layang-layang', 'dimension_a_label' => 'Diagonal 1', 'dimension_b_label' => 'Diagonal 2'],
            ['value' => 'triangle', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'triangle', 'family_label' => 'Segitiga', 'label' => 'Umum', 'dimension_a_label' => 'Alas', 'dimension_b_label' => 'Tinggi'],
            ['value' => 'triangle_right', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'triangle', 'family_label' => 'Segitiga', 'label' => 'Siku-siku', 'dimension_a_label' => 'Alas', 'dimension_b_label' => 'Tinggi'],
            ['value' => 'triangle_isosceles', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'triangle', 'family_label' => 'Segitiga', 'label' => 'Sama kaki', 'dimension_a_label' => 'Alas', 'dimension_b_label' => 'Tinggi'],
            ['value' => 'triangle_equilateral', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'triangle', 'family_label' => 'Segitiga', 'label' => 'Sama sisi', 'dimension_a_label' => 'Panjang sisi'],
            ['value' => 'triangle_scalene', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'triangle', 'family_label' => 'Segitiga', 'label' => 'Sembarang', 'dimension_a_label' => 'Alas', 'dimension_b_label' => 'Tinggi'],
            ['value' => 'triangle_acute', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'triangle', 'family_label' => 'Segitiga', 'label' => 'Lancip', 'dimension_a_label' => 'Alas', 'dimension_b_label' => 'Tinggi'],
            ['value' => 'triangle_obtuse', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'triangle', 'family_label' => 'Segitiga', 'label' => 'Tumpul', 'dimension_a_label' => 'Alas', 'dimension_b_label' => 'Tinggi'],
            ['value' => 'circle', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'circle', 'family_label' => 'Lingkaran', 'label' => 'Jari-jari', 'dimension_a_label' => 'Jari-jari'],
            ['value' => 'circle_diameter', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'circle', 'family_label' => 'Lingkaran', 'label' => 'Diameter', 'dimension_a_label' => 'Diameter'],
            ['value' => 'semicircle', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'circle', 'family_label' => 'Lingkaran', 'label' => 'Setengah lingkaran', 'dimension_a_label' => 'Diameter'],
            ['value' => 'quarter_circle', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'circle', 'family_label' => 'Lingkaran', 'label' => 'Seperempat lingkaran', 'dimension_a_label' => 'Jari-jari'],
            ['value' => 'circle_sector', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'circle', 'family_label' => 'Lingkaran', 'label' => 'Juring', 'dimension_a_label' => 'Jari-jari', 'dimension_b_label' => 'Sudut pusat (derajat)'],
            ['value' => 'annulus', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'circle', 'family_label' => 'Lingkaran', 'label' => 'Cincin lingkaran', 'dimension_a_label' => 'Jari-jari luar', 'dimension_b_label' => 'Jari-jari dalam'],
            ['value' => 'pentagon', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'polygon', 'family_label' => 'Segi banyak beraturan', 'label' => 'Segi lima', 'dimension_a_label' => 'Panjang sisi'],
            ['value' => 'hexagon', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'polygon', 'family_label' => 'Segi banyak beraturan', 'label' => 'Segi enam', 'dimension_a_label' => 'Panjang sisi'],
            ['value' => 'heptagon', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'polygon', 'family_label' => 'Segi banyak beraturan', 'label' => 'Segi tujuh', 'dimension_a_label' => 'Panjang sisi'],
            ['value' => 'octagon', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'polygon', 'family_label' => 'Segi banyak beraturan', 'label' => 'Segi delapan', 'dimension_a_label' => 'Panjang sisi'],
            ['value' => 'nonagon', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'polygon', 'family_label' => 'Segi banyak beraturan', 'label' => 'Segi sembilan', 'dimension_a_label' => 'Panjang sisi'],
            ['value' => 'decagon', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'polygon', 'family_label' => 'Segi banyak beraturan', 'label' => 'Segi sepuluh', 'dimension_a_label' => 'Panjang sisi'],
            ['value' => 'dodecagon', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'polygon', 'family_label' => 'Segi banyak beraturan', 'label' => 'Segi dua belas', 'dimension_a_label' => 'Panjang sisi'],
            ['value' => 'cube', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'box', 'family_label' => 'Kubus & balok', 'label' => 'Kubus', 'dimension_a_label' => 'Panjang rusuk'],
            ['value' => 'cuboid', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'box', 'family_label' => 'Kubus & balok', 'label' => 'Balok', 'dimension_a_label' => 'Panjang', 'dimension_b_label' => 'Lebar', 'dimension_c_label' => 'Tinggi'],
            ['value' => 'triangular_prism', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'prism', 'family_label' => 'Prisma', 'label' => 'Prisma segitiga', 'dimension_a_label' => 'Alas segitiga', 'dimension_b_label' => 'Tinggi segitiga', 'dimension_c_label' => 'Panjang prisma'],
            ['value' => 'pentagonal_prism', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'prism', 'family_label' => 'Prisma', 'label' => 'Prisma segi lima', 'dimension_a_label' => 'Sisi alas', 'dimension_b_label' => 'Apotema alas', 'dimension_c_label' => 'Panjang prisma'],
            ['value' => 'hexagonal_prism', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'prism', 'family_label' => 'Prisma', 'label' => 'Prisma segi enam', 'dimension_a_label' => 'Sisi alas', 'dimension_b_label' => 'Apotema alas', 'dimension_c_label' => 'Panjang prisma'],
            ['value' => 'triangular_pyramid', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'pyramid', 'family_label' => 'Limas', 'label' => 'Limas segitiga', 'dimension_a_label' => 'Sisi alas', 'dimension_b_label' => 'Tinggi limas'],
            ['value' => 'square_pyramid', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'pyramid', 'family_label' => 'Limas', 'label' => 'Limas segi empat', 'dimension_a_label' => 'Sisi alas', 'dimension_b_label' => 'Tinggi limas'],
            ['value' => 'pentagonal_pyramid', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'pyramid', 'family_label' => 'Limas', 'label' => 'Limas segi lima', 'dimension_a_label' => 'Sisi alas', 'dimension_b_label' => 'Tinggi limas'],
            ['value' => 'hexagonal_pyramid', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'pyramid', 'family_label' => 'Limas', 'label' => 'Limas segi enam', 'dimension_a_label' => 'Sisi alas', 'dimension_b_label' => 'Tinggi limas'],
            ['value' => 'cylinder', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'round', 'family_label' => 'Sisi lengkung', 'label' => 'Tabung', 'dimension_a_label' => 'Jari-jari', 'dimension_b_label' => 'Tinggi'],
            ['value' => 'cone', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'round', 'family_label' => 'Sisi lengkung', 'label' => 'Kerucut', 'dimension_a_label' => 'Jari-jari', 'dimension_b_label' => 'Tinggi'],
            ['value' => 'sphere', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'round', 'family_label' => 'Sisi lengkung', 'label' => 'Bola', 'dimension_a_label' => 'Jari-jari'],
            ['value' => 'hemisphere', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'round', 'family_label' => 'Sisi lengkung', 'label' => 'Setengah bola', 'dimension_a_label' => 'Jari-jari'],
            ['value' => 'parallel_lines', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'lines', 'family_label' => 'Garis & kedudukan', 'label' => 'Garis sejajar', 'dimension_a_label' => 'Jarak antar garis'],
            ['value' => 'perpendicular_lines', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'lines', 'family_label' => 'Garis & kedudukan', 'label' => 'Garis tegak lurus', 'dimension_a_label' => 'Panjang acuan'],
            ['value' => 'intersecting_lines', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'lines', 'family_label' => 'Garis & kedudukan', 'label' => 'Garis berpotongan', 'dimension_a_label' => 'Besar sudut', 'uses_unit' => false],
            ['value' => 'angle_acute', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'angles', 'family_label' => 'Sudut', 'label' => 'Sudut lancip', 'dimension_a_label' => 'Besar sudut', 'uses_unit' => false],
            ['value' => 'angle_right', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'angles', 'family_label' => 'Sudut', 'label' => 'Sudut siku-siku', 'dimension_a_label' => 'Besar sudut', 'uses_unit' => false],
            ['value' => 'angle_obtuse', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'angles', 'family_label' => 'Sudut', 'label' => 'Sudut tumpul', 'dimension_a_label' => 'Besar sudut', 'uses_unit' => false],
            ['value' => 'angle_straight', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'angles', 'family_label' => 'Sudut', 'label' => 'Sudut lurus', 'dimension_a_label' => 'Besar sudut', 'uses_unit' => false],
            ['value' => 'angle_reflex', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'angles', 'family_label' => 'Sudut', 'label' => 'Sudut refleks', 'dimension_a_label' => 'Besar sudut', 'uses_unit' => false],
            ['value' => 'circle_chord', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'circle', 'family_label' => 'Lingkaran', 'label' => 'Tali busur', 'dimension_a_label' => 'Jari-jari', 'dimension_b_label' => 'Panjang tali busur'],
            ['value' => 'circle_segment', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'circle', 'family_label' => 'Lingkaran', 'label' => 'Tembereng', 'dimension_a_label' => 'Jari-jari'],
            ['value' => 'circle_tangent', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'circle', 'family_label' => 'Lingkaran', 'label' => 'Garis singgung', 'dimension_a_label' => 'Jari-jari', 'dimension_b_label' => 'Panjang garis singgung'],
            ['value' => 'composite_square_semicircle', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'square', 'subfamily_label' => 'Persegi', 'label' => 'Gabung ½ lingkaran', 'dimension_a_label' => 'Sisi persegi / diameter'],
            ['value' => 'composite_square_quarter_circle', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'square', 'subfamily_label' => 'Persegi', 'label' => 'Arsir di luar ¼ lingkaran', 'dimension_a_label' => 'Sisi persegi', 'dimension_b_label' => 'Jari-jari ¼ lingkaran'],
            ['value' => 'composite_square_four_quarters', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'square', 'subfamily_label' => 'Persegi', 'label' => 'Arsir di luar empat ¼ lingkaran', 'dimension_a_label' => 'Sisi persegi'],
            ['value' => 'shaded_square_diagonal', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'square', 'subfamily_label' => 'Persegi', 'label' => 'Arsiran diagonal ½ bagian', 'dimension_a_label' => 'Sisi persegi'],
            ['value' => 'shaded_square_circle', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'square', 'subfamily_label' => 'Persegi', 'label' => 'Arsir di luar lingkaran', 'dimension_a_label' => 'Sisi persegi', 'dimension_b_label' => 'Jari-jari lingkaran'],
            ['value' => 'composite_rectangle_semicircle', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'rectangle', 'subfamily_label' => 'Persegi panjang', 'label' => 'Gabung ½ lingkaran', 'dimension_a_label' => 'Panjang / diameter', 'dimension_b_label' => 'Tinggi persegi panjang'],
            ['value' => 'composite_stadium', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'rectangle', 'subfamily_label' => 'Persegi panjang', 'label' => 'Gabung dua ½ lingkaran', 'dimension_a_label' => 'Panjang bagian lurus', 'dimension_b_label' => 'Diameter'],
            ['value' => 'composite_rectangle_two_quarters', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'rectangle', 'subfamily_label' => 'Persegi panjang', 'label' => 'Arsir di luar dua ¼ lingkaran', 'dimension_a_label' => 'Panjang', 'dimension_b_label' => 'Lebar / diameter'],
            ['value' => 'composite_l_shape', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'rectangle', 'subfamily_label' => 'Persegi panjang', 'label' => 'Lekukan persegi (bentuk L)', 'dimension_a_label' => 'Panjang total', 'dimension_b_label' => 'Tinggi total', 'dimension_c_label' => 'Ukuran lekukan'],
            ['value' => 'shaded_rectangle_circle', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'rectangle', 'subfamily_label' => 'Persegi panjang', 'label' => 'Arsir di luar lingkaran', 'dimension_a_label' => 'Panjang', 'dimension_b_label' => 'Lebar', 'dimension_c_label' => 'Jari-jari lingkaran'],
            ['value' => 'composite_triangle_semicircle', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'triangle', 'subfamily_label' => 'Segitiga', 'label' => 'Gabung ½ lingkaran', 'dimension_a_label' => 'Alas / diameter', 'dimension_b_label' => 'Tinggi segitiga'],
            ['value' => 'composite_triangle_rectangle', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'triangle', 'subfamily_label' => 'Segitiga', 'label' => 'Gabung persegi panjang', 'dimension_a_label' => 'Lebar bersama', 'dimension_b_label' => 'Tinggi persegi panjang', 'dimension_c_label' => 'Tinggi segitiga'],
            ['value' => 'shaded_triangle_midsegment', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'triangle', 'subfamily_label' => 'Segitiga', 'label' => 'Arsiran segitiga tengah ¼ bagian', 'dimension_a_label' => 'Alas segitiga besar', 'dimension_b_label' => 'Tinggi segitiga besar'],
            ['value' => 'shaded_circle_square', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'circle', 'subfamily_label' => 'Lingkaran', 'label' => 'Arsir di luar persegi dalam', 'dimension_a_label' => 'Jari-jari lingkaran'],
            ['value' => 'shaded_circle_sector', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'circle', 'subfamily_label' => 'Lingkaran', 'label' => 'Arsiran juring', 'dimension_a_label' => 'Jari-jari', 'dimension_b_label' => 'Sudut pusat (derajat)'],
            ['value' => 'shaded_annulus', 'category' => '2d', 'category_label' => 'Bangun datar (2D)', 'family' => 'composite', 'family_label' => 'Gabungan & arsiran', 'subfamily' => 'circle', 'subfamily_label' => 'Lingkaran', 'label' => 'Arsiran cincin', 'dimension_a_label' => 'Jari-jari luar', 'dimension_b_label' => 'Jari-jari dalam'],
            ['value' => 'fraction_circle', 'category' => 'fraction', 'category_label' => 'Pecahan & perbandingan', 'family' => 'fraction_model', 'family_label' => 'Model pecahan', 'label' => 'Satu lingkaran pecahan', 'dimension_a_label' => 'Pembilang', 'dimension_b_label' => 'Penyebut', 'uses_unit' => false, 'integer_dimensions' => true],
            ['value' => 'fraction_bar', 'category' => 'fraction', 'category_label' => 'Pecahan & perbandingan', 'family' => 'fraction_model', 'family_label' => 'Model pecahan', 'label' => 'Satu batang pecahan', 'dimension_a_label' => 'Pembilang', 'dimension_b_label' => 'Penyebut', 'uses_unit' => false, 'integer_dimensions' => true],
            ['value' => 'fraction_equivalent_circles', 'category' => 'fraction', 'category_label' => 'Pecahan & perbandingan', 'family' => 'fraction_reasoning', 'family_label' => 'Penalaran pecahan', 'label' => 'Perbandingan 2–4 lingkaran', 'dimension_a_label' => 'Pembilang lingkaran pertama', 'dimension_b_label' => 'Penyebut lingkaran pertama', 'dimension_c_label' => 'Jumlah lingkaran', 'uses_unit' => false, 'integer_dimensions' => true, 'custom_fraction_models' => true],
            ['value' => 'fraction_equivalent_bars', 'category' => 'fraction', 'category_label' => 'Pecahan & perbandingan', 'family' => 'fraction_reasoning', 'family_label' => 'Penalaran pecahan', 'label' => 'Tiga batang pecahan senilai', 'dimension_a_label' => 'Pembilang dasar', 'dimension_b_label' => 'Penyebut dasar', 'dimension_c_label' => 'Faktor model ketiga (2–4)', 'uses_unit' => false, 'integer_dimensions' => true],
            ['value' => 'cube_net', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'nets', 'family_label' => 'Jaring-jaring', 'label' => 'Jaring-jaring kubus', 'dimension_a_label' => 'Panjang rusuk'],
            ['value' => 'cuboid_net', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'nets', 'family_label' => 'Jaring-jaring', 'label' => 'Jaring-jaring balok', 'dimension_a_label' => 'Panjang', 'dimension_b_label' => 'Lebar', 'dimension_c_label' => 'Tinggi'],
            ['value' => 'triangular_prism_net', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'nets', 'family_label' => 'Jaring-jaring', 'label' => 'Jaring-jaring prisma segitiga', 'dimension_a_label' => 'Alas segitiga', 'dimension_b_label' => 'Tinggi segitiga', 'dimension_c_label' => 'Panjang prisma'],
            ['value' => 'cylinder_net', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'nets', 'family_label' => 'Jaring-jaring', 'label' => 'Jaring-jaring tabung', 'dimension_a_label' => 'Jari-jari', 'dimension_b_label' => 'Tinggi tabung'],
            ['value' => 'cone_net', 'category' => '3d', 'category_label' => 'Bangun ruang (3D)', 'family' => 'nets', 'family_label' => 'Jaring-jaring', 'label' => 'Jaring-jaring kerucut', 'dimension_a_label' => 'Jari-jari alas', 'dimension_b_label' => 'Garis pelukis'],
            ['value' => 'cartesian_point', 'category' => 'coordinate', 'category_label' => 'Koordinat & transformasi', 'family' => 'cartesian', 'family_label' => 'Koordinat Kartesius', 'label' => 'Titik koordinat', 'dimension_a_label' => 'Koordinat x', 'dimension_b_label' => 'Koordinat y', 'uses_unit' => false, 'allow_signed_dimensions' => true],
            ['value' => 'cartesian_line', 'category' => 'coordinate', 'category_label' => 'Koordinat & transformasi', 'family' => 'cartesian', 'family_label' => 'Koordinat Kartesius', 'label' => 'Persamaan garis', 'dimension_a_label' => 'Gradien (m)', 'dimension_b_label' => 'Konstanta (c)', 'uses_unit' => false, 'allow_signed_dimensions' => true],
            ['value' => 'translation', 'category' => 'coordinate', 'category_label' => 'Koordinat & transformasi', 'family' => 'transform', 'family_label' => 'Transformasi', 'label' => 'Translasi', 'dimension_a_label' => 'Geser x', 'dimension_b_label' => 'Geser y', 'uses_unit' => false, 'allow_signed_dimensions' => true],
            ['value' => 'reflection', 'category' => 'coordinate', 'category_label' => 'Koordinat & transformasi', 'family' => 'transform', 'family_label' => 'Transformasi', 'label' => 'Refleksi terhadap x = a', 'dimension_a_label' => 'Nilai a', 'uses_unit' => false, 'allow_signed_dimensions' => true],
            ['value' => 'rotation', 'category' => 'coordinate', 'category_label' => 'Koordinat & transformasi', 'family' => 'transform', 'family_label' => 'Transformasi', 'label' => 'Rotasi', 'dimension_a_label' => 'Sudut rotasi', 'uses_unit' => false],
            ['value' => 'dilation', 'category' => 'coordinate', 'category_label' => 'Koordinat & transformasi', 'family' => 'transform', 'family_label' => 'Transformasi', 'label' => 'Dilatasi', 'dimension_a_label' => 'Faktor skala', 'uses_unit' => false],
            ['value' => 'ruler', 'category' => 'measurement', 'category_label' => 'Pengukuran', 'family' => 'length', 'family_label' => 'Panjang & skala', 'label' => 'Penggaris', 'dimension_a_label' => 'Panjang benda'],
            ['value' => 'number_line', 'category' => 'measurement', 'category_label' => 'Pengukuran', 'family' => 'length', 'family_label' => 'Panjang & skala', 'label' => 'Garis bilangan', 'dimension_a_label' => 'Nilai minimum', 'dimension_b_label' => 'Nilai maksimum', 'uses_unit' => false, 'allow_signed_dimensions' => true],
            ['value' => 'scale_bar', 'category' => 'measurement', 'category_label' => 'Pengukuran', 'family' => 'length', 'family_label' => 'Panjang & skala', 'label' => 'Skala batang', 'dimension_a_label' => 'Nilai total', 'dimension_b_label' => 'Jumlah interval'],
            ['value' => 'clock', 'category' => 'measurement', 'category_label' => 'Pengukuran', 'family' => 'time_angle', 'family_label' => 'Waktu & sudut', 'label' => 'Jam analog', 'dimension_a_label' => 'Jam (0–23)', 'dimension_b_label' => 'Menit (0–59)', 'uses_unit' => false, 'allow_signed_dimensions' => true],
            ['value' => 'protractor', 'category' => 'measurement', 'category_label' => 'Pengukuran', 'family' => 'time_angle', 'family_label' => 'Waktu & sudut', 'label' => 'Busur derajat', 'dimension_a_label' => 'Besar sudut', 'uses_unit' => false],
        ];
    }

    private function syncOptions(Question $question, array $options): void
    {
        $question->options()->delete();
        foreach ($options as $index => $option) {
            $question->options()->create([
                'label' => chr(65 + $index),
                'content' => $option['content'],
                'is_correct' => $option['is_correct'],
                'position' => $index + 1,
            ]);
        }
    }

    private function competencies(Request $request)
    {
        return Competency::query()
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->orderBy('grade_level')
            ->orderBy('domain')
            ->orderBy('name')
            ->get(['id', 'subject_id', 'parent_id', 'code', 'domain', 'name', 'grade_level']);
    }

    private function subjects(Request $request)
    {
        return Subject::query()
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'ai_question_format']);
    }

    private function questionBlueprints(Request $request)
    {
        return QuestionBlueprint::query()
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->with('competencies:id')
            ->orderBy('name')
            ->get(['id', 'subject_id', 'code', 'name'])
            ->map(fn (QuestionBlueprint $blueprint): array => [
                ...$blueprint->only(['id', 'subject_id', 'code', 'name']),
                'competency_ids' => $blueprint->competencies->pluck('id'),
            ]);
    }

    private function assessmentsForQuestion(Request $request): Collection
    {
        return Assessment::query()
            ->where('school_id', $request->user()->school_id)
            ->whereIn('status', [AssessmentStatus::Draft, AssessmentStatus::Published])
            ->with(['questions:id,competency_id', 'questions.competency:id,parent_id,code,name'])
            ->oldest() // oldest first — so frontend can suggest the earliest package that still needs coverage
            ->get(['id', 'title', 'grade_level', 'subject_id', 'created_at'])
            ->map(fn (Assessment $a): array => [
                'id' => $a->id,
                'title' => $a->title,
                'grade_level' => $a->grade_level,
                'subject_id' => $a->subject_id,
                'created_at' => $a->created_at,
                // competency_id (sub-competency) => count of questions already in this assessment
                'competency_coverage' => $a->questions
                    ->pluck('competency')
                    ->filter()
                    ->filter(fn ($c) => $c->parent_id !== null)
                    ->groupBy('id')
                    ->map->count()
                    ->toArray(),
            ]);
    }

    private function attachToAssessment(Request $request, Question $question): void
    {
        $assessmentId = (int) ($request->input('target_assessment_id') ?? 0);
        if ($assessmentId === 0) {
            return;
        }

        $assessment = Assessment::query()
            ->where('school_id', $request->user()->school_id)
            ->whereIn('status', [AssessmentStatus::Draft, AssessmentStatus::Published])
            ->find($assessmentId);

        if (! $assessment) {
            return;
        }

        // Only attach if not already in the assessment
        if (! $assessment->questions()->whereKey($question->id)->exists()) {
            $nextPosition = $assessment->questions()->count() + 1;
            $assessment->questions()->attach($question->id, [
                'position' => $nextPosition,
                'points' => 1,
                'snapshot' => null,
            ]);
        }
    }

    private function ensureSameSchool(Request $request, Question $question): void
    {
        abort_unless($question->school_id === $request->user()->school_id, 404);
    }
}
