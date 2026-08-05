<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
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
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'npsn' => ['required', 'digits:8'],
            'account_type' => ['required', Rule::in([UserRole::Student->value, UserRole::Teacher->value])],
            'student_identifier' => [
                Rule::requiredIf($request->string('account_type')->toString() === UserRole::Student->value),
                'nullable',
                'string',
                'max:100',
            ],
            'grade_level' => [
                Rule::requiredIf($request->string('account_type')->toString() === UserRole::Student->value),
                'nullable',
                'integer',
                Rule::in([5, 8, 11]),
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
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $role,
            'student_identifier' => $role === UserRole::Student ? $data['student_identifier'] : null,
            'grade_level' => $role === UserRole::Student ? $data['grade_level'] : null,
            'is_active' => $role === UserRole::Student,
            'approved_at' => $role === UserRole::Student ? now() : null,
        ]);

        event(new Registered($user));

        if ($role === UserRole::Teacher) {
            return to_route('login')->with(
                'status',
                'Pendaftaran guru berhasil. Akun Anda menunggu persetujuan admin sekolah.',
            );
        }

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
