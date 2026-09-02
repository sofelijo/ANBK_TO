import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

type Blueprint = {
    id: number;
    code: string;
    name: string;
    description?: string;
    subject: { code: string; name: string };
    competencies: { id: number; code: string; name: string }[];
    questions_count: number;
    can_manage: boolean;
};

export default function Index({ blueprints }: { blueprints: Blueprint[] }) {
    const competencyCount = new Set(blueprints.flatMap((blueprint) => blueprint.competencies.map((competency) => competency.id))).size;

    const remove = (blueprint: Blueprint) => {
        if (window.confirm(`Hapus tipe soal ${blueprint.name}?`)) {
            router.delete(route('question-types.destroy', blueprint.id), { preserveScroll: true });
        }
    };

    return <AuthenticatedLayout header={<div className="flex items-center justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-wide text-emerald-600">Bahasa Indonesia</p><h1 className="mt-0.5 text-xl font-bold text-slate-900">Kompetensi & Tipe Soal</h1></div><Link href={route('question-types.create')} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-500">Tambah tipe</Link></div>}>
        <Head title="Tipe Soal" />
        <div className="mx-auto max-w-7xl px-4 py-5 sm:px-6">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs">
                <p className="text-slate-500">Tipe soal dapat digunakan ulang pada beberapa kompetensi.</p>
                <div className="flex gap-2 font-semibold"><span className="rounded-md bg-indigo-50 px-2 py-1 text-indigo-700">{blueprints.length} tipe soal</span><span className="rounded-md bg-emerald-50 px-2 py-1 text-emerald-700">{competencyCount} kompetensi</span></div>
            </div>
            {blueprints.length === 0 ? <div className="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">Belum ada tipe soal Bahasa Indonesia.</div> : <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div className="hidden grid-cols-[minmax(240px,1fr)_minmax(320px,1.45fr)_72px_104px] gap-4 border-b border-slate-200 bg-slate-50 px-4 py-2 text-[11px] font-bold uppercase tracking-wide text-slate-500 md:grid">
                    <span>Tipe soal</span><span>Kompetensi terkait</span><span className="text-center">Soal</span><span className="text-right">Aksi</span>
                </div>
                <div className="divide-y divide-slate-100">{blueprints.map((blueprint) => <article key={blueprint.id} className="grid gap-2 px-4 py-3 md:grid-cols-[minmax(240px,1fr)_minmax(320px,1.45fr)_72px_104px] md:items-center md:gap-4">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2"><h2 className="text-sm font-bold text-slate-900">{blueprint.name}</h2>{!blueprint.can_manage && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-slate-500">Global</span>}</div>
                        <div className="mt-0.5 flex min-w-0 items-center gap-2"><span className="shrink-0 font-mono text-[10px] font-semibold text-indigo-600">{blueprint.code}</span><span className="truncate text-[11px] text-slate-400" title={blueprint.description}>{blueprint.description || 'Tanpa deskripsi'}</span></div>
                    </div>
                    <div className="flex flex-wrap gap-1.5">{blueprint.competencies.length ? blueprint.competencies.map((competency) => <span key={competency.id} title={competency.code} className="rounded-md bg-emerald-50 px-2 py-1 text-[11px] leading-4 text-emerald-700">{competency.name}</span>) : <span className="text-[11px] text-amber-600">Belum terhubung ke kompetensi</span>}</div>
                    <div className="flex items-center gap-1 text-xs text-slate-500 md:justify-center"><span className="md:hidden">Digunakan:</span><strong className="text-slate-700">{blueprint.questions_count}</strong></div>
                    <div className="flex items-center gap-1.5 md:justify-end">{blueprint.can_manage ? <><Link href={route('question-types.edit', blueprint.id)} className="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Edit</Link><button onClick={() => remove(blueprint)} className="rounded-md px-2 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50">Hapus</button></> : <span className="text-[11px] text-slate-400">—</span>}</div>
                </article>)}</div>
            </div>}
        </div>
    </AuthenticatedLayout>;
}
