import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

type Assessment = {
    id: number;
    title: string;
    gradeLevel: number;
    startsAt: string | null;
    endsAt: string | null;
    participantCount: number;
    type: 'regular' | 'together';
    typeLabel: string;
    schoolName: string | null;
};

type Ranking = {
    rank: number;
    student: { id: number; name: string; identifier: string | null; gradeLevel: number | null };
    school: { name: string; npsn: string | null; subdistrict: string | null };
    packagesCompleted: number;
    averageScore: number;
    highestScore: number;
    averageDurationSeconds: number;
};

type DistrictRanking = {
    rank: number;
    name: string;
    participants: number;
    schools: number;
    packagesCompleted: number;
    averageScore: number;
    highestScore: number;
};

type SchoolRanking = {
    rank: number;
    school: { name: string; npsn: string | null; subdistrict: string | null };
    participants: number;
    packagesCompleted: number;
    averageScore: number;
    highestScore: number;
    averageDurationSeconds: number;
};

type Props = {
    assessments: Assessment[];
    selectedAssessment: Assessment | null;
    selectedSubdistrict: string | null;
    subdistricts: string[];
    rankings: Ranking[];
    schoolRankings: SchoolRanking[];
    districtRankings: DistrictRanking[];
    cumulativeAssessmentCount: number;
};

const numberFormatter = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
const PAGE_SIZE = 10;
type RankOrder = 'highest' | 'lowest';

export default function Index({
    assessments,
    selectedAssessment,
    selectedSubdistrict,
    subdistricts,
    rankings,
    schoolRankings,
    districtRankings,
    cumulativeAssessmentCount,
}: Props) {
    const [rankOrder, setRankOrder] = useState<RankOrder>('highest');
    const [schoolLimit, setSchoolLimit] = useState(PAGE_SIZE);
    const [studentLimit, setStudentLimit] = useState(PAGE_SIZE);
    const [districtLimit, setDistrictLimit] = useState(PAGE_SIZE);

    useEffect(() => {
        setSchoolLimit(PAGE_SIZE);
        setStudentLimit(PAGE_SIZE);
        setDistrictLimit(PAGE_SIZE);
    }, [rankOrder, selectedAssessment?.id, selectedSubdistrict]);

    const orderedSchools = useMemo(
        () => reorderRanking(schoolRankings, rankOrder),
        [rankOrder, schoolRankings],
    );
    const orderedStudents = useMemo(
        () => reorderRanking(rankings, rankOrder),
        [rankOrder, rankings],
    );
    const orderedDistricts = useMemo(
        () => reorderRanking(districtRankings, rankOrder),
        [districtRankings, rankOrder],
    );

    const updateFilters = (assessmentId: number | null, subdistrict: string | null) => {
        router.get(
            route('rankings.index'),
            {
                ...(assessmentId ? { assessment_id: assessmentId } : {}),
                ...(subdistrict ? { subdistrict } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const average = rankings.length > 0
        ? rankings.reduce((total, row) => total + row.averageScore, 0) / rankings.length
        : 0;

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-sm font-semibold text-indigo-600">Try Out Bersama</p>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900">Ranking Try Out Bersama</h1>
                    <p className="mt-1 text-sm text-slate-500">Peringkat lengkap sekolah, peserta, dan kecamatan berdasarkan hasil paket bersama.</p>
                </div>
            }
        >
            <Head title="Ranking Try Out Bersama" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div>
                    <Link href={route('dashboard')} className="text-sm font-semibold text-indigo-600 hover:text-indigo-800">
                        ← Kembali ke dashboard
                    </Link>
                </div>

                {assessments.length > 0 ? (
                    <>
                        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                            <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(200px,1fr)_minmax(200px,1fr)]">
                                <label className="text-sm font-semibold text-slate-700">
                                    Paket Try Out
                                    <select
                                        value={selectedAssessment?.id ?? ''}
                                        onChange={(event) => updateFilters(event.target.value ? Number(event.target.value) : null, null)}
                                        className="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="">Semua paket · rerata kumulatif</option>
                                        {assessments.map((assessment) => (
                                            <option key={assessment.id} value={assessment.id}>
                                                [{assessment.typeLabel}] {assessment.title} · Kelas {assessment.gradeLevel} · {formatNumber(assessment.participantCount)} peserta{assessment.type === 'regular' && assessment.schoolName ? ` · ${assessment.schoolName}` : ''}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="text-sm font-semibold text-slate-700">
                                    Urutan ranking
                                    <select
                                        value={rankOrder}
                                        onChange={(event) => setRankOrder(event.target.value as RankOrder)}
                                        className="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="highest">Nilai tertinggi</option>
                                        <option value="lowest">Nilai terendah</option>
                                    </select>
                                </label>
                                <label className="text-sm font-semibold text-slate-700">
                                    Lingkup ranking
                                    <select
                                        value={selectedSubdistrict ?? ''}
                                        onChange={(event) => updateFilters(selectedAssessment?.id ?? null, event.target.value || null)}
                                        className="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="">Semua kecamatan</option>
                                        {subdistricts.map((subdistrict) => (
                                            <option key={subdistrict} value={subdistrict}>{subdistrict}</option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <p className="mt-4 text-xs text-slate-500">
                                {selectedAssessment
                                    ? 'Mode satu paket: posisi diurutkan berdasarkan nilai paket yang dipilih.'
                                    : `Mode kumulatif: rerata dari ${formatNumber(cumulativeAssessmentCount)} paket Try Out Bersama, dengan bobot yang sama untuk setiap paket.`}
                                {selectedAssessment?.startsAt && ` Periode paket dimulai ${formatDate(selectedAssessment.startsAt)}.`}
                            </p>
                        </section>

                        <section className="grid gap-4 sm:grid-cols-3">
                            <SummaryCard label="Sekolah dalam ranking" value={formatNumber(schoolRankings.length)} />
                            <SummaryCard label="Peserta dalam ranking" value={formatNumber(rankings.length)} />
                            <SummaryCard label="Rerata nilai" value={rankings.length > 0 ? formatPercent(average) : '—'} />
                        </section>

                        <section className="overflow-hidden rounded-2xl border border-indigo-200 bg-white shadow-sm">
                            <div className="border-b border-indigo-100 bg-indigo-50/60 px-5 py-5 sm:px-6">
                                <h2 className="text-lg font-bold text-slate-900">
                                    Ranking sekolah {selectedSubdistrict ? `Kecamatan ${selectedSubdistrict}` : 'seluruh kecamatan'}
                                </h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    {selectedAssessment
                                        ? `Hasil khusus paket ${selectedAssessment.title}.`
                                        : 'Setiap paket dihitung reratanya terlebih dahulu, lalu seluruh rerata paket digabung dengan bobot yang sama.'}
                                </p>
                            </div>
                            <div className={`overflow-auto ${orderedSchools.length > PAGE_SIZE ? 'h-[46rem]' : ''}`}>
                                <table className="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead className="sticky top-0 z-10 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 shadow-sm">
                                        <tr>
                                            <th className="px-5 py-3 text-center">Rank</th>
                                            <th className="px-5 py-3">Sekolah</th>
                                            <th className="px-5 py-3">Kecamatan</th>
                                            <th className="px-5 py-3 text-right">Peserta</th>
                                            <th className="px-5 py-3 text-right">Paket</th>
                                            <th className="px-5 py-3 text-right">Rerata nilai</th>
                                            <th className="px-5 py-3 text-right">Nilai tertinggi</th>
                                            <th className="px-5 py-3 text-right">Rerata durasi</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {orderedSchools.slice(0, schoolLimit).map((row) => (
                                            <tr key={row.school.npsn ?? row.school.name} className="hover:bg-slate-50/80">
                                                <td className="px-5 py-4 text-center"><RankBadge rank={row.rank} /></td>
                                                <td className="px-5 py-4">
                                                    <p className="font-semibold text-slate-900">{row.school.name}</p>
                                                    <p className="mt-0.5 text-xs text-slate-500">NPSN {row.school.npsn ?? '—'}</p>
                                                </td>
                                                <td className="px-5 py-4 text-slate-600">{row.school.subdistrict ?? 'Belum diisi'}</td>
                                                <td className="px-5 py-4 text-right tabular-nums text-slate-600">{formatNumber(row.participants)}</td>
                                                <td className="px-5 py-4 text-right tabular-nums text-slate-600">{formatNumber(row.packagesCompleted)}</td>
                                                <td className="px-5 py-4 text-right text-base font-bold tabular-nums text-indigo-700">{formatPercent(row.averageScore)}</td>
                                                <td className="px-5 py-4 text-right font-semibold tabular-nums text-slate-800">{formatPercent(row.highestScore)}</td>
                                                <td className="px-5 py-4 text-right tabular-nums text-slate-600">{formatDuration(row.averageDurationSeconds)}</td>
                                            </tr>
                                        ))}
                                        {schoolRankings.length === 0 && (
                                            <tr><td colSpan={8} className="px-5 py-14 text-center text-slate-500">Belum ada sekolah pada lingkup ini.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            <LoadMore
                                shown={Math.min(schoolLimit, orderedSchools.length)}
                                total={orderedSchools.length}
                                onLoadMore={() => setSchoolLimit((value) => value + PAGE_SIZE)}
                            />
                        </section>

                        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <div className="border-b border-slate-200 px-5 py-5 sm:px-6">
                                <h2 className="text-lg font-bold text-slate-900">
                                    Ranking individu {selectedSubdistrict ? `Kecamatan ${selectedSubdistrict}` : 'seluruh kecamatan'}
                                </h2>
                                <p className="mt-1 text-sm text-slate-500">{selectedAssessment?.title ?? `Rerata kumulatif ${cumulativeAssessmentCount} paket bersama`}</p>
                            </div>
                            <div className={`overflow-auto ${orderedStudents.length > PAGE_SIZE ? 'h-[46rem]' : ''}`}>
                                <table className="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead className="sticky top-0 z-10 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 shadow-sm">
                                        <tr>
                                            <th className="px-5 py-3 text-center">Rank</th>
                                            <th className="px-5 py-3">Siswa</th>
                                            <th className="px-5 py-3">Sekolah</th>
                                            <th className="px-5 py-3">Kecamatan</th>
                                            <th className="px-5 py-3 text-right">Paket</th>
                                            <th className="px-5 py-3 text-right">Rerata nilai</th>
                                            <th className="px-5 py-3 text-right">Nilai tertinggi</th>
                                            <th className="px-5 py-3 text-right">Rerata durasi</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {orderedStudents.slice(0, studentLimit).map((row) => (
                                            <tr key={row.student.id} className="hover:bg-slate-50/80">
                                                <td className="px-5 py-4 text-center"><RankBadge rank={row.rank} /></td>
                                                <td className="px-5 py-4">
                                                    <p className="font-semibold text-slate-900">{row.student.name}</p>
                                                    <p className="mt-0.5 text-xs text-slate-500">NISN {row.student.identifier ?? '—'} · Kelas {row.student.gradeLevel ?? '—'}</p>
                                                </td>
                                                <td className="px-5 py-4">
                                                    <p className="font-medium text-slate-800">{row.school.name}</p>
                                                    <p className="mt-0.5 text-xs text-slate-500">NPSN {row.school.npsn ?? '—'}</p>
                                                </td>
                                                <td className="px-5 py-4 text-slate-600">{row.school.subdistrict ?? 'Belum diisi'}</td>
                                                <td className="px-5 py-4 text-right tabular-nums text-slate-600">{formatNumber(row.packagesCompleted)}</td>
                                                <td className="px-5 py-4 text-right text-base font-bold tabular-nums text-indigo-700">{formatPercent(row.averageScore)}</td>
                                                <td className="px-5 py-4 text-right font-semibold tabular-nums text-slate-700">{formatPercent(row.highestScore)}</td>
                                                <td className="px-5 py-4 text-right tabular-nums text-slate-600">{formatDuration(row.averageDurationSeconds)}</td>
                                            </tr>
                                        ))}
                                        {rankings.length === 0 && (
                                            <tr><td colSpan={8} className="px-5 py-14 text-center text-slate-500">Belum ada peserta pada lingkup ini.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            <LoadMore
                                shown={Math.min(studentLimit, orderedStudents.length)}
                                total={orderedStudents.length}
                                onLoadMore={() => setStudentLimit((value) => value + PAGE_SIZE)}
                            />
                        </section>

                        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <div className="border-b border-slate-200 px-5 py-5 sm:px-6">
                                <h2 className="text-lg font-bold text-slate-900">Ranking per kecamatan</h2>
                                <p className="mt-1 text-sm text-slate-500">{selectedAssessment ? `Rerata seluruh peserta pada paket ${selectedAssessment.title}.` : 'Rerata kumulatif seluruh paket Try Out Bersama.'}</p>
                            </div>
                            <div className={`overflow-auto ${orderedDistricts.length > PAGE_SIZE ? 'h-[46rem]' : ''}`}>
                                <table className="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead className="sticky top-0 z-10 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 shadow-sm">
                                        <tr>
                                            <th className="px-5 py-3 text-center">Rank</th>
                                            <th className="px-5 py-3">Kecamatan</th>
                                            <th className="px-5 py-3 text-right">Sekolah</th>
                                            <th className="px-5 py-3 text-right">Peserta</th>
                                            <th className="px-5 py-3 text-right">Paket</th>
                                            <th className="px-5 py-3 text-right">Rerata nilai</th>
                                            <th className="px-5 py-3 text-right">Nilai tertinggi</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {orderedDistricts.slice(0, districtLimit).map((district) => (
                                            <tr key={district.name}>
                                                <td className="px-5 py-4 text-center"><RankBadge rank={district.rank} /></td>
                                                <td className="px-5 py-4 font-semibold text-slate-900">{district.name}</td>
                                                <td className="px-5 py-4 text-right tabular-nums text-slate-600">{formatNumber(district.schools)}</td>
                                                <td className="px-5 py-4 text-right tabular-nums text-slate-600">{formatNumber(district.participants)}</td>
                                                <td className="px-5 py-4 text-right tabular-nums text-slate-600">{formatNumber(district.packagesCompleted)}</td>
                                                <td className="px-5 py-4 text-right font-bold tabular-nums text-indigo-700">{district.participants > 0 ? formatPercent(district.averageScore) : '—'}</td>
                                                <td className="px-5 py-4 text-right font-semibold tabular-nums text-slate-800">{district.participants > 0 ? formatPercent(district.highestScore) : '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <LoadMore
                                shown={Math.min(districtLimit, orderedDistricts.length)}
                                total={orderedDistricts.length}
                                onLoadMore={() => setDistrictLimit((value) => value + PAGE_SIZE)}
                            />
                        </section>
                    </>
                ) : (
                    <section className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
                        <h2 className="text-lg font-bold text-slate-900">Belum ada hasil Try Out Bersama</h2>
                        <p className="mt-2 text-sm text-slate-500">Halaman ranking akan terisi setelah siswa menyelesaikan paket bersama.</p>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function reorderRanking<T extends { rank: number }>(rows: T[], order: RankOrder): T[] {
    return order === 'highest' ? [...rows] : [...rows].reverse();
}

function LoadMore({ shown, total, onLoadMore }: { shown: number; total: number; onLoadMore: () => void }) {
    if (total === 0) return null;

    return (
        <div className="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 text-sm sm:flex-row sm:items-center sm:justify-between">
            <p className="text-slate-500">Menampilkan {formatNumber(shown)} dari {formatNumber(total)} peringkat.</p>
            {shown < total && (
                <button
                    type="button"
                    onClick={onLoadMore}
                    className="w-fit rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2 font-semibold text-indigo-700 transition hover:bg-indigo-100"
                >
                    Muat 10 lagi
                </button>
            )}
        </div>
    );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
    return (
        <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p className="text-sm font-medium text-slate-500">{label}</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{value}</p>
        </article>
    );
}

function RankBadge({ rank }: { rank: number }) {
    const tone = rank === 1
        ? 'bg-amber-100 text-amber-800 ring-amber-200'
        : rank === 2
          ? 'bg-slate-200 text-slate-700 ring-slate-300'
          : rank === 3
            ? 'bg-orange-100 text-orange-800 ring-orange-200'
            : 'bg-indigo-50 text-indigo-700 ring-indigo-100';

    return <span className={`inline-flex h-9 min-w-9 items-center justify-center rounded-full px-2 font-bold ring-1 ${tone}`}>{rank}</span>;
}

function formatNumber(value: number): string {
    return numberFormatter.format(value);
}

function formatPercent(value: number): string {
    return `${numberFormatter.format(value)}%`;
}

function formatDuration(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainingSeconds = seconds % 60;

    return hours > 0
        ? `${hours}j ${minutes}m ${remainingSeconds}d`
        : `${minutes}m ${remainingSeconds}d`;
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' }).format(new Date(value));
}
