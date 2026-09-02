<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StudentLoginRequest;
use App\Models\School;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class StudentAccessController extends Controller
{
    public function __invoke(StudentLoginRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        $npsn = $request->string('npsn')->toString();
        $nisn = $request->string('nisn')->toString();
        $school = School::query()
            ->where('npsn', $npsn)
            ->first();
        $student = $school
            ? User::query()
                ->where('school_id', $school->id)
                ->where('student_identifier', $nisn)
                ->first()
            : null;
        if ($student && $student->role !== UserRole::Student) {
            $request->recordFailedAttempt();

            throw ValidationException::withMessages([
                'nisn' => 'NISN tidak dapat digunakan untuk masuk sebagai siswa.',
            ]);
        }

        if ($student && ! $student->is_active) {
            $request->recordFailedAttempt();

            throw ValidationException::withMessages([
                'nisn' => $student->approved_at === null
                    ? 'Pendaftaran akun masih menunggu persetujuan operator sekolah.'
                    : 'Akun siswa sedang dinonaktifkan. Hubungi operator atau admin sekolah.',
            ]);
        }

        if (! $student) {
            $request->recordFailedAttempt();

            throw ValidationException::withMessages([
                'nisn' => 'Akun belum terdaftar. Silakan daftar sebagai murid terlebih dahulu.',
            ]);
        }

        Auth::login($student);
        $request->session()->regenerate();
        $student->update(['last_login_at' => now()]);
        $request->clearRateLimit();

        $auditLogger->log(
            $request,
            'student.logged_in_without_password',
            $student,
            ['npsn' => $school->npsn]
        );

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
