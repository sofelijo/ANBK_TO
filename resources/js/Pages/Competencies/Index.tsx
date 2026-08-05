import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Competency = {
    id: number;
    code: string;
    domain: string;
    name: string;
    description?: string;
    grade_level: number;
    parent?: { id: number; code: string; name: string };
    questions_count: number;
    children_count: number;
    can_manage: boolean;
};

export default function Index({
    competencies,
    filters,
}: {
    competencies: Competency[];
    filters: { search?: string; grade_level?: string };
}) {
    const [search, setSearch] = useState(filters.search || '');
    const [gradeLevel, setGradeLevel] = useState(filters.grade_level || '');

    const filter = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            route('competencies.index'),
            { search, grade_level: gradeLevel },
            { preserveState: true, replace: true },
        );
    };

    const clearFilters = () => {
        setSearch('');
        setGradeLevel('');
        router.get(route('competencies.index'), {}, { replace: true });
    };

    const remove = (competency: Competency) => {
        if (
            window.confirm(
                `Hapus kompetensi ${competency.code} · ${competency.name}?`,
            )
        ) {
            router.delete(route('competencies.destroy', competency.id), {
                preserveScroll: true,
            });
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <p className="text-sm font-medium text-emerald-600">
                            Klasifikasi Bank Soal
                        </p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">
                            Kompetensi
                        </h1>
                    </div>
                    <Link
                        href={route('competencies.create')}
                        className="inline-flex justify-center rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-500"
                    >
                        Tambah kompetensi
                    </Link>
                </div>
            }
        >
            <Head title="Kompetensi" />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <form
                    onSubmit={filter}
                    className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[1fr_180px_auto]"
                >
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Cari kode, nama, atau domain…"
                        className="rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                    />
                    <select
                        value={gradeLevel}
                        onChange={(event) => setGradeLevel(event.target.value)}
                        className="rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                    >
                        <option value="">Semua kelas</option>
                        <option value="5">Kelas 5</option>
                        <option value="8">Kelas 8</option>
                        <option value="11">Kelas 11</option>
                    </select>
                    <div className="flex gap-2">
                        <button
                            type="submit"
                            className="flex-1 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white"
                        >
                            Terapkan
                        </button>
                        {(filters.search || filters.grade_level) && (
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-600"
                            >
                                Reset
                            </button>
                        )}
                    </div>
                </form>

                <div className="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    {competencies.length === 0 ? (
                        <div className="p-12 text-center">
                            <p className="font-semibold text-slate-800">
                                Belum ada kompetensi
                            </p>
                            <p className="mt-1 text-sm text-slate-500">
                                Tambahkan kompetensi pertama untuk digunakan pada
                                bank soal.
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50">
                                    <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <th className="px-5 py-4">Kompetensi</th>
                                        <th className="px-5 py-4">Domain</th>
                                        <th className="px-5 py-4">Jenjang</th>
                                        <th className="px-5 py-4">Penggunaan</th>
                                        <th className="px-5 py-4 text-right">
                                            Aksi
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {competencies.map((competency) => (
                                        <tr
                                            key={competency.id}
                                            className="align-top hover:bg-slate-50/70"
                                        >
                                            <td className="px-5 py-4">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="rounded-lg bg-indigo-50 px-2.5 py-1 font-mono text-xs font-bold text-indigo-700">
                                                        {competency.code}
                                                    </span>
                                                    {!competency.can_manage && (
                                                        <span className="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-500">
                                                            Global
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="mt-2 font-semibold text-slate-900">
                                                    {competency.name}
                                                </p>
                                                {competency.parent && (
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        Induk:{' '}
                                                        {competency.parent.code} ·{' '}
                                                        {competency.parent.name}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-5 py-4 text-sm text-slate-600">
                                                {competency.domain}
                                            </td>
                                            <td className="px-5 py-4 text-sm text-slate-600">
                                                Kelas {competency.grade_level}
                                            </td>
                                            <td className="px-5 py-4 text-sm text-slate-600">
                                                <p>
                                                    {competency.questions_count}{' '}
                                                    soal
                                                </p>
                                                {competency.children_count > 0 && (
                                                    <p className="mt-1 text-xs text-slate-400">
                                                        {
                                                            competency.children_count
                                                        }{' '}
                                                        turunan
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-5 py-4">
                                                {competency.can_manage ? (
                                                    <div className="flex justify-end gap-2">
                                                        <Link
                                                            href={route(
                                                                'competencies.edit',
                                                                competency.id,
                                                            )}
                                                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                                                        >
                                                            Edit
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                remove(competency)
                                                            }
                                                            className="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 hover:bg-rose-50"
                                                        >
                                                            Hapus
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <p className="text-right text-xs text-slate-400">
                                                        Hanya baca
                                                    </p>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
