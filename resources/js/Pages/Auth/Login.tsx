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
    adminWhatsapp,
    verifyUrl,
}: {
    status?: string;
    canResetPassword: boolean;
    adminWhatsapp?: string | null;
    verifyUrl?: string;
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

            {status && (
                adminWhatsapp ? (
                    <a
                        href={`https://wa.me/${adminWhatsapp}?text=${encodeURIComponent(
                            `Halo Admin TOA, saya baru saja mendaftar sebagai guru/operator dan menunggu verifikasi akun.\n\nMohon bantuannya untuk mempercepat verifikasi. Berikut link langsung ke halaman verifikasi admin:\n${verifyUrl}\n\nTerima kasih 🙏`,
                        )}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mb-4 flex items-center justify-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800 transition hover:bg-green-100"
                    >
                        <svg viewBox="0 0 24 24" className="h-5 w-5 flex-shrink-0 fill-green-600" xmlns="http://www.w3.org/2000/svg">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                        </svg>
                        Kirim WA ke Admin untuk Percepat Verifikasi
                    </a>
                ) : (
                    <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        Hubungi admin sekolahmu untuk mempercepat verifikasi akun.
                        <br />
                        <span className="text-xs text-amber-600">
                            (Tombol WA otomatis tersedia setelah admin mengisi nomor WhatsApp di Data Sekolah.)
                        </span>
                    </div>
                )
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
