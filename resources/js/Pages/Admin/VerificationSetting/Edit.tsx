import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Edit({ requiredVerifications, default: defaultValue }: { requiredVerifications: number; default: number }) {
    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        required_verifications: requiredVerifications,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(route('admin.verification-setting.update'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-sm font-medium text-emerald-600">Administrasi</p>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900">Pengaturan Verifikasi Soal</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Tentukan jumlah minimal guru yang harus memverifikasi setiap soal sebelum soal dapat diterbitkan.
                    </p>
                </div>
            }
        >
            <Head title="Pengaturan Verifikasi Soal" />
            <div className="mx-auto max-w-xl px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div className="space-y-6">
                        <div className="rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm leading-6 text-blue-900">
                            <p className="font-semibold">Cara kerja verifikasi soal</p>
                            <p className="mt-1">Setiap soal yang diajukan guru harus diverifikasi oleh sejumlah guru berbeda sebelum berstatus <strong>Terbit</strong> dan bisa masuk paket ujian. Saat ini ditetapkan minimal <strong>{requiredVerifications} guru</strong>.</p>
                        </div>

                        <label className="block">
                            <span className="text-sm font-medium text-slate-700">Jumlah minimal verifikasi guru</span>
                            <div className="mt-2 flex items-center gap-4">
                                <input
                                    id="required-verifications-input"
                                    type="number"
                                    min={1}
                                    max={10}
                                    value={data.required_verifications}
                                    onChange={(event) => setData('required_verifications', Number(event.target.value))}
                                    className="w-24 rounded-lg border-slate-300 text-center text-lg font-bold focus:border-emerald-500 focus:ring-emerald-500"
                                />
                                <span className="text-sm text-slate-500">guru (min. 1, maks. 10)</span>
                            </div>
                            <InputError message={errors.required_verifications} className="mt-1" />
                        </label>

                        <div className="grid grid-cols-3 gap-2">
                            {[1, 2, 3, 4, 5].map((n) => (
                                <button
                                    key={n}
                                    type="button"
                                    onClick={() => setData('required_verifications', n)}
                                    className={`rounded-lg border py-2.5 text-sm font-semibold transition ${data.required_verifications === n ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600 hover:border-slate-300'}`}
                                >
                                    {n} guru
                                    {n === defaultValue && <span className="ml-1 text-xs font-normal text-slate-400">(default)</span>}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="mt-8 flex items-center justify-between">
                        {recentlySuccessful && (
                            <p className="text-sm font-medium text-emerald-600">✓ Tersimpan!</p>
                        )}
                        <div className="ml-auto">
                            <button
                                id="save-verification-setting-btn"
                                type="submit"
                                disabled={processing}
                                className="rounded-lg bg-emerald-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
                            >
                                {processing ? 'Menyimpan...' : 'Simpan pengaturan'}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
