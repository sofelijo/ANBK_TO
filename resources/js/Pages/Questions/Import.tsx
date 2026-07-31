import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Import({ errors: importErrors }: { errors: string[] }) {
    const { data, setData, post, processing, errors } = useForm<{ file: File | null }>({ file: null });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('questions.import.store'), { forceFormData: true });
    };

    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Bank Soal</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Impor Soal</h1></div>}>
            <Head title="Impor Soal" />
            <div className="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6">
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Unggah CSV atau Excel</h2>
                    <p className="mt-2 text-sm leading-6 text-slate-500">Setiap baris yang valid akan dibuat sebagai draft. Baris yang bermasalah dilewati dan ditampilkan agar dapat diperbaiki tanpa menggagalkan seluruh impor.</p>
                    <Link href={route('questions.import.template')} className="mt-4 inline-flex text-sm font-semibold text-emerald-700">Unduh template CSV</Link>
                    <form onSubmit={submit} className="mt-6">
                        <input type="file" accept=".csv,.xlsx,.xls" onChange={(event) => setData('file', event.target.files?.[0] || null)} className="block w-full rounded-xl border border-dashed border-slate-300 p-6 text-sm" />
                        <InputError message={errors.file} className="mt-2" />
                        <div className="mt-5 flex justify-end gap-3"><Link href={route('questions.index')} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Kembali</Link><button disabled={processing || !data.file} className="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-40">Impor sebagai draft</button></div>
                    </form>
                </section>

                {importErrors.length > 0 && <section className="rounded-2xl border border-amber-200 bg-amber-50 p-6"><h2 className="font-semibold text-amber-900">Baris yang perlu diperbaiki</h2><ul className="mt-3 space-y-2 text-sm text-amber-800">{importErrors.map((error, index) => <li key={index}>{error}</li>)}</ul></section>}
            </div>
        </AuthenticatedLayout>
    );
}
