import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

type AccountType = 'student' | 'teacher' | 'operator';

export default function Register({ initialAccountType = 'student' }: { initialAccountType?: AccountType }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        parent_email: '',
        npsn: '',
        account_type: initialAccountType,
        student_identifier: '',
        grade_level: 6,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);

    return (
        <GuestLayout>
            <Head title="Daftar" />

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="account_type" value="Daftar sebagai" />
                    <select
                        id="account_type"
                        value={data.account_type}
                        onChange={(e) => setData('account_type', e.target.value as AccountType)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="student">Murid</option>
                        <option value="teacher">Guru</option>
                        <option value="operator">Operator Sekolah</option>
                    </select>
                    <InputError message={errors.account_type} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="name" value="Nama lengkap" />

                    <TextInput
                        id="name"
                        name="name"
                        value={data.name}
                        className="mt-1 block w-full"
                        autoComplete="name"
                        isFocused={true}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                    />

                    <InputError message={errors.name} className="mt-2" />
                </div>

                <div className={`mt-4 grid gap-3 ${data.account_type === 'student' ? 'grid-cols-2' : 'grid-cols-1'}`}>
                    <div>
                        <InputLabel htmlFor="npsn" value="NPSN sekolah" />
                        <TextInput id="npsn" inputMode="numeric" maxLength={8} placeholder="8 digit NPSN" value={data.npsn} className="mt-1 block w-full" onChange={(e) => setData('npsn', e.target.value.replace(/\D/g, '').slice(0, 8))} required />
                        <InputError message={errors.npsn} className="mt-2" />
                    </div>
                    {data.account_type === 'student' && <div>
                        <InputLabel htmlFor="grade_level" value="Kelas" />
                        <select id="grade_level" value={data.grade_level} onChange={(e) => setData('grade_level', Number(e.target.value))} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value={6}>Kelas 6</option>
                            <option value={9}>Kelas 9</option>
                            <option value={12}>Kelas 12</option>
                        </select>
                    </div>}
                </div>

                {data.account_type === 'student' && <div className="mt-4">
                        <InputLabel htmlFor="student_identifier" value="NISN" />
                    <TextInput id="student_identifier" inputMode="numeric" maxLength={10} placeholder="10 digit NISN" value={data.student_identifier} className="mt-1 block w-full" onChange={(e) => setData('student_identifier', e.target.value.replace(/\D/g, '').slice(0, 10))} required />
                    <InputError message={errors.student_identifier} className="mt-2" />
                </div>}



                {data.account_type === 'operator' && (
                    <div className="mt-4 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm leading-6 text-indigo-800">
                        Setelah mendaftar, akun operator harus disetujui admin sekolah sebelum dapat login.
                    </div>
                )}

                {data.account_type === 'student' && (
                    <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm leading-6 text-emerald-800">
                        Setelah mendaftar, akun murid harus disetujui operator sekolah sebelum dapat login.
                    </div>
                )}

                <div className="mt-4">
                    <InputLabel htmlFor={data.account_type === 'student' ? 'parent_email' : 'email'} value={data.account_type === 'student' ? 'Email orang tua' : 'Email'} />

                    <TextInput
                        id={data.account_type === 'student' ? 'parent_email' : 'email'}
                        type="email"
                        name={data.account_type === 'student' ? 'parent_email' : 'email'}
                        value={data.account_type === 'student' ? data.parent_email : data.email}
                        className="mt-1 block w-full"
                        autoComplete="username"
                        onChange={(e) => data.account_type === 'student' ? setData('parent_email', e.target.value) : setData('email', e.target.value)}
                        required
                    />

                    <InputError message={data.account_type === 'student' ? errors.parent_email : errors.email} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="password" value="Kata sandi" />

                    <div className="relative mt-1">
                        <TextInput
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            name="password"
                            value={data.password}
                            className="block w-full pe-12"
                            autoComplete="new-password"
                            onChange={(e) => setData('password', e.target.value)}
                            required
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword((v) => !v)}
                            className="absolute inset-y-0 end-0 flex w-12 items-center justify-center rounded-e-md text-gray-500 transition hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500"
                            aria-label={showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}
                            aria-pressed={showPassword}
                        >
                            {showPassword ? (
                                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                                    <path d="m3 3 18 18" />
                                    <path d="M10.6 10.7a2 2 0 0 0 2.7 2.7" />
                                    <path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c5.5 0 9 6 9 6a15.8 15.8 0 0 1-2.1 2.9" />
                                    <path d="M6.6 6.6C4.4 8 3 10 3 10s3.5 6 9 6a9.7 9.7 0 0 0 3.4-.6" />
                                </svg>
                            ) : (
                                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                                    <path d="M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6Z" />
                                    <circle cx="12" cy="12" r="2.5" />
                                </svg>
                            )}
                        </button>
                    </div>

                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="password_confirmation" value="Konfirmasi kata sandi" />

                    <div className="relative mt-1">
                        <TextInput
                            id="password_confirmation"
                            type={showConfirmPassword ? 'text' : 'password'}
                            name="password_confirmation"
                            value={data.password_confirmation}
                            className="block w-full pe-12"
                            autoComplete="new-password"
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            required
                        />
                        <button
                            type="button"
                            onClick={() => setShowConfirmPassword((v) => !v)}
                            className="absolute inset-y-0 end-0 flex w-12 items-center justify-center rounded-e-md text-gray-500 transition hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500"
                            aria-label={showConfirmPassword ? 'Sembunyikan konfirmasi kata sandi' : 'Tampilkan konfirmasi kata sandi'}
                            aria-pressed={showConfirmPassword}
                        >
                            {showConfirmPassword ? (
                                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                                    <path d="m3 3 18 18" />
                                    <path d="M10.6 10.7a2 2 0 0 0 2.7 2.7" />
                                    <path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c5.5 0 9 6 9 6a15.8 15.8 0 0 1-2.1 2.9" />
                                    <path d="M6.6 6.6C4.4 8 3 10 3 10s3.5 6 9 6a9.7 9.7 0 0 0 3.4-.6" />
                                </svg>
                            ) : (
                                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                                    <path d="M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6Z" />
                                    <circle cx="12" cy="12" r="2.5" />
                                </svg>
                            )}
                        </button>
                    </div>

                    <InputError message={errors.password_confirmation} className="mt-2" />
                </div>

                <div className="mt-4 flex items-center justify-end">
                    <Link
                        href={route('login')}
                        className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        Sudah punya akun?
                    </Link>

                    <PrimaryButton className="ms-4" disabled={processing}>
                        {data.account_type === 'teacher'
                            ? 'Daftar sebagai Guru'
                            : data.account_type === 'operator'
                              ? 'Daftar sebagai Operator'
                              : 'Daftar sebagai Murid'}
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
