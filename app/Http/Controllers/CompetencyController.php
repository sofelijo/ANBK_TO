<?php

namespace App\Http\Controllers;

use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Recommendation;
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
            ->with(['parent:id,code,name'])
            ->withCount(['questions', 'children'])
            ->orderBy('grade_level')
            ->orderBy('domain')
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
                'parent' => $competency->parent,
                'questions_count' => $competency->questions_count,
                'children_count' => $competency->children_count,
                'can_manage' => $competency->school_id === $schoolId,
            ]),
            'filters' => $request->only(['search', 'grade_level']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Competencies/Form', [
            'competency' => null,
            'parents' => $this->parentOptions($request),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $this->validatedData($request);
        $this->ensureValidParent($request, $data);

        $competency = Competency::create([
            'school_id' => $request->user()->school_id,
            ...$data,
        ]);
        $auditLogger->log($request, 'competency.created', $competency);

        return to_route('competencies.index')->with('success', 'Kompetensi berhasil ditambahkan.');
    }

    public function edit(Request $request, Competency $competency): Response
    {
        $this->ensureManageable($request, $competency);

        return Inertia::render('Competencies/Form', [
            'competency' => $competency,
            'parents' => $this->parentOptions($request, $competency),
        ]);
    }

    public function update(Request $request, Competency $competency, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureManageable($request, $competency);
        $data = $this->validatedData($request, $competency);
        $this->ensureValidParent($request, $data, $competency);

        $competency->update($data);
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
        $request->merge([
            'code' => Str::upper(trim($request->string('code')->toString())),
            'domain' => Str::squish($request->string('domain')->toString()),
            'name' => Str::squish($request->string('name')->toString()),
        ]);

        return $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('competencies', 'code')
                    ->where('school_id', $request->user()->school_id)
                    ->ignore($competency),
            ],
            'domain' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'grade_level' => ['required', 'integer', Rule::in([5, 8, 11])],
            'parent_id' => ['nullable', 'integer'],
        ], [
            'code.regex' => 'Kode hanya boleh berisi huruf kapital, angka, titik, garis bawah, dan tanda hubung.',
        ]);
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
            ->orderBy('grade_level')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'grade_level']);
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
}
