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
    const remove = (blueprint: Blueprint) => {
        if (window.confirm(`Hapus tipe soal ${blueprint.name}?`)) {
            router.delete(route('question-types.destroy', blueprint.id), { preserveScroll: true });
        }
    };

    return <AuthenticatedLayout header={<div className="flex items-center justify-between gap-4"><div><p className="text-sm font-medium text-emerald-600">Klasifikasi Bahasa Indonesia</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Tipe Soal</h1></div><Link href={route('question-types.create')} className="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white">Tambah tipe soal</Link></div>}>
        <Head title="Tipe Soal" />
        <div className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
            <div className="mb-5 rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm leading-6 text-indigo-900">Satu tipe soal dapat menjadi default di beberapa kompetensi. Contohnya, <strong>Ide pokok</strong> dapat dipakai pada kompetensi Informasi Deskripsi dan Informasi Eksposisi.</div>
            {blueprints.length === 0 ? <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-slate-500">Belum ada tipe soal Bahasa Indonesia.</div> : <div className="grid gap-4 md:grid-cols-2">{blueprints.map((blueprint) => <article key={blueprint.id} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex items-start justify-between gap-3"><span className="rounded-lg bg-indigo-50 px-2.5 py-1 font-mono text-xs font-bold text-indigo-700">{blueprint.code}</span>{!blueprint.can_manage && <span className="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-500">Global</span>}</div>
                <h2 className="mt-4 text-lg font-bold text-slate-900">{blueprint.name}</h2><p className="mt-2 text-sm leading-6 text-slate-500">{blueprint.description || 'Tanpa deskripsi.'}</p>
                <div className="mt-4 flex flex-wrap gap-2">{blueprint.competencies.length ? blueprint.competencies.map((competency) => <span key={competency.id} className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs text-emerald-700">{competency.code} · {competency.name}</span>) : <span className="text-xs text-amber-600">Belum menjadi default kompetensi mana pun</span>}</div>
                <p className="mt-4 border-t border-slate-100 pt-4 text-xs text-slate-500">Digunakan oleh {blueprint.questions_count} soal</p>
                {blueprint.can_manage && <div className="mt-4 flex gap-2"><Link href={route('question-types.edit', blueprint.id)} className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-center text-sm font-semibold text-slate-700">Edit</Link><button onClick={() => remove(blueprint)} className="rounded-lg border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-600">Hapus</button></div>}
            </article>)}</div>}
        </div>
    </AuthenticatedLayout>;
}
