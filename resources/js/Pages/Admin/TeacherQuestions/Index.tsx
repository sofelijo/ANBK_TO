import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Teacher = {
    id: number;
    name: string;
    email: string;
    question_count: number;
    draft_count: number;
    review_count: number;
    published_count: number;
    archived_count: number;
    last_question_created_at?: string | null;
    school?: {
        name: string;
        npsn: string;
        subdistrict?: string | null;
    };
};

type Props = {
    teachers: {
        data: Teacher[];
        from: number | null;
        total: number;
        links: { url?: string; label: string; active: boolean }[];
    };
    stats: {
        totalQuestions: number;
        publishedQuestions: number;
        activeTeachers: number;
        totalTeachers: number;
        averageQuestions: number;
    };
    filters: { period: string; subdistrict: string; search: string };
    subdistricts: string[];
};

const numberFormatter = new Intl.NumberFormat('id-ID');

export default function Index({ teachers, stats, filters, subdistricts }: Props) {
    const [period, setPeriod] = useState(filters.period);
    const [subdistrict, setSubdistrict] = useState(filters.subdistrict);
    const [search, setSearch] = useState(filters.search);

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            route('admin.teacher-questions.index'),
            { period, subdistrict, search },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-sm font-semibold text-indigo-600">Produktivitas konten</p>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900">Statistik Soal Guru</h1>
                    <p className="mt-1 text-sm text-slate-500">Pantau jumlah soal yang telah dibuat oleh setiap guru.</p>
                </div>
            }
        >
            <Head title="Statistik Soal Guru" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={applyFilters} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[180px_200px_minmax(0,1fr)_auto] sm:items-end">
                    <label className="text-xs font-semibold text-slate-600">
                        Periode pembuatan
                        <select value={period} onChange={(event) => setPeriod(event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                            <option value="7">7 hari terakhir</option>
                            <option value="30">30 hari terakhir</option>
                            <option value="90">90 hari terakhir</option>
                            <option value="all">Semua waktu</option>
                        </select>
                    </label>
                    <label className="text-xs font-semibold text-slate-600">
                        Kecamatan
                        <select value={subdistrict} onChange={(event) => setSubdistrict(event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                            <option value="">Semua kecamatan</option>
                            {subdistricts.map((item) => <option key={item} value={item}>{item}</option>)}
                        </select>
                    </label>
                    <label className="text-xs font-semibold text-slate-600">
                        Cari guru atau sekolah
                        <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Nama, email, sekolah, atau NPSN" className="mt-1 block w-full rounded-lg border-slate-300 text-sm" />
                    </label>
                    <button className="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Terapkan</button>
                </form>

                <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard label="Total soal dibuat" value={stats.totalQuestions} detail="Oleh guru pada periode terpilih" tone="indigo" />
                    <MetricCard label="Soal terbit" value={stats.publishedQuestions} detail="Berstatus terbit saat ini" tone="emerald" />
                    <MetricCard label="Guru pembuat soal" value={stats.activeTeachers} detail={`Dari ${formatNumber(stats.totalTeachers)} guru`} tone="blue" />
                    <MetricCard label="Rata-rata per guru aktif" value={stats.averageQuestions} detail="Soal per guru yang berkontribusi" tone="amber" />
                </section>

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-200 p-5 sm:p-6">
                        <h2 className="text-lg font-bold text-slate-900">Jumlah soal per guru</h2>
                        <p className="mt-1 text-sm text-slate-500">Diurutkan dari jumlah soal terbanyak. Guru yang belum membuat soal tetap ditampilkan.</p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3 text-center">Peringkat</th>
                                    <th className="px-5 py-3">Guru</th>
                                    <th className="px-5 py-3">Sekolah</th>
                                    <th className="px-5 py-3 text-right">Total</th>
                                    <th className="px-5 py-3 text-right">Draf</th>
                                    <th className="px-5 py-3 text-right">Ditinjau</th>
                                    <th className="px-5 py-3 text-right">Terbit</th>
                                    <th className="px-5 py-3 text-right">Arsip</th>
                                    <th className="px-5 py-3">Terakhir membuat</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {teachers.data.map((teacher, index) => (
                                    <tr key={teacher.id} className="hover:bg-slate-50/80">
                                        <td className="px-5 py-4 text-center font-bold tabular-nums text-slate-500">#{(teachers.from ?? 1) + index}</td>
                                        <td className="px-5 py-4">
                                            <p className="font-semibold text-slate-900">{teacher.name}</p>
                                            <p className="mt-0.5 text-xs text-slate-500">{teacher.email}</p>
                                        </td>
                                        <td className="px-5 py-4">
                                            <p className="font-medium text-slate-800">{teacher.school?.name || 'Sekolah tidak tersedia'}</p>
                                            <p className="mt-0.5 text-xs text-slate-500">{teacher.school?.subdistrict ? `Kecamatan ${teacher.school.subdistrict}` : 'Kecamatan belum diisi'}</p>
                                        </td>
                                        <td className="px-5 py-4 text-right text-xl font-bold tabular-nums text-slate-900">{formatNumber(teacher.question_count)}</td>
                                        <td className="px-5 py-4 text-right font-semibold tabular-nums text-slate-600">{formatNumber(teacher.draft_count)}</td>
                                        <td className="px-5 py-4 text-right font-semibold tabular-nums text-amber-700">{formatNumber(teacher.review_count)}</td>
                                        <td className="px-5 py-4 text-right font-semibold tabular-nums text-emerald-700">{formatNumber(teacher.published_count)}</td>
                                        <td className="px-5 py-4 text-right font-semibold tabular-nums text-slate-400">{formatNumber(teacher.archived_count)}</td>
                                        <td className="whitespace-nowrap px-5 py-4 text-slate-600">{formatDate(teacher.last_question_created_at)}</td>
                                    </tr>
                                ))}
                                {teachers.data.length === 0 && (
                                    <tr><td colSpan={9} className="px-5 py-12 text-center text-slate-500">Tidak ada guru yang sesuai dengan filter.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4">
                        <p className="text-xs text-slate-500">Menampilkan {formatNumber(teachers.data.length)} dari {formatNumber(teachers.total)} guru.</p>
                        <div className="flex flex-wrap gap-1.5">
                            {teachers.links.map((link, index) => link.url ? (
                                <Link key={index} href={link.url} preserveScroll className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${link.active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                            ) : null)}
                        </div>
                    </div>
                </section>

                <div className="rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm leading-6 text-indigo-900">
                    <span className="font-bold">Catatan:</span> jumlah dihitung dari seluruh soal yang dibuat guru pada periode terpilih, termasuk soal yang kemudian diarsipkan atau digantikan oleh revisi.
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function MetricCard({ label, value, detail, tone }: { label: string; value: number; detail: string; tone: 'indigo' | 'emerald' | 'blue' | 'amber' }) {
    const colors = { indigo: 'bg-indigo-500', emerald: 'bg-emerald-500', blue: 'bg-blue-500', amber: 'bg-amber-500' };

    return (
        <article className="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <span className={`absolute inset-x-0 top-0 h-1 ${colors[tone]}`} />
            <p className="text-sm font-medium text-slate-500">{label}</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{formatNumber(value)}</p>
            <p className="mt-2 text-xs text-slate-500">{detail}</p>
        </article>
    );
}

function formatNumber(value: number): string {
    return numberFormatter.format(value);
}

function formatDate(value?: string | null): string {
    if (!value) return 'Belum ada';

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}
