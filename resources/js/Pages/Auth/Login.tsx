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
    const [showNameModal, setShowNameModal] = useState(false);
    const studentForm = useForm({
        npsn: '',
        nisn: '',
        name: '',
    });
    const staffForm = useForm({
        email: '',
        password: '',
        remember: false as boolean,
    });

    const submitStudent: FormEventHandler = (event) => {
        event.preventDefault();
        studentForm.post(route('student-login'), {
            onError: (errors) => {
                if (errors.name) {
                    setShowNameModal(true);
                }
            },
        });
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
                    Masuk ANBK Cerdas
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
                    Guru / Admin
                </button>
            </div>

            {mode === 'student' ? (
                <form onSubmit={submitStudent}>
                    <div className="rounded-xl border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-900">
                        Belum punya akun? Isi data di bawah. Akun akan dibuat
                        otomatis.
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
                        <TextInput
                            id="password"
                            type="password"
                            name="password"
                            value={staffForm.data.password}
                            className="mt-1 block w-full"
                            autoComplete="current-password"
                            onChange={(event) =>
                                staffForm.setData(
                                    'password',
                                    event.target.value,
                                )
                            }
                        />
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
                </form>
            )}

            {showNameModal && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 px-4 backdrop-blur-sm"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="student-name-title"
                >
                    <form
                        onSubmit={submitStudent}
                        className="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl sm:p-8"
                    >
                        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 text-xl font-bold text-emerald-700">
                            👋
                        </div>
                        <h2
                            id="student-name-title"
                            className="mt-5 text-2xl font-bold text-slate-900"
                        >
                            Selamat datang!
                        </h2>
                        <p className="mt-2 text-sm leading-6 text-slate-600">
                            NISN ini belum pernah masuk. Tuliskan nama lengkapmu
                            untuk membuat akun.
                        </p>

                        <div className="mt-6">
                            <InputLabel
                                htmlFor="student-name"
                                value="Nama Lengkap"
                            />
                            <TextInput
                                id="student-name"
                                name="name"
                                type="text"
                                autoComplete="name"
                                value={studentForm.data.name}
                                className="mt-1 block w-full px-4 py-3 text-lg"
                                isFocused
                                onChange={(event) =>
                                    studentForm.setData(
                                        'name',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={studentForm.errors.name}
                                className="mt-2"
                            />
                        </div>

                        <div className="mt-6 flex gap-3">
                            <button
                                type="button"
                                className="flex-1 rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                                onClick={() => {
                                    setShowNameModal(false);
                                    studentForm.setData('name', '');
                                    studentForm.clearErrors('name');
                                }}
                            >
                                Kembali
                            </button>
                            <PrimaryButton
                                className="flex-1 justify-center py-3 text-sm"
                                disabled={studentForm.processing}
                            >
                                {studentForm.processing
                                    ? 'Menyimpan...'
                                    : 'Simpan & Masuk'}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            )}
        </GuestLayout>
    );
}
