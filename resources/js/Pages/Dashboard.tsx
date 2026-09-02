import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type DashboardMode = 'admin' | 'teacher' | 'operator' | 'student';
type DashboardStats = Record<string, number>;

type DistrictMetrics = {
    name: string;
    schools: number;
    students: number;
    participants: number;
    completedAttempts: number;
    participationRate: number;
    averageScore: number;
};

type SchoolMetrics = {
    id: number;
    name: string;
    npsn: string;
    subdistrict: string | null;
    students: number;
    participants: number;
    completedAttempts: number;
    participationRate: number;
    averageScore: number;
    scoredAttempts: number;
};

type SchoolIdentity = {
    name: string;
    npsn: string;
    subdistrict: string | null;
};

type RankingSchool = {
    rank: number;
    school: { name: string; npsn: string | null; subdistrict: string | null };
    participants: number;
    packagesCompleted: number;
    averageScore: number;
    highestScore: number;
    averageDurationSeconds: number;
};

type RankingPreview = {
    assessmentCount: number;
    participantCount: number;
    schoolCount: number;
    schools: RankingSchool[];
};

type DashboardProps = {
    mode: DashboardMode;
    stats: DashboardStats;
    districts?: DistrictMetrics[];
    schools?: SchoolMetrics[];
    school?: SchoolIdentity;
    rankingPreview?: RankingPreview | null;
};

const numberFormatter = new Intl.NumberFormat('id-ID');

export default function Dashboard({
    mode,
    stats,
    districts = [],
    schools = [],
    school,
    rankingPreview,
}: DashboardProps) {
    const isRegionalDashboard = mode === 'admin';

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-sm font-semibold text-emerald-600">
                            {isRegionalDashboard
                                ? 'Suku Dinas Pendidikan Jakarta Utara Wilayah II'
                                : 'Ringkasan platform'}
                        </p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">
                            {isRegionalDashboard ? 'Dashboard Analisis Wilayah' : 'Dashboard TOA'}
                        </h1>
                        {isRegionalDashboard && (
                            <p className="mt-1 text-sm text-slate-500">
                                Membandingkan partisipasi dan capaian Kelapa Gading, Cilincing, serta Koja.
                            </p>
                        )}
                    </div>
                    {isRegionalDashboard && (
                        <div className="w-fit rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700">
                            Data hasil try out terkirim
                        </div>
                    )}
                </div>
            }
        >
            <Head title="Dashboard" />
            {isRegionalDashboard ? (
                <RegionalDashboard stats={stats} districts={districts} schools={schools} rankingPreview={rankingPreview} />
            ) : (
                <RoleDashboard mode={mode} stats={stats} school={school} rankingPreview={rankingPreview} />
            )}
        </AuthenticatedLayout>
    );
}

function RegionalDashboard({
    stats,
    districts,
    schools,
    rankingPreview,
}: {
    stats: DashboardStats;
    districts: DistrictMetrics[];
    schools: SchoolMetrics[];
    rankingPreview?: RankingPreview | null;
}) {
    const [subdistrict, setSubdistrict] = useState('all');
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState('attention');

    const filteredSchools = useMemo(() => {
        const normalizedSearch = search.trim().toLocaleLowerCase('id-ID');

        return schools
            .filter((school) => subdistrict === 'all' || school.subdistrict === subdistrict)
            .filter((school) =>
                normalizedSearch === ''
                    || school.name.toLocaleLowerCase('id-ID').includes(normalizedSearch)
                    || school.npsn.includes(normalizedSearch),
            )
            .sort((left, right) => {
                if (sort === 'score') {
                    return right.averageScore - left.averageScore || left.name.localeCompare(right.name, 'id-ID');
                }

                if (sort === 'name') {
                    return left.name.localeCompare(right.name, 'id-ID');
                }

                return left.participationRate - right.participationRate
                    || left.averageScore - right.averageScore
                    || left.name.localeCompare(right.name, 'id-ID');
            });
    }, [schools, search, sort, subdistrict]);

    const districtsWithResults = districts.filter((district) => district.completedAttempts > 0);
    const bestDistrict = [...districtsWithResults].sort((left, right) => right.averageScore - left.averageScore)[0];
    const districtsWithStudents = districts.filter((district) => district.students > 0);
    const attentionDistrict = [...districtsWithStudents].sort((left, right) => left.participationRate - right.participationRate)[0];

    return (
        <div className="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
            <RankingPreviewCard preview={rankingPreview} />
            {stats.unclassifiedSchools > 0 && (
                <div className="flex flex-col gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <span className="font-bold">{formatNumber(stats.unclassifiedSchools)} sekolah belum memiliki kecamatan.</span>{' '}
                        Minta operator melengkapinya pada menu Data Sekolah agar analisis wilayah akurat.
                    </div>
                    <span className="shrink-0 rounded-full bg-white px-3 py-1 text-xs font-semibold text-amber-700 shadow-sm">
                        Perlu dilengkapi
                    </span>
                </div>
            )}

            <section>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <MetricCard label="Sekolah terdaftar" value={formatNumber(stats.schools)} detail="Wilayah II" tone="emerald" />
                    <MetricCard label="Siswa terdaftar" value={formatNumber(stats.students)} detail={`${formatNumber(stats.participants)} sudah berpartisipasi`} tone="blue" />
                    <MetricCard label="Pengerjaan selesai" value={formatNumber(stats.completedAttempts)} detail="Semua paket try out" tone="violet" />
                    <MetricCard label="Partisipasi wilayah" value={formatPercent(stats.participationRate)} detail="Siswa unik yang mengirim" tone="amber" />
                    <MetricCard label="Rerata nilai" value={stats.completedAttempts > 0 ? formatPercent(stats.averageScore) : '—'} detail="Dari nilai maksimum" tone="rose" />
                </div>
            </section>

            <section>
                <div className="mb-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 className="text-lg font-bold text-slate-900">Perbandingan kecamatan</h2>
                        <p className="text-sm text-slate-500">Indikator dihitung dari siswa dan pengerjaan yang sudah tersimpan.</p>
                    </div>
                    <p className="text-xs text-slate-400">Partisipasi = siswa unik mengirim ÷ siswa terdaftar</p>
                </div>
                <div className="grid gap-4 lg:grid-cols-3">
                    {districts.map((district, index) => (
                        <DistrictCard key={district.name} district={district} index={index} />
                    ))}
                </div>
            </section>

            {(bestDistrict || attentionDistrict) && (
                <section className="rounded-2xl bg-slate-900 p-6 text-white shadow-sm">
                    <p className="text-xs font-bold uppercase tracking-[0.18em] text-emerald-400">Sorotan otomatis</p>
                    <div className="mt-4 grid gap-5 md:grid-cols-2">
                        <Insight
                            label="Capaian tertinggi"
                            value={bestDistrict ? bestDistrict.name : 'Belum tersedia'}
                            detail={bestDistrict ? `Rerata nilai ${formatPercent(bestDistrict.averageScore)} dari ${formatNumber(bestDistrict.completedAttempts)} pengerjaan selesai.` : 'Belum ada hasil try out yang dapat dibandingkan.'}
                        />
                        <Insight
                            label="Prioritas peningkatan partisipasi"
                            value={attentionDistrict ? attentionDistrict.name : 'Belum tersedia'}
                            detail={attentionDistrict ? `Partisipasi ${formatPercent(attentionDistrict.participationRate)}; ${formatNumber(attentionDistrict.students - attentionDistrict.participants)} siswa terdaftar belum mengirim try out.` : 'Belum ada siswa terdaftar.'}
                        />
                    </div>
                </section>
            )}

            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="border-b border-slate-200 p-5 sm:p-6">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <h2 className="text-lg font-bold text-slate-900">Analisis per sekolah</h2>
                            <p className="mt-1 text-sm text-slate-500">Urutkan sekolah yang perlu ditindaklanjuti atau cari berdasarkan nama dan NPSN.</p>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <label className="text-xs font-semibold text-slate-600">
                                Kecamatan
                                <select value={subdistrict} onChange={(event) => setSubdistrict(event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                    <option value="all">Semua kecamatan</option>
                                    {districts.map((district) => <option key={district.name} value={district.name}>{district.name}</option>)}
                                </select>
                            </label>
                            <label className="text-xs font-semibold text-slate-600">
                                Urutkan
                                <select value={sort} onChange={(event) => setSort(event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                    <option value="attention">Perlu perhatian</option>
                                    <option value="score">Nilai tertinggi</option>
                                    <option value="name">Nama sekolah</option>
                                </select>
                            </label>
                            <label className="text-xs font-semibold text-slate-600">
                                Cari sekolah
                                <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Nama / NPSN" className="mt-1 block w-full rounded-lg border-slate-300 text-sm" />
                            </label>
                        </div>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-3">Sekolah</th>
                                <th className="px-5 py-3">Kecamatan</th>
                                <th className="px-5 py-3 text-right">Siswa</th>
                                <th className="px-5 py-3 text-right">Pengerjaan</th>
                                <th className="px-5 py-3">Partisipasi</th>
                                <th className="px-5 py-3 text-right">Rerata nilai</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {filteredSchools.map((school) => (
                                <tr key={school.id} className="hover:bg-slate-50/80">
                                    <td className="px-5 py-4">
                                        <p className="font-semibold text-slate-900">{school.name}</p>
                                        <p className="mt-0.5 text-xs text-slate-500">NPSN {school.npsn}</p>
                                    </td>
                                    <td className="px-5 py-4">
                                        {school.subdistrict ? (
                                            <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{school.subdistrict}</span>
                                        ) : (
                                            <span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">Belum diisi</span>
                                        )}
                                    </td>
                                    <td className="px-5 py-4 text-right tabular-nums text-slate-700">{formatNumber(school.students)}</td>
                                    <td className="px-5 py-4 text-right tabular-nums text-slate-700">{formatNumber(school.completedAttempts)}</td>
                                    <td className="min-w-40 px-5 py-4">
                                        <ProgressBar value={school.participationRate} tone="emerald" />
                                    </td>
                                    <td className="px-5 py-4 text-right font-bold tabular-nums text-slate-900">
                                        {school.scoredAttempts > 0 ? formatPercent(school.averageScore) : '—'}
                                    </td>
                                </tr>
                            ))}
                            {filteredSchools.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-5 py-12 text-center text-slate-500">Tidak ada sekolah yang sesuai dengan filter.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                    Menampilkan {formatNumber(filteredSchools.length)} dari {formatNumber(schools.length)} sekolah.
                </div>
            </section>
        </div>
    );
}

function DistrictCard({ district, index }: { district: DistrictMetrics; index: number }) {
    const accents = [
        'border-t-emerald-500',
        'border-t-blue-500',
        'border-t-violet-500',
    ];

    return (
        <article className={`rounded-2xl border border-t-4 border-slate-200 bg-white p-5 shadow-sm ${accents[index % accents.length]}`}>
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Kecamatan</p>
                    <h3 className="mt-1 text-xl font-bold text-slate-900">{district.name}</h3>
                </div>
                <div className="rounded-xl bg-slate-100 px-3 py-2 text-center">
                    <p className="text-lg font-bold text-slate-900">{formatNumber(district.schools)}</p>
                    <p className="text-[10px] font-semibold uppercase text-slate-500">Sekolah</p>
                </div>
            </div>
            <div className="mt-5 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3">
                <div>
                    <p className="text-xs text-slate-500">Siswa</p>
                    <p className="mt-1 font-bold tabular-nums text-slate-900">{formatNumber(district.students)}</p>
                </div>
                <div>
                    <p className="text-xs text-slate-500">Pengerjaan selesai</p>
                    <p className="mt-1 font-bold tabular-nums text-slate-900">{formatNumber(district.completedAttempts)}</p>
                </div>
            </div>
            <div className="mt-5 space-y-4">
                <div>
                    <div className="mb-1.5 flex justify-between text-xs"><span className="font-medium text-slate-600">Partisipasi</span><span className="font-bold text-slate-900">{formatPercent(district.participationRate)}</span></div>
                    <ProgressBar value={district.participationRate} tone="emerald" hideLabel />
                </div>
                <div>
                    <div className="mb-1.5 flex justify-between text-xs"><span className="font-medium text-slate-600">Rerata nilai</span><span className="font-bold text-slate-900">{district.completedAttempts > 0 ? formatPercent(district.averageScore) : '—'}</span></div>
                    <ProgressBar value={district.averageScore} tone="blue" hideLabel />
                </div>
            </div>
        </article>
    );
}

function RoleDashboard({ mode, stats, school, rankingPreview }: { mode: Exclude<DashboardMode, 'admin'>; stats: DashboardStats; school?: SchoolIdentity; rankingPreview?: RankingPreview | null }) {
    const cards = mode === 'student'
        ? [
              { label: 'Try out tersedia', value: formatNumber(stats.availableAssessments), detail: 'Siap dikerjakan' },
              { label: 'Try out selesai', value: formatNumber(stats.completedAttempts), detail: 'Sudah dikirim' },
          ]
        : mode === 'operator'
          ? [
                { label: 'Jadwal mendatang', value: formatNumber(stats.upcomingSchedules), detail: 'Sesi aktif berikutnya' },
                { label: 'Total siswa', value: formatNumber(stats.students), detail: `${formatNumber(stats.schoolUsers)} pengguna sekolah` },
                { label: 'Pengerjaan selesai', value: formatNumber(stats.completedAttempts), detail: 'Semua paket try out' },
                { label: 'Partisipasi', value: formatPercent(stats.participationRate), detail: 'Siswa unik yang mengirim' },
                { label: 'Rerata nilai', value: stats.completedAttempts > 0 ? formatPercent(stats.averageScore) : '—', detail: 'Dari nilai maksimum' },
            ]
          : [
                { label: 'Total soal', value: formatNumber(stats.questions), detail: 'Bank soal sekolah' },
                { label: 'Soal terbit', value: formatNumber(stats.publishedQuestions), detail: 'Siap digunakan' },
                { label: 'Paket ujian', value: formatNumber(stats.assessments), detail: 'Total paket' },
                { label: 'Pengerjaan selesai', value: formatNumber(stats.completedAttempts), detail: 'Sudah dikirim siswa' },
            ];
    const schoolNeedsSubdistrict = mode === 'operator' && !school?.subdistrict;

    return (
        <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            {mode === 'operator' && school && (
                <div className={`mb-6 flex flex-col gap-3 rounded-2xl border px-5 py-4 sm:flex-row sm:items-center sm:justify-between ${schoolNeedsSubdistrict ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50'}`}>
                    <div>
                        <p className="font-bold text-slate-900">{school.name}</p>
                        <p className="mt-1 text-sm text-slate-600">NPSN {school.npsn} · {school.subdistrict ? `Kecamatan ${school.subdistrict}` : 'Kecamatan belum dilengkapi'}</p>
                    </div>
                    {schoolNeedsSubdistrict && (
                        <Link href={route('school.edit')} className="w-fit rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-500">Lengkapi kecamatan</Link>
                    )}
                </div>
            )}

            <div className={`grid gap-4 sm:grid-cols-2 ${mode === 'operator' ? 'lg:grid-cols-5' : 'lg:grid-cols-4'}`}>
                {cards.map((card, index) => (
                    <MetricCard key={card.label} label={card.label} value={card.value} detail={card.detail} tone={['emerald', 'blue', 'violet', 'amber', 'rose'][index] as MetricTone} />
                ))}
            </div>

            {mode === 'teacher' && (
                <div className="mt-8">
                    <RankingPreviewCard preview={rankingPreview} />
                </div>
            )}

            <div className="mt-8 rounded-2xl bg-slate-900 p-8 text-white">
                <h2 className="text-xl font-semibold">
                    {mode === 'student'
                        ? 'Siap mengukur kemampuanmu?'
                        : mode === 'operator'
                          ? schoolNeedsSubdistrict ? 'Lengkapi data wilayah sekolah' : 'Pastikan data dan jadwal sekolah selalu siap'
                          : 'Bangun bank soal berkualitas secara bertahap'}
                </h2>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                    {mode === 'student'
                        ? 'Kerjakan try out, lihat peta kompetensi, lalu lanjutkan dengan soal latihan yang paling relevan.'
                        : mode === 'operator'
                          ? schoolNeedsSubdistrict
                              ? 'Pilih Kelapa Gading, Cilincing, atau Koja agar sekolah masuk ke analisis Sudin Pendidikan Jakarta Utara Wilayah II.'
                              : 'Pantau partisipasi siswa, lengkapi identitas sekolah, dan ambil sesi try out sebelum jam operasional dimulai.'
                          : 'AI membantu membuat draf. Guru tetap menjadi peninjau dan penerbit akhir setiap soal.'}
                </p>
                <Link
                    href={mode === 'student' ? route('assessments.index') : mode === 'operator' ? (schoolNeedsSubdistrict ? route('school.edit') : route('schedules.index')) : route('questions.create')}
                    className="mt-5 inline-flex rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-400"
                >
                    {mode === 'student' ? 'Lihat try out' : mode === 'operator' ? (schoolNeedsSubdistrict ? 'Buka Data Sekolah' : 'Atur jadwal sekolah') : 'Buat soal pertama'}
                </Link>
            </div>
        </div>
    );
}

function RankingPreviewCard({ preview }: { preview?: RankingPreview | null }) {
    return (
        <section className="overflow-hidden rounded-2xl border border-indigo-200 bg-white shadow-sm">
            <div className="flex flex-col gap-4 bg-gradient-to-r from-indigo-950 to-indigo-800 px-5 py-5 text-white sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.18em] text-indigo-200">Ranking Sekolah · Try Out Bersama</p>
                    <h2 className="mt-1 text-xl font-bold">{preview ? `Rata-rata ${formatNumber(preview.assessmentCount)} paket bersama` : 'Belum ada hasil ranking'}</h2>
                    <p className="mt-1 text-sm text-indigo-200">
                        {preview ? `${formatNumber(preview.schoolCount)} sekolah · ${formatNumber(preview.participantCount)} peserta telah menyelesaikan paket ini.` : 'Ranking akan muncul setelah peserta menyelesaikan Try Out Bersama.'}
                    </p>
                </div>
                <a
                    href={route('rankings.index')}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex w-fit items-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-bold text-indigo-800 transition hover:bg-indigo-50"
                >
                    Lihat ranking lengkap
                    <span aria-hidden="true">↗</span>
                </a>
            </div>

            {preview && preview.schools.length > 0 && (
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-100 text-sm">
                        <thead className="bg-indigo-50/60 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-3 text-center">Rank</th>
                                <th className="px-5 py-3">Sekolah</th>
                                <th className="px-5 py-3">Kecamatan</th>
                                <th className="px-5 py-3 text-right">Peserta</th>
                                <th className="px-5 py-3 text-right">Paket</th>
                                <th className="px-5 py-3 text-right">Rerata nilai</th>
                                <th className="px-5 py-3 text-right">Nilai tertinggi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {preview.schools.map((row) => (
                                <tr key={row.school.npsn ?? row.school.name}>
                                    <td className="px-5 py-3 text-center"><RankBadge rank={row.rank} /></td>
                                    <td className="px-5 py-3">
                                        <p className="font-semibold text-slate-900">{row.school.name}</p>
                                        <p className="mt-0.5 text-xs text-slate-500">NPSN {row.school.npsn ?? '—'}</p>
                                    </td>
                                    <td className="px-5 py-3 text-slate-600">{row.school.subdistrict ?? '—'}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">{formatNumber(row.participants)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">{formatNumber(row.packagesCompleted)}</td>
                                    <td className="px-5 py-3 text-right text-base font-bold tabular-nums text-indigo-700">{formatPercent(row.averageScore)}</td>
                                    <td className="px-5 py-3 text-right font-semibold tabular-nums text-slate-700">{formatPercent(row.highestScore)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
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

    return <span className={`inline-flex h-8 min-w-8 items-center justify-center rounded-full px-2 font-bold ring-1 ${tone}`}>{rank}</span>;
}

type MetricTone = 'emerald' | 'blue' | 'violet' | 'amber' | 'rose';

function MetricCard({ label, value, detail, tone }: { label: string; value: string; detail: string; tone: MetricTone }) {
    const tones: Record<MetricTone, string> = {
        emerald: 'bg-emerald-500',
        blue: 'bg-blue-500',
        violet: 'bg-violet-500',
        amber: 'bg-amber-500',
        rose: 'bg-rose-500',
    };

    return (
        <article className="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <span className={`absolute inset-x-0 top-0 h-1 ${tones[tone]}`} />
            <p className="text-sm font-medium text-slate-500">{label}</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{value}</p>
            <p className="mt-2 text-xs leading-5 text-slate-500">{detail}</p>
        </article>
    );
}

function ProgressBar({ value, tone, hideLabel = false }: { value: number; tone: 'emerald' | 'blue'; hideLabel?: boolean }) {
    const safeValue = Math.max(0, Math.min(value, 100));

    return (
        <div className="flex items-center gap-3">
            <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                <div className={`h-full rounded-full ${tone === 'emerald' ? 'bg-emerald-500' : 'bg-blue-500'}`} style={{ width: `${safeValue}%` }} />
            </div>
            {!hideLabel && <span className="w-12 text-right text-xs font-bold tabular-nums text-slate-700">{formatPercent(value)}</span>}
        </div>
    );
}

function Insight({ label, value, detail }: { label: string; value: string; detail: string }) {
    return (
        <div className="border-l-2 border-emerald-400 pl-4">
            <p className="text-xs font-semibold text-slate-400">{label}</p>
            <p className="mt-1 text-lg font-bold">{value}</p>
            <p className="mt-1 text-sm leading-6 text-slate-300">{detail}</p>
        </div>
    );
}

function formatNumber(value: number | undefined): string {
    return numberFormatter.format(value ?? 0);
}

function formatPercent(value: number | undefined): string {
    return `${numberFormatter.format(value ?? 0)}%`;
}
