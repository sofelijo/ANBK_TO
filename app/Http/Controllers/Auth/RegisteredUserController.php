<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request): Response
    {
        $requestedRole = $request->string('account_type')->toString();

        return Inertia::render('Auth/Register', [
            'initialAccountType' => in_array($requestedRole, [
                UserRole::Teacher->value,
                UserRole::Operator->value,
            ], true) ? $requestedRole : UserRole::Student->value,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                Rule::requiredIf($request->string('account_type')->toString() !== UserRole::Student->value),
                'nullable',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class, 'email'),
            ],
            'parent_email' => [
                Rule::requiredIf($request->string('account_type')->toString() === UserRole::Student->value),
                'nullable',
                'string',
                'lowercase',
                'email',
                'max:255',
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'npsn' => ['required', 'digits:8'],
            'account_type' => ['required', Rule::in([
                UserRole::Student->value,
                UserRole::Teacher->value,
                UserRole::Operator->value,
            ])],
            'student_identifier' => [
                Rule::requiredIf($request->string('account_type')->toString() === UserRole::Student->value),
                'nullable',
                'digits:10',
            ],
            'grade_level' => [
                Rule::requiredIf($request->string('account_type')->toString() === UserRole::Student->value),
                'nullable',
                'integer',
                Rule::in([6, 9, 12]),
            ],
        ]);

        $npsn = trim($data['npsn']);
        $school = School::firstOrCreate(
            ['npsn' => $npsn],
            ['name' => "Sekolah NPSN {$npsn}"]
        );

        $role = UserRole::from($data['account_type']);

        if ($role === UserRole::Student) {
            $request->validate([
                'student_identifier' => [
                    Rule::unique('users')->where('school_id', $school->id),
                ],
            ]);
        }

        $user = User::create([
            'school_id' => $school->id,
            'name' => $data['name'],
            'email' => $role === UserRole::Student
                ? "student.{$school->id}.{$data['student_identifier']}@toa.local"
                : $data['email'],
            'parent_email' => $role === UserRole::Student ? $data['parent_email'] : null,
            'password' => Hash::make($data['password']),
            'role' => $role,
            'student_identifier' => $role === UserRole::Student ? $data['student_identifier'] : null,
            'grade_level' => $role === UserRole::Student ? $data['grade_level'] : null,
            'is_active' => false,
            'approved_at' => null,
        ]);

        event(new Registered($user));

        $status = match ($role) {
            UserRole::Student => 'Pendaftaran murid berhasil. Akun Anda menunggu persetujuan operator sekolah.',
            UserRole::Operator => 'Pendaftaran operator berhasil. Akun Anda menunggu persetujuan admin sekolah.',
            default => 'Pendaftaran guru berhasil. Akun Anda menunggu persetujuan admin sekolah.',
        };

        return to_route('login')->with('status', $status);
    }
}
