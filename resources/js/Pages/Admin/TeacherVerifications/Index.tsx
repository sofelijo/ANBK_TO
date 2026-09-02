import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Teacher = {
    id: number;
    name: string;
    email: string;
    verification_count: number;
    published_contribution_count: number;
    last_verified_at?: string | null;
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
        totalVerifications: number;
        activeTeachers: number;
        totalTeachers: number;
        teacherParticipationRate: number;
        questionsVerified: number;
        publishedQuestions: number;
    };
    trend: { date: string; label: string; count: number }[];
    filters: { period: string; subdistrict: string; search: string };
    subdistricts: string[];
};

const numberFormatter = new Intl.NumberFormat('id-ID');

export default function Index({ teachers, stats, trend, filters, subdistricts }: Props) {
    const [period, setPeriod] = useState(filters.period);
    const [subdistrict, setSubdistrict] = useState(filters.subdistrict);
    const [search, setSearch] = useState(filters.search);
    const maxTrend = Math.max(...trend.map((item) => item.count), 1);

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            route('admin.teacher-verifications.index'),
            { period, subdistrict, search },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-sm font-semibold text-emerald-600">Kontrol mutu soal</p>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900">Analisis Verifikasi Guru</h1>
                    <p className="mt-1 text-sm text-slate-500">Pantau kontribusi guru dalam proses verifikasi minimal tiga orang per soal.</p>
                </div>
            }
        >
            <Head title="Analisis Verifikasi Guru" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={applyFilters} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[180px_200px_1fr_auto] sm:items-end">
                    <label className="text-xs font-semibold text-slate-600">
                        Periode
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
                    <MetricCard label="Total verifikasi" value={stats.totalVerifications} detail={`${stats.questionsVerified} soal disentuh`} tone="emerald" />
                    <MetricCard label="Guru aktif verifikasi" value={stats.activeTeachers} detail={`Dari ${formatNumber(stats.totalTeachers)} guru`} tone="blue" />
                    <MetricCard label="Partisipasi guru" value={`${formatNumber(stats.teacherParticipationRate)}%`} detail="Guru yang berkontribusi" tone="amber" />
                    <MetricCard label="Soal sudah terbit" value={stats.publishedQuestions} detail="Memiliki kontribusi verifikasi" tone="violet" />
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 className="text-lg font-bold text-slate-900">Aktivitas verifikasi harian</h2>
                            <p className="text-sm text-slate-500">Pergerakan verifikasi dalam {trend.length} hari terakhir.</p>
                        </div>
                        <p className="text-xs font-medium text-slate-400">Satu guru hanya dihitung sekali untuk soal yang sama</p>
                    </div>
                    <div className="mt-6 flex h-48 items-end gap-1.5 sm:gap-3">
                        {trend.map((item) => (
                            <div key={item.date} className="group flex min-w-0 flex-1 flex-col items-center justify-end gap-2">
                                <span className="text-xs font-bold tabular-nums text-slate-700">{item.count}</span>
                                <div className="relative flex h-32 w-full items-end overflow-hidden rounded-t-md bg-slate-100">
                                    <div
                                        className="w-full rounded-t-md bg-emerald-500 transition group-hover:bg-emerald-400"
                                        style={{ height: `${item.count === 0 ? 0 : Math.max(8, (item.count / maxTrend) * 100)}%` }}
                                    />
                                </div>
                                <span className="hidden truncate text-[10px] text-slate-500 sm:block">{item.label}</span>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-200 p-5 sm:p-6">
                        <h2 className="text-lg font-bold text-slate-900">Peringkat kontribusi guru</h2>
                        <p className="mt-1 text-sm text-slate-500">Diurutkan dari jumlah verifikasi terbanyak pada periode terpilih.</p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3 text-center">Peringkat</th>
                                    <th className="px-5 py-3">Guru</th>
                                    <th className="px-5 py-3">Sekolah</th>
                                    <th className="px-5 py-3 text-right">Verifikasi</th>
                                    <th className="px-5 py-3 text-right">Soal terbit</th>
                                    <th className="px-5 py-3">Aktivitas terakhir</th>
                                    <th className="px-5 py-3">Kategori</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {teachers.data.map((teacher, index) => {
                                    const activity = activityLevel(teacher.verification_count);

                                    return (
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
                                            <td className="px-5 py-4 text-right text-xl font-bold tabular-nums text-slate-900">{formatNumber(teacher.verification_count)}</td>
                                            <td className="px-5 py-4 text-right font-semibold tabular-nums text-emerald-700">{formatNumber(teacher.published_contribution_count)}</td>
                                            <td className="whitespace-nowrap px-5 py-4 text-slate-600">{formatDate(teacher.last_verified_at)}</td>
                                            <td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${activity.className}`}>{activity.label}</span></td>
                                        </tr>
                                    );
                                })}
                                {teachers.data.length === 0 && (
                                    <tr><td colSpan={7} className="px-5 py-12 text-center text-slate-500">Tidak ada guru yang sesuai dengan filter.</td></tr>
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

                <div className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-900">
                    <span className="font-bold">Cara membaca:</span> “Verifikasi” adalah aksi guru pada soal, sedangkan “Soal terbit” adalah verifikasi guru tersebut yang berkontribusi pada soal yang akhirnya mencapai minimal tiga verifikator.
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function MetricCard({ label, value, detail, tone }: { label: string; value: number | string; detail: string; tone: 'emerald' | 'blue' | 'amber' | 'violet' }) {
    const colors = { emerald: 'bg-emerald-500', blue: 'bg-blue-500', amber: 'bg-amber-500', violet: 'bg-violet-500' };

    return (
        <article className="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <span className={`absolute inset-x-0 top-0 h-1 ${colors[tone]}`} />
            <p className="text-sm font-medium text-slate-500">{label}</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{typeof value === 'number' ? formatNumber(value) : value}</p>
            <p className="mt-2 text-xs text-slate-500">{detail}</p>
        </article>
    );
}

function activityLevel(count: number): { label: string; className: string } {
    if (count >= 15) return { label: 'Sangat aktif', className: 'bg-emerald-100 text-emerald-800' };
    if (count >= 5) return { label: 'Aktif', className: 'bg-blue-100 text-blue-800' };
    if (count >= 1) return { label: 'Mulai aktif', className: 'bg-amber-100 text-amber-800' };

    return { label: 'Belum aktif', className: 'bg-slate-100 text-slate-600' };
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
