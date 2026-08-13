import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Blueprint = { id: number; subject_id: number; code: string; name: string; description?: string; competency_ids: number[] };
type Competency = { id: number; subject_id: number; code: string; name: string; grade_level: number };

export default function Form({ blueprint, subjects, competencies }: { blueprint?: Blueprint; subjects: { id: number; code: string; name: string }[]; competencies: Competency[] }) {
    const editing = Boolean(blueprint);
    const { data, setData, post, put, processing, errors } = useForm({
        subject_id: blueprint?.subject_id || subjects[0]?.id || 0,
        code: blueprint?.code || '',
        name: blueprint?.name || '',
        description: blueprint?.description || '',
        competency_ids: blueprint?.competency_ids || [],
    });
    const availableCompetencies = competencies.filter((item) => item.subject_id === data.subject_id);
    const toggleCompetency = (id: number) => setData('competency_ids', data.competency_ids.includes(id) ? data.competency_ids.filter((item) => item !== id) : [...data.competency_ids, id]);
    const submit = (event: FormEvent) => { event.preventDefault(); editing && blueprint ? put(route('question-types.update', blueprint.id)) : post(route('question-types.store')); };

    return <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Klasifikasi Bahasa Indonesia</p><h1 className="mt-1 text-2xl font-bold text-slate-900">{editing ? 'Edit Tipe Soal' : 'Tambah Tipe Soal'}</h1></div>}>
        <Head title={editing ? 'Edit Tipe Soal' : 'Tambah Tipe Soal'} />
        <form onSubmit={submit} className="mx-auto max-w-3xl space-y-5 px-4 py-8 sm:px-6">
            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <label className="block text-sm font-semibold text-slate-700">Mata pelajaran<select value={data.subject_id} onChange={(event) => setData((current) => ({ ...current, subject_id: Number(event.target.value), competency_ids: [] }))} className="mt-1 block w-full rounded-xl border-slate-300"><option value={0}>Pilih mata pelajaran</option>{subjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.code} · {subject.name}</option>)}</select><InputError message={errors.subject_id} className="mt-1" /></label>
                <div className="mt-5 grid gap-4 sm:grid-cols-2"><label className="text-sm font-semibold text-slate-700">Kode<input value={data.code} onChange={(event) => setData('code', event.target.value.toUpperCase())} placeholder="Contoh: IDE-POKOK" className="mt-1 block w-full rounded-xl border-slate-300 font-mono" /><InputError message={errors.code} className="mt-1" /></label><label className="text-sm font-semibold text-slate-700">Nama tipe soal<input value={data.name} onChange={(event) => setData('name', event.target.value)} placeholder="Contoh: Ide pokok" className="mt-1 block w-full rounded-xl border-slate-300" /><InputError message={errors.name} className="mt-1" /></label></div>
                <label className="mt-5 block text-sm font-semibold text-slate-700">Petunjuk untuk guru dan AI<textarea value={data.description} onChange={(event) => setData('description', event.target.value)} rows={4} placeholder="Jelaskan fokus yang harus diukur oleh tipe soal ini." className="mt-1 block w-full rounded-xl border-slate-300" /><InputError message={errors.description} className="mt-1" /></label>
            </section>
            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 className="font-bold text-slate-900">Default pada kompetensi</h2><p className="mt-1 text-sm text-slate-500">Tipe ini tetap dapat dipilih pada kompetensi lain saat guru membuat soal.</p><div className="mt-4 grid gap-2 sm:grid-cols-2">{availableCompetencies.map((competency) => <label key={competency.id} className="flex items-start gap-3 rounded-xl border border-slate-200 p-3"><input type="checkbox" checked={data.competency_ids.includes(competency.id)} onChange={() => toggleCompetency(competency.id)} className="mt-0.5 rounded border-slate-300 text-emerald-600" /><span><strong className="block text-sm text-slate-900">{competency.name}</strong><span className="text-xs text-slate-500">Kelas {competency.grade_level} · {competency.code}</span></span></label>)}</div><InputError message={errors.competency_ids} className="mt-2" /></section>
            <div className="flex justify-end gap-3"><Link href={route('question-types.index')} className="rounded-xl border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-600">Batal</Link><button disabled={processing} className="rounded-xl bg-emerald-600 px-6 py-3 text-sm font-bold text-white disabled:opacity-50">{processing ? 'Menyimpan…' : 'Simpan'}</button></div>
        </form>
    </AuthenticatedLayout>;
}
