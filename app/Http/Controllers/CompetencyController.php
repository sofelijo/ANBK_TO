<?php

namespace App\Http\Controllers;

use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Recommendation;
use App\Models\QuestionBlueprint;
use App\Models\Subject;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CompetencyController extends Controller
{
    public function index(Request $request): Response
    {
        $schoolId = $request->user()->school_id;
        $competencies = Competency::query()
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $schoolId))
            ->when($request->string('search')->toString(), function ($query, string $search) {
                $query->where(fn ($nested) => $nested
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%"));
            })
            ->when($request->integer('grade_level'), fn ($query, int $grade) => $query->where('grade_level', $grade))
            ->when($request->integer('subject_id'), fn ($query, int $subjectId) => $query->where('subject_id', $subjectId))
            ->with(['parent:id,code,name', 'subject:id,code,name'])
            ->withCount([
                'questions',
                'children',
                'subcompetencyQuestions' => fn ($query) => $query->where('questions.school_id', $schoolId),
            ])
            ->orderBy('grade_level')
            ->orderByRaw('COALESCE(parent_id, id)')
            ->orderBy('parent_id')
            ->orderBy('code')
            ->get();

        return Inertia::render('Competencies/Index', [
            'competencies' => $competencies->map(fn (Competency $competency): array => [
                'id' => $competency->id,
                'code' => $competency->code,
                'domain' => $competency->domain,
                'name' => $competency->name,
                'description' => $competency->description,
                'grade_level' => $competency->grade_level,
                'subject' => $competency->subject,
                'parent' => $competency->parent,
                'questions_count' => $competency->parent_id === null
                    ? $competency->subcompetency_questions_count
                    : $competency->questions_count,
                'children_count' => $competency->children_count,
                'can_manage' => $competency->school_id === $schoolId,
            ]),
            'subjects' => $this->subjects($request),
            'filters' => $request->only(['search', 'grade_level', 'subject_id']),
        ]);
    }

    public function create(Request $request): Response
    {
        $parents = $this->parentOptions($request);
        $requestedParentId = $request->integer('parent_id') ?: null;
        $parentId = $requestedParentId && $parents->contains('id', $requestedParentId)
            ? $requestedParentId
            : null;

        return Inertia::render('Competencies/Form', [
            'competency' => null,
            'defaultParentId' => $parentId,
            'parents' => $parents,
            'subjects' => $this->subjects($request),
            'questionBlueprints' => $this->questionBlueprints($request),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $this->validatedData($request);
        $this->ensureValidParent($request, $data);
        $questionBlueprintIds = $data['question_blueprint_ids'];
        unset($data['question_blueprint_ids']);

        $competency = Competency::create([
            'school_id' => $request->user()->school_id,
            ...$data,
        ]);
        $this->syncQuestionBlueprints($competency, $questionBlueprintIds);
        $auditLogger->log($request, 'competency.created', $competency);

        return to_route('competencies.index')->with('success', 'Kompetensi berhasil ditambahkan.');
    }

    public function edit(Request $request, Competency $competency): Response
    {
        $this->ensureManageable($request, $competency);

        return Inertia::render('Competencies/Form', [
            'competency' => [
                ...$competency->toArray(),
                'question_blueprint_ids' => $competency->questionBlueprints()->pluck('question_blueprints.id'),
            ],
            'defaultParentId' => null,
            'parents' => $this->parentOptions($request, $competency),
            'subjects' => $this->subjects($request),
            'questionBlueprints' => $this->questionBlueprints($request),
        ]);
    }

    public function update(Request $request, Competency $competency, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureManageable($request, $competency);
        $data = $this->validatedData($request, $competency);
        $this->ensureValidParent($request, $data, $competency);
        $questionBlueprintIds = $data['question_blueprint_ids'];
        unset($data['question_blueprint_ids']);

        $competency->update($data);
        $this->syncQuestionBlueprints($competency, $questionBlueprintIds);
        $auditLogger->log($request, 'competency.updated', $competency);

        return to_route('competencies.index')->with('success', 'Kompetensi berhasil diperbarui.');
    }

    public function destroy(Request $request, Competency $competency, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureManageable($request, $competency);

        $isUsed = $competency->questions()->exists()
            || $competency->children()->exists()
            || CompetencyResult::query()->where('competency_id', $competency->id)->exists()
            || Recommendation::query()->where('competency_id', $competency->id)->exists();

        if ($isUsed) {
            return back()->with('error', 'Kompetensi tidak dapat dihapus karena sudah digunakan atau memiliki kompetensi turunan.');
        }

        $auditLogger->log($request, 'competency.deleted', $competency, [
            'code' => $competency->code,
            'name' => $competency->name,
        ]);
        $competency->delete();

        return to_route('competencies.index')->with('success', 'Kompetensi berhasil dihapus.');
    }

    private function validatedData(Request $request, ?Competency $competency = null): array
    {
        $gradeLevel = $request->integer('grade_level');
        $normalizedGradeLevel = [5 => 6, 8 => 9, 11 => 12][$gradeLevel] ?? $gradeLevel;

        $rawCode = Str::upper(trim($request->string('code')->toString()));
        $rawName = Str::squish($request->string('name')->toString());

        // Auto-generate code from name if not provided
        if ($rawCode === '') {
            $rawCode = Str::upper(Str::slug(Str::words($rawName, 4, ''), '-'));
            if ($rawCode === '') {
                $rawCode = 'KOMP-' . Str::upper(Str::random(4));
            }
            // Ensure uniqueness by appending school-scoped counter
            $base = $rawCode;
            $suffix = 2;
            while (
                Competency::query()
                    ->where('school_id', $request->user()->school_id)
                    ->where('code', $rawCode)
                    ->when($competency, fn ($q) => $q->whereKeyNot($competency->id))
                    ->exists()
            ) {
                $rawCode = $base . '-' . $suffix++;
            }
        }

        $request->merge([
            'code' => $rawCode,
            'domain' => Str::squish($request->string('domain')->toString()),
            'name' => $rawName,
            'grade_level' => $normalizedGradeLevel,
        ]);

        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('competencies', 'code')
                    ->where('school_id', $request->user()->school_id)
                    ->ignore($competency),
            ],
            'domain' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'grade_level' => ['required', 'integer', Rule::in([6, 9, 12])],
            'parent_id' => ['nullable', 'integer'],
            'question_blueprint_ids' => ['array'],
            'question_blueprint_ids.*' => ['integer', 'distinct'],
        ], [
            'code.regex' => 'Kode hanya boleh berisi huruf kapital, angka, titik, garis bawah, dan tanda hubung.',
            'grade_level.in' => 'Pilih jenjang kelas 6, 9, atau 12.',
        ]);
        $data['question_blueprint_ids'] ??= [];

        $subjectExists = Subject::query()
            ->whereKey($data['subject_id'])
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->exists();

        if (! $subjectExists) {
            throw ValidationException::withMessages(['subject_id' => 'Mata pelajaran tidak tersedia.']);
        }

        $validBlueprintCount = QuestionBlueprint::query()
            ->whereIn('id', $data['question_blueprint_ids'] ?? [])
            ->where('subject_id', $data['subject_id'])
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->count();
        if ($validBlueprintCount !== count($data['question_blueprint_ids'] ?? [])) {
            throw ValidationException::withMessages([
                'question_blueprint_ids' => 'Ada tipe soal yang tidak tersedia untuk mata pelajaran ini.',
            ]);
        }

        return $data;
    }

    private function syncQuestionBlueprints(Competency $competency, array $blueprintIds): void
    {
        if ($competency->parent_id !== null || $competency->subject?->code !== 'BIND') {
            $competency->questionBlueprints()->detach();

            return;
        }

        $competency->questionBlueprints()->sync(
            collect($blueprintIds)->values()->mapWithKeys(fn (int $id, int $index): array => [
                $id => ['position' => $index + 1],
            ])->all(),
        );
    }

    private function questionBlueprints(Request $request)
    {
        return QuestionBlueprint::query()
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->whereHas('subject', fn ($query) => $query->where('code', 'BIND'))
            ->orderBy('name')
            ->get(['id', 'subject_id', 'code', 'name']);
    }

    private function ensureValidParent(Request $request, array $data, ?Competency $competency = null): void
    {
        if (! $data['parent_id']) {
            return;
        }

        $parent = Competency::query()
            ->whereKey($data['parent_id'])
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->first();

        if (! $parent) {
            throw ValidationException::withMessages(['parent_id' => 'Kompetensi induk tidak tersedia.']);
        }

        if ($parent->grade_level !== (int) $data['grade_level']) {
            throw ValidationException::withMessages(['parent_id' => 'Kompetensi induk harus berada pada jenjang kelas yang sama.']);
        }

        if ($parent->subject_id !== (int) $data['subject_id']) {
            throw ValidationException::withMessages(['parent_id' => 'Kompetensi induk harus berada pada mata pelajaran yang sama.']);
        }

        if ($parent->parent_id !== null) {
            throw ValidationException::withMessages(['parent_id' => 'Subkompetensi tidak dapat memiliki subkompetensi lagi.']);
        }

        if ($competency?->children()->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'Kompetensi yang sudah memiliki subkompetensi tidak dapat dijadikan subkompetensi.']);
        }

        if ($competency && ($parent->is($competency) || in_array($parent->id, $this->descendantIds($competency), true))) {
            throw ValidationException::withMessages(['parent_id' => 'Kompetensi tidak dapat menjadi induk bagi dirinya sendiri atau turunannya.']);
        }
    }

    private function parentOptions(Request $request, ?Competency $competency = null)
    {
        $excludedIds = $competency ? [$competency->id, ...$this->descendantIds($competency)] : [];

        return Competency::query()
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->when($excludedIds, fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->whereNull('parent_id')
            ->orderBy('grade_level')
            ->orderBy('code')
            ->get(['id', 'subject_id', 'code', 'name', 'grade_level']);
    }

    private function descendantIds(Competency $competency): array
    {
        $descendants = [];
        $parentIds = [$competency->id];

        while ($parentIds !== []) {
            $children = Competency::query()->whereIn('parent_id', $parentIds)->pluck('id')->all();
            $children = array_values(array_diff($children, $descendants));
            $descendants = [...$descendants, ...$children];
            $parentIds = $children;
        }

        return $descendants;
    }

    private function ensureManageable(Request $request, Competency $competency): void
    {
        abort_unless($competency->school_id === $request->user()->school_id, 404);
    }

    private function subjects(Request $request)
    {
        return Subject::query()
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }
}
