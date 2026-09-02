<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SchoolStudentController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'grade' => ['nullable', 'integer', Rule::in([6, 9, 12])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'pending'])],
        ]);
        $school = $request->user()->school()->firstOrFail();
        $baseQuery = User::query()
            ->where('school_id', $school->id)
            ->where('role', UserRole::Student);

        $students = (clone $baseQuery)
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(fn ($student) => $student
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('student_identifier', 'like', "%{$search}%"));
            })
            ->when($filters['grade'] ?? null, fn ($query, int $grade) => $query->where('grade_level', $grade))
            ->when($filters['status'] ?? null, function ($query, string $status): void {
                match ($status) {
                    'pending' => $query->where('is_active', false)->whereNull('approved_at'),
                    'active' => $query->where('is_active', true),
                    'inactive' => $query->where('is_active', false)->whereNotNull('approved_at'),
                    default => null,
                };
            })
            ->withCount([
                'attempts',
                'attempts as completed_attempts_count' => fn ($query) => $query->whereNotNull('submitted_at'),
            ])
            ->orderBy('grade_level')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $student): array => [
                'id' => $student->id,
                'name' => $student->name,
                'nisn' => $student->student_identifier,
                'parent_email' => $student->parent_email,
                'grade_level' => $student->grade_level,
                'is_active' => $student->is_active,
                'approved_at' => $student->approved_at,
                'attempts_count' => $student->attempts_count,
                'completed_attempts_count' => $student->completed_attempts_count,
                'last_login_at' => $student->last_login_at,
                'registered_at' => $student->created_at,
            ]);

        return Inertia::render('Schools/Students/Index', [
            'school' => $school->only(['name', 'npsn']),
            'students' => $students,
            'filters' => $filters,
            'summary' => [
                'total' => (clone $baseQuery)->count(),
                'active' => (clone $baseQuery)->where('is_active', true)->count(),
                'pending' => (clone $baseQuery)->where('is_active', false)->whereNull('approved_at')->count(),
                'inactive' => (clone $baseQuery)->where('is_active', false)->whereNotNull('approved_at')->count(),
            ],
        ]);
    }

    public function approve(Request $request, User $student, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless(
            $student->school_id === $request->user()->school_id && $student->role === UserRole::Student,
            404,
        );

        if ($student->is_active || $student->approved_at !== null) {
            throw ValidationException::withMessages([
                'student' => 'Akun murid ini tidak sedang menunggu persetujuan.',
            ]);
        }

        $student->update([
            'is_active' => true,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'email_verified_at' => $student->email_verified_at ?? now(),
        ]);
        $auditLogger->log($request, 'student.approved', $student, ['role' => $student->role->value]);

        return back()->with('success', 'Akun murid disetujui dan sekarang dapat login.');
    }
}
