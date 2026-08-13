import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Subject = {
    id: number;
    code: string;
    name: string;
    description?: string;
    ai_question_format: 'direct' | 'story';
    competencies_count: number;
    questions_count: number;
    can_manage: boolean;
};

export default function Index({ subjects, filters }: { subjects: Subject[]; filters: { search?: string } }) {
    const [search, setSearch] = useState(filters.search || '');

    const filter = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('subjects.index'), { search }, { preserveState: true, replace: true });
    };

    const remove = (subject: Subject) => {
        if (window.confirm(`Hapus mata pelajaran ${subject.name}?`)) {
            router.delete(route('subjects.destroy', subject.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<div className="flex flex-wrap items-center justify-between gap-4"><div><p className="text-sm font-medium text-emerald-600">Klasifikasi Bank Soal</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Mata Pelajaran</h1><p className="mt-1 text-sm text-slate-500">Kelola mapel sebelum menyusun kompetensi dan soal.</p></div><Link href={route('subjects.create')} className="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-500">Tambah mata pelajaran</Link></div>}>
            <Head title="Mata Pelajaran" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={filter} className="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari kode atau nama mapel…" className="min-w-0 flex-1 rounded-xl border-slate-300 text-sm" /><button className="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white">Cari</button></form>
                {subjects.length === 0 ? <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center"><p className="font-semibold text-slate-800">Belum ada mata pelajaran</p><p className="mt-1 text-sm text-slate-500">Tambahkan mapel pertama agar guru dapat membuat kompetensi dan soal.</p></div> : <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">{subjects.map((subject) => <article key={subject.id} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div className="flex items-start justify-between gap-3"><span className="rounded-lg bg-indigo-50 px-2.5 py-1 font-mono text-xs font-bold text-indigo-700">{subject.code}</span>{!subject.can_manage && <span className="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-500">Global</span>}</div><h2 className="mt-4 text-lg font-bold text-slate-900">{subject.name}</h2><span className="mt-2 inline-flex rounded-full bg-sky-50 px-2.5 py-1 text-[11px] font-semibold text-sky-700">AI: {subject.ai_question_format === 'story' ? 'Paket cerita' : 'Soal langsung'}</span><p className="mt-2 min-h-12 text-sm leading-6 text-slate-500">{subject.description || 'Tanpa deskripsi.'}</p><div className="mt-5 flex gap-5 border-t border-slate-100 pt-4 text-xs text-slate-500"><span><strong className="text-slate-800">{subject.competencies_count}</strong> kompetensi</span><span><strong className="text-slate-800">{subject.questions_count}</strong> soal</span></div>{subject.can_manage ? <div className="mt-5 flex gap-2"><Link href={route('subjects.edit', subject.id)} className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-center text-sm font-semibold text-slate-700">Edit</Link><button onClick={() => remove(subject)} className="rounded-lg border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-600">Hapus</button></div> : <p className="mt-5 text-right text-xs text-slate-400">Hanya baca</p>}</article>)}</div>}
            </div>
        </AuthenticatedLayout>
    );
}
