<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->where('school_id', $request->user()->school_id)
            ->when($request->string('search')->toString(), function ($query, string $search) {
                $query->where(fn ($nested) => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('student_identifier', 'like', "%{$search}%"));
            })
            ->when($request->string('role')->toString(), fn ($query, string $role) => $query->where('role', $role))
            ->when($request->string('status')->toString(), function ($query, string $status) {
                match ($status) {
                    'pending' => $query
                        ->where('role', UserRole::Teacher)
                        ->whereNull('approved_at')
                        ->where('is_active', false),
                    'active' => $query->where('is_active', true),
                    'inactive' => $query
                        ->where('is_active', false)
                        ->where(fn ($inactive) => $inactive
                            ->whereNotNull('approved_at')
                            ->orWhere('role', '!=', UserRole::Teacher)),
                    default => null,
                };
            })
            ->orderByRaw(
                'CASE WHEN role = ? AND approved_at IS NULL AND is_active = ? THEN 0 ELSE 1 END',
                [UserRole::Teacher->value, false],
            )
            ->orderBy('role')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'role', 'status']),
            'schoolNpsn' => $request->user()->school?->npsn,
            'pendingCount' => User::query()
                ->where('school_id', $request->user()->school_id)
                ->where('role', UserRole::Teacher)
                ->whereNull('approved_at')
                ->where('is_active', false)
                ->count(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in([UserRole::Teacher->value, UserRole::Student->value])],
            'student_identifier' => [
                Rule::requiredIf($request->string('role')->toString() === UserRole::Student->value),
                'nullable',
                'string',
                'max:100',
                Rule::unique('users')->where('school_id', $request->user()->school_id),
            ],
            'grade_level' => [
                Rule::requiredIf($request->string('role')->toString() === UserRole::Student->value),
                'nullable',
                'integer',
                Rule::in([5, 8, 11]),
            ],
        ]);

        $user = User::create([
            'school_id' => $request->user()->school_id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'student_identifier' => $data['role'] === UserRole::Student->value
                ? $data['student_identifier']
                : null,
            'grade_level' => $data['role'] === UserRole::Student->value
                ? $data['grade_level']
                : null,
            'email_verified_at' => now(),
            'is_active' => true,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        $auditLogger->log($request, 'user.created', $user, ['role' => $user->role->value]);

        return back()->with('success', 'Pengguna berhasil ditambahkan.');
    }

    public function toggleActive(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($user->school_id === $request->user()->school_id, 404);

        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                'user' => 'Anda tidak dapat menonaktifkan akun sendiri.',
            ]);
        }

        if ($user->role === UserRole::Teacher && $user->approved_at === null && ! $user->is_active) {
            throw ValidationException::withMessages([
                'user' => 'Gunakan tombol Setujui untuk akun guru yang masih menunggu.',
            ]);
        }

        $user->update([
            'is_active' => ! $user->is_active,
            'approved_at' => $user->approved_at ?? now(),
            'approved_by' => $user->approved_by ?? $request->user()->id,
        ]);
        $auditLogger->log($request, 'user.status_changed', $user, ['is_active' => $user->is_active]);

        return back()->with('success', $user->is_active ? 'Akun diaktifkan.' : 'Akun dinonaktifkan.');
    }

    public function approve(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($user->school_id === $request->user()->school_id, 404);

        if ($user->role !== UserRole::Teacher || $user->approved_at !== null || $user->is_active) {
            throw ValidationException::withMessages([
                'user' => 'Akun ini bukan pendaftaran guru yang sedang menunggu persetujuan.',
            ]);
        }

        $user->update([
            'is_active' => true,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);
        $auditLogger->log($request, 'user.approved', $user, ['role' => $user->role->value]);

        return back()->with('success', 'Akun guru disetujui dan sekarang dapat login.');
    }
}
