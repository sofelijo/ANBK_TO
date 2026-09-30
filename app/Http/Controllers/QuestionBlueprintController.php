<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Competency;
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

class QuestionBlueprintController extends Controller
{
    public function index(Request $request): Response
    {
        $schoolId = $request->user()->school_id;
        $allBlueprints = QuestionBlueprint::query()
            ->when(
                ! $request->user()->hasRole(UserRole::Admin),
                fn ($query) => $query->where(fn ($scope) => $scope->whereNull('school_id')->orWhere('school_id', $schoolId)),
            )
            ->whereHas('subject', fn ($query) => $query->where('code', 'BIND'))
            ->with(['subject:id,code,name', 'competencies:id,code,name'])
            ->withCount('questions')
            ->orderBy('name')
            ->get();

        $schoolCodes = $request->user()->hasRole(UserRole::Admin)
            ? []
            : $allBlueprints->where('school_id', $schoolId)->pluck('code')->all();

        $blueprints = $allBlueprints
            ->reject(fn (QuestionBlueprint $blueprint): bool => $blueprint->school_id === null && in_array($blueprint->code, $schoolCodes, true))
            ->values()
            ->map(fn (QuestionBlueprint $blueprint): array => [
                ...$blueprint->only(['id', 'code', 'name', 'description']),
                'subject' => $blueprint->subject,
                'competencies' => $blueprint->competencies,
                'questions_count' => $blueprint->questions_count,
                'can_manage' => $request->user()->hasRole(UserRole::Admin) || $blueprint->school_id === $schoolId,
                'is_global' => $blueprint->school_id === null,
            ]);

        return Inertia::render('QuestionBlueprints/Index', ['blueprints' => $blueprints]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $this->validatedData($request);
        $competencyIds = $data['competency_ids'];
        unset($data['competency_ids']);

        $blueprint = QuestionBlueprint::create([
            ...$data,
            'school_id' => $request->user()->hasRole(UserRole::Admin) ? null : $request->user()->school_id,
        ]);
        $this->syncCompetencies($blueprint, $competencyIds);
        $auditLogger->log($request, 'question_blueprint.created', $blueprint);

        return to_route('question-types.index')->with('success', 'Tipe soal berhasil ditambahkan.');
    }

    public function edit(Request $request, QuestionBlueprint $questionType): Response
    {
        $this->ensureAccess($request, $questionType);

        return $this->form($request, $questionType);
    }

    public function update(Request $request, QuestionBlueprint $questionType, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureAccess($request, $questionType);
        $schoolId = $request->user()->school_id;

        $data = $this->validatedData($request, $questionType);
        $competencyIds = $data['competency_ids'];
        unset($data['competency_ids']);

        if ($questionType->school_id === null && ! $request->user()->hasRole(UserRole::Admin)) {
            $target = QuestionBlueprint::query()
                ->where('school_id', $schoolId)
                ->where('subject_id', $data['subject_id'])
                ->where('code', $data['code'])
                ->first();

            if (! $target) {
                $target = QuestionBlueprint::create([
                    ...$data,
                    'school_id' => $schoolId,
                ]);
            } else {
                $target->update($data);
            }

            $this->syncCompetencies($target, $competencyIds);
            $auditLogger->log($request, 'question_blueprint.customized', $target, [
                'original_blueprint_id' => $questionType->id,
            ]);

            return to_route('question-types.index')->with('success', 'Tipe soal berhasil disesuaikan untuk sekolah Anda.');
        }

        $questionType->update($data);
        $this->syncCompetencies($questionType, $competencyIds);
        $auditLogger->log($request, 'question_blueprint.updated', $questionType);

        return to_route('question-types.index')->with('success', 'Tipe soal berhasil diperbarui.');
    }

    public function destroy(Request $request, QuestionBlueprint $questionType, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureManageable($request, $questionType);
        if ($questionType->questions()->exists()) {
            return back()->with('error', 'Tipe soal tidak dapat dihapus karena sudah digunakan oleh soal.');
        }

        $auditLogger->log($request, 'question_blueprint.deleted', $questionType);
        $questionType->delete();

        return to_route('question-types.index')->with('success', 'Tipe soal berhasil dihapus.');
    }

    private function form(Request $request, ?QuestionBlueprint $blueprint = null): Response
    {
        $subjects = $this->subjects($request);
        $subjectIds = $subjects->pluck('id');

        return Inertia::render('QuestionBlueprints/Form', [
            'blueprint' => $blueprint ? [
                ...$blueprint->only(['id', 'subject_id', 'code', 'name', 'description']),
                'is_global' => $blueprint->school_id === null,
                'competency_ids' => $blueprint->competencies()->pluck('competencies.id'),
            ] : null,
            'subjects' => $subjects,
            'competencies' => Competency::query()
                ->whereIn('subject_id', $subjectIds)
                ->whereNull('parent_id')
                ->when(
                    ! $request->user()->hasRole(UserRole::Admin),
                    fn ($query) => $query->where(fn ($scope) => $scope->whereNull('school_id')->orWhere('school_id', $request->user()->school_id)),
                )
                ->orderBy('grade_level')
                ->orderBy('name')
                ->get(['id', 'subject_id', 'code', 'name', 'grade_level']),
        ]);
    }

    private function validatedData(Request $request, ?QuestionBlueprint $blueprint = null): array
    {
        $schoolId = $request->user()->hasRole(UserRole::Admin) ? null : $request->user()->school_id;
        $request->merge([
            'code' => Str::upper(trim($request->string('code')->toString())),
            'name' => Str::squish($request->string('name')->toString()),
        ]);

        $existingSchoolBlueprint = ! $request->user()->hasRole(UserRole::Admin) && $blueprint && $blueprint->school_id === null
            ? QuestionBlueprint::query()
                ->where('school_id', $schoolId)
                ->where('subject_id', $request->integer('subject_id'))
                ->where('code', $request->string('code')->toString())
                ->first()
            : null;

        $ignoreId = $blueprint?->school_id === $schoolId
            ? $blueprint->id
            : $existingSchoolBlueprint?->id;

        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('question_blueprints', 'code')
                    ->where('school_id', $schoolId)
                    ->where('subject_id', $request->integer('subject_id'))
                    ->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'competency_ids' => ['array'],
            'competency_ids.*' => ['integer', 'distinct'],
        ]);

        $subject = $this->subjects($request)->firstWhere('id', (int) $data['subject_id']);
        if (! $subject) {
            throw ValidationException::withMessages(['subject_id' => 'Tipe soal saat ini khusus untuk Bahasa Indonesia.']);
        }

        $validCompetencyCount = Competency::query()
            ->whereIn('id', $data['competency_ids'] ?? [])
            ->where('subject_id', $subject->id)
            ->whereNull('parent_id')
            ->when(
                ! $request->user()->hasRole(UserRole::Admin),
                fn ($query) => $query->where(fn ($scope) => $scope->whereNull('school_id')->orWhere('school_id', $request->user()->school_id)),
            )
            ->count();
        if ($validCompetencyCount !== count($data['competency_ids'] ?? [])) {
            throw ValidationException::withMessages(['competency_ids' => 'Ada kompetensi yang tidak tersedia.']);
        }

        return $data;
    }

    private function syncCompetencies(QuestionBlueprint $blueprint, array $competencyIds): void
    {
        $blueprint->competencies()->sync(
            collect($competencyIds)->values()->mapWithKeys(fn (int $id, int $index): array => [
                $id => ['position' => $index + 1],
            ])->all(),
        );
    }

    private function subjects(Request $request)
    {
        return Subject::query()
            ->where('code', 'BIND')
            ->when(
                ! $request->user()->hasRole(UserRole::Admin),
                fn ($query) => $query->where(fn ($scope) => $scope->whereNull('school_id')->orWhere('school_id', $request->user()->school_id)),
            )
            ->orderByDesc('school_id')
            ->get(['id', 'code', 'name']);
    }

    private function ensureAccess(Request $request, QuestionBlueprint $blueprint): void
    {
        abort_unless(
            $request->user()->hasRole(UserRole::Admin)
            || $blueprint->school_id === null
            || $blueprint->school_id === $request->user()->school_id,
            404,
        );
    }

    private function ensureManageable(Request $request, QuestionBlueprint $blueprint): void
    {
        abort_unless(
            $request->user()->hasRole(UserRole::Admin) || $blueprint->school_id === $request->user()->school_id,
            404,
        );
    }
}
