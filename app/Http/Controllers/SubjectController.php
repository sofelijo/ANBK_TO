<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Subject;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubjectController extends Controller
{
    public function index(Request $request): Response
    {
        $schoolId = $request->user()->school_id;
        $subjects = Subject::query()
            ->when(
                ! $request->user()->hasRole(UserRole::Admin),
                fn ($query) => $query->where(fn ($scope) => $scope->whereNull('school_id')->orWhere('school_id', $schoolId)),
            )
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(fn ($subject) => $subject
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%"));
            })
            ->withCount(['competencies', 'competencies as questions_count' => fn ($query) => $query
                ->join('questions', 'questions.competency_id', '=', 'competencies.id')])
            ->orderBy('name')
            ->get()
            ->map(fn (Subject $subject): array => [
                'id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'description' => $subject->description,
                'ai_question_format' => $subject->ai_question_format,
                'competencies_count' => $subject->competencies_count,
                'questions_count' => $subject->questions_count,
                'can_manage' => $request->user()->hasRole(UserRole::Admin) || $subject->school_id === $schoolId,
            ]);

        return Inertia::render('Subjects/Index', [
            'subjects' => $subjects,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Subjects/Form');
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $subject = Subject::create([
            'school_id' => $request->user()->hasRole(UserRole::Admin) ? null : $request->user()->school_id,
            ...$this->validatedData($request),
        ]);
        $auditLogger->log($request, 'subject.created', $subject);

        return to_route('subjects.index')->with('success', 'Mata pelajaran berhasil ditambahkan.');
    }

    public function edit(Request $request, Subject $subject): Response
    {
        $this->ensureManageable($request, $subject);

        return Inertia::render('Subjects/Form', ['subject' => $subject]);
    }

    public function update(Request $request, Subject $subject, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureManageable($request, $subject);
        $subject->update($this->validatedData($request, $subject));
        $auditLogger->log($request, 'subject.updated', $subject);

        return to_route('subjects.index')->with('success', 'Mata pelajaran berhasil diperbarui.');
    }

    public function destroy(Request $request, Subject $subject, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureManageable($request, $subject);

        if ($subject->competencies()->exists()) {
            return back()->with('error', 'Mata pelajaran tidak dapat dihapus karena sudah memiliki kompetensi.');
        }

        $auditLogger->log($request, 'subject.deleted', $subject, $subject->only(['code', 'name']));
        $subject->delete();

        return to_route('subjects.index')->with('success', 'Mata pelajaran berhasil dihapus.');
    }

    private function validatedData(Request $request, ?Subject $subject = null): array
    {
        $schoolId = $request->user()->hasRole(UserRole::Admin) ? null : $request->user()->school_id;
        $request->merge([
            'code' => Str::upper(trim($request->string('code')->toString())),
            'name' => Str::squish($request->string('name')->toString()),
        ]);

        return $request->validate([
            'code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('subjects', 'code')->where('school_id', $schoolId)->ignore($subject),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'ai_question_format' => ['required', Rule::in(['direct', 'story'])],
        ], [
            'code.regex' => 'Kode hanya boleh berisi huruf kapital, angka, titik, garis bawah, dan tanda hubung.',
        ]);
    }

    private function ensureManageable(Request $request, Subject $subject): void
    {
        abort_unless(
            $request->user()->hasRole(UserRole::Admin) || $subject->school_id === $request->user()->school_id,
            404,
        );
    }
}
