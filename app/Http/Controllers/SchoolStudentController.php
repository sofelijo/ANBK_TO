<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SchoolStudentController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'grade' => ['nullable', 'integer', Rule::in([5, 8, 11])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
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
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
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
                'grade_level' => $student->grade_level,
                'is_active' => $student->is_active,
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
                'inactive' => (clone $baseQuery)->where('is_active', false)->count(),
            ],
        ]);
    }
}
