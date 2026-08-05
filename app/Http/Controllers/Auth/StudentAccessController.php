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
use Illuminate\Support\Str;
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
        $wasCreated = false;

        if ($student && $student->role !== UserRole::Student) {
            $request->recordFailedAttempt();

            throw ValidationException::withMessages([
                'nisn' => 'NISN tidak dapat digunakan untuk masuk sebagai siswa.',
            ]);
        }

        if ($student && ! $student->is_active) {
            $request->recordFailedAttempt();

            throw ValidationException::withMessages([
                'nisn' => 'Akun siswa sedang dinonaktifkan. Hubungi guru atau admin sekolah.',
            ]);
        }

        if (! $student) {
            $name = Str::squish($request->string('name')->toString());

            if ($name === '') {
                $request->recordFailedAttempt();

                throw ValidationException::withMessages([
                    'name' => 'Isi nama lengkap untuk membuat akun pertama kali.',
                ]);
            }

            $school ??= School::firstOrCreate(
                ['npsn' => $npsn],
                ['name' => "Sekolah NPSN {$npsn}"]
            );

            $student = User::create([
                'school_id' => $school->id,
                'name' => $name,
                'email' => "student.{$school->id}.{$nisn}@anbk.local",
                'password' => Str::random(40),
                'role' => UserRole::Student,
                'student_identifier' => $nisn,
                'grade_level' => 5,
                'email_verified_at' => now(),
                'is_active' => true,
            ]);
            $wasCreated = true;
        }

        Auth::login($student);
        $request->session()->regenerate();
        $student->update(['last_login_at' => now()]);
        $request->clearRateLimit();

        $auditLogger->log(
            $request,
            $wasCreated ? 'student.auto_registered' : 'student.logged_in_without_password',
            $student,
            ['npsn' => $school->npsn]
        );

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
