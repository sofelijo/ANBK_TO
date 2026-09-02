import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

type LoginMode = 'student' | 'staff';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const [mode, setMode] = useState<LoginMode>('student');
    const [showPassword, setShowPassword] = useState(false);
    const studentForm = useForm({
        npsn: '',
        nisn: '',
    });
    const staffForm = useForm({
        email: '',
        password: '',
        remember: false as boolean,
    });

    const submitStudent: FormEventHandler = (event) => {
        event.preventDefault();
        studentForm.post(route('student-login'));
    };

    const submitStaff: FormEventHandler = (event) => {
        event.preventDefault();
        staffForm.post(route('login'), {
            onFinish: () => staffForm.reset('password'),
        });
    };

    const digitsOnly = (value: string, length: number) =>
        value.replace(/\D/g, '').slice(0, length);

    return (
        <GuestLayout>
            <Head title="Masuk" />

            <div className="mb-6 text-center">
                <h1 className="text-2xl font-bold text-gray-900">
                    Masuk TOA
                </h1>
                <p className="mt-1 text-sm text-gray-600">
                    Pilih cara masuk sesuai akunmu.
                </p>
            </div>

            {status && (
                <div className="mb-4 rounded-lg bg-green-50 p-3 text-sm font-medium text-green-700">
                    {status}
                </div>
            )}

            <div className="mb-6 grid grid-cols-2 rounded-xl bg-gray-100 p-1">
                <button
                    type="button"
                    onClick={() => setMode('student')}
                    className={`rounded-lg px-3 py-3 text-sm font-semibold transition ${
                        mode === 'student'
                            ? 'bg-white text-indigo-700 shadow-sm'
                            : 'text-gray-600'
                    }`}
                >
                    Siswa
                </button>
                <button
                    type="button"
                    onClick={() => setMode('staff')}
                    className={`rounded-lg px-3 py-3 text-sm font-semibold transition ${
                        mode === 'staff'
                            ? 'bg-white text-indigo-700 shadow-sm'
                            : 'text-gray-600'
                    }`}
                >
                    Guru / Operator / Admin
                </button>
            </div>

            {mode === 'student' ? (
                <form onSubmit={submitStudent}>
                    <div className="rounded-xl border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-900">
                        Belum punya akun murid?{' '}
                        <Link href={route('register', { account_type: 'student' })} className="font-bold underline">
                            Daftar terlebih dahulu
                        </Link>
                        . Akun dapat digunakan setelah disetujui operator sekolah.
                    </div>

                    <div className="mt-5">
                        <InputLabel htmlFor="npsn" value="NPSN Sekolah" />
                        <TextInput
                            id="npsn"
                            name="npsn"
                            type="text"
                            inputMode="numeric"
                            autoComplete="off"
                            maxLength={8}
                            value={studentForm.data.npsn}
                            className="mt-1 block w-full px-4 py-3 text-lg"
                            isFocused
                            onChange={(event) =>
                                studentForm.setData(
                                    'npsn',
                                    digitsOnly(event.target.value, 8),
                                )
                            }
                        />
                        <p className="mt-1 text-xs text-gray-500">
                            Masukkan 8 angka NPSN sekolahmu.
                        </p>
                        <InputError
                            message={studentForm.errors.npsn}
                            className="mt-2"
                        />
                    </div>

                    <div className="mt-5">
                        <InputLabel htmlFor="nisn" value="NISN" />
                        <TextInput
                            id="nisn"
                            name="nisn"
                            type="text"
                            inputMode="numeric"
                            autoComplete="username"
                            maxLength={10}
                            value={studentForm.data.nisn}
                            className="mt-1 block w-full px-4 py-3 text-lg"
                            onChange={(event) =>
                                studentForm.setData(
                                    'nisn',
                                    digitsOnly(event.target.value, 10),
                                )
                            }
                        />
                        <p className="mt-1 text-xs text-gray-500">
                            NISN terdiri dari 10 angka.
                        </p>
                        <InputError
                            message={studentForm.errors.nisn}
                            className="mt-2"
                        />
                    </div>

                    <PrimaryButton
                        className="mt-6 w-full justify-center py-3 text-sm"
                        disabled={studentForm.processing}
                    >
                        {studentForm.processing
                            ? 'Sedang masuk...'
                            : 'Masuk sebagai Siswa'}
                    </PrimaryButton>
                </form>
            ) : (
                <form onSubmit={submitStaff}>
                    <div>
                        <InputLabel htmlFor="email" value="Email" />
                        <TextInput
                            id="email"
                            type="email"
                            name="email"
                            value={staffForm.data.email}
                            className="mt-1 block w-full"
                            autoComplete="username"
                            isFocused
                            onChange={(event) =>
                                staffForm.setData('email', event.target.value)
                            }
                        />
                        <InputError
                            message={staffForm.errors.email}
                            className="mt-2"
                        />
                    </div>

                    <div className="mt-4">
                        <InputLabel htmlFor="password" value="Password" />
                        <div className="relative mt-1">
                            <TextInput
                                id="password"
                                type={showPassword ? 'text' : 'password'}
                                name="password"
                                value={staffForm.data.password}
                                className="block w-full pe-12"
                                autoComplete="current-password"
                                onChange={(event) =>
                                    staffForm.setData(
                                        'password',
                                        event.target.value,
                                    )
                                }
                            />
                            <button
                                type="button"
                                onClick={() => setShowPassword((value) => !value)}
                                className="absolute inset-y-0 end-0 flex w-12 items-center justify-center rounded-e-md text-gray-500 transition hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500"
                                aria-label={
                                    showPassword
                                        ? 'Sembunyikan password'
                                        : 'Tampilkan password'
                                }
                                aria-pressed={showPassword}
                            >
                                {showPassword ? (
                                    <svg
                                        className="h-5 w-5"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="2"
                                        aria-hidden="true"
                                    >
                                        <path d="m3 3 18 18" />
                                        <path d="M10.6 10.7a2 2 0 0 0 2.7 2.7" />
                                        <path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c5.5 0 9 6 9 6a15.8 15.8 0 0 1-2.1 2.9" />
                                        <path d="M6.6 6.6C4.4 8 3 10 3 10s3.5 6 9 6a9.7 9.7 0 0 0 3.4-.6" />
                                    </svg>
                                ) : (
                                    <svg
                                        className="h-5 w-5"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="2"
                                        aria-hidden="true"
                                    >
                                        <path d="M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6Z" />
                                        <circle cx="12" cy="12" r="2.5" />
                                    </svg>
                                )}
                            </button>
                        </div>
                        <InputError
                            message={staffForm.errors.password}
                            className="mt-2"
                        />
                    </div>

                    <div className="mt-4 block">
                        <label className="flex items-center">
                            <Checkbox
                                name="remember"
                                checked={staffForm.data.remember}
                                onChange={(event) =>
                                    staffForm.setData(
                                        'remember',
                                        event.target.checked,
                                    )
                                }
                            />
                            <span className="ms-2 text-sm text-gray-600">
                                Ingat saya
                            </span>
                        </label>
                    </div>

                    <div className="mt-5 flex items-center justify-between">
                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="rounded-md text-sm text-gray-600 underline hover:text-gray-900"
                            >
                                Lupa password?
                            </Link>
                        )}
                        <PrimaryButton
                            className="ms-auto"
                            disabled={staffForm.processing}
                        >
                            Masuk
                        </PrimaryButton>
                    </div>

                    <div className="mt-6 border-t border-gray-200 pt-5 text-center">
                        <p className="text-sm text-gray-600">
                            Belum memiliki akun guru atau operator?
                        </p>
                        <Link
                            href={route('register', {
                                account_type: 'teacher',
                            })}
                            className="mt-3 inline-flex w-full items-center justify-center rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm font-semibold text-indigo-700 transition hover:border-indigo-300 hover:bg-indigo-100"
                        >
                            Daftar Guru / Operator
                        </Link>
                        <p className="mt-3 text-xs leading-5 text-gray-500">
                            Pendaftaran guru atau operator harus disetujui admin sekolah.
                        </p>
                    </div>
                </form>
            )}

        </GuestLayout>
    );
}
