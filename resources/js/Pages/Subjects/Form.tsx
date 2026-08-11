import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Subject = { id: number; code: string; name: string; description?: string };

export default function Form({ subject }: { subject?: Subject }) {
    const editing = Boolean(subject);
    const { data, setData, post, put, processing, errors } = useForm({
        code: subject?.code || '',
        name: subject?.name || '',
        description: subject?.description || '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (subject) put(route('subjects.update', subject.id));
        else post(route('subjects.store'));
    };

    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Klasifikasi Bank Soal</p><h1 className="mt-1 text-2xl font-bold text-slate-900">{editing ? 'Edit Mata Pelajaran' : 'Tambah Mata Pelajaran'}</h1></div>}>
            <Head title={editing ? 'Edit Mata Pelajaran' : 'Tambah Mata Pelajaran'} />
            <div className="mx-auto max-w-3xl px-4 py-8 sm:px-6">
                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div className="grid gap-5 sm:grid-cols-[180px_1fr]"><div><InputLabel htmlFor="code" value="Kode mapel" /><TextInput id="code" value={data.code} onChange={(event) => setData('code', event.target.value.toUpperCase())} placeholder="BIND" className="mt-1 block w-full font-mono uppercase" isFocused /><InputError message={errors.code} className="mt-2" /></div><div><InputLabel htmlFor="name" value="Nama mata pelajaran" /><TextInput id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} placeholder="Bahasa Indonesia" className="mt-1 block w-full" /><InputError message={errors.name} className="mt-2" /></div></div>
                    <div className="mt-5"><InputLabel htmlFor="description" value="Deskripsi (opsional)" /><textarea id="description" rows={5} value={data.description} onChange={(event) => setData('description', event.target.value)} placeholder="Jelaskan ruang lingkup mata pelajaran." className="mt-1 block w-full rounded-xl border-slate-300 text-sm" /><InputError message={errors.description} className="mt-2" /></div>
                    <div className="mt-8 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><Link href={route('subjects.index')} className="rounded-xl border border-slate-300 px-5 py-3 text-center text-sm font-semibold text-slate-600">Batal</Link><button disabled={processing} className="rounded-xl bg-emerald-600 px-6 py-3 text-sm font-bold text-white disabled:opacity-50">{processing ? 'Menyimpan…' : editing ? 'Simpan perubahan' : 'Tambah mata pelajaran'}</button></div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
