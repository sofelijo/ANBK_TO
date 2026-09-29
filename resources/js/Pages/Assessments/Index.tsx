import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type SubCompetency = {
    id: number;
    parent_id: number;
    code: string;
    name: string;
    grade_level: number;
    subject_id: number | null;
};

type Assessment = {
    id: number;
    title: string;
    description?: string;
    grade_level: number;
    duration_minutes: number;
    status: string;
    subject_id?: number | null;
    competency_slots?: number[];
    questions_count: number;
    attempts_count: number;
    average_difficulty: number | null;
    subject_label: string;
    subject_kind: string;
    starts_at?: string;
    ends_at?: string;
    schedules_count?: number;
    schedules?: { id: number; school_npsn: string; starts_at: string; ends_at: string; session_number: number }[];
    settings?: { type?: 'regular' | 'together'; type_label?: string; selection_mode?: string };
    attempts?: { public_id: string; status: string }[];
    competency_coverage?: Record<number, number>;
    can_manage?: boolean;
    source_school?: string;
};

export default function Index({
    assessments,
    canManage,
    subCompetencies,
    subjects,
    filters,
}: {
    assessments: Assessment[];
    canManage: boolean;
    subCompetencies: SubCompetency[];
    subjects: { value: string; code: string; name: string }[];
    filters: { subject?: string; type?: '' | 'regular' | 'together' };
}) {
    const [difficultySort, setDifficultySort] = useState<'default' | 'easiest' | 'hardest'>('default');
    const displayedAssessments = useMemo(() => {
        if (difficultySort === 'default') return assessments;

        return [...assessments].sort((left, right) => {
            if (left.average_difficulty === null && right.average_difficulty === null) return 0;
            if (left.average_difficulty === null) return 1;
            if (right.average_difficulty === null) return -1;

            return difficultySort === 'easiest'
                ? left.average_difficulty - right.average_difficulty
                : right.average_difficulty - left.average_difficulty;
        });
    }, [assessments, difficultySort]);

    const difficultyLabel = (average: number | null) => {
        if (average === null) return 'Belum dihitung';
        if (average < 1.5) return 'Mudah';
        if (average < 2.5) return 'Sedang';
        return 'Sulit';
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-sm font-medium text-emerald-600">Pelaksanaan</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">
                            {canManage ? 'Paket Ujian' : 'Try Out Tersedia'}
                        </h1>
                    </div>
                    {canManage && (
                        <Link
                            href={route('assessments.create')}
                            className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white"
                        >
                            Buat paket
                        </Link>
                    )}
                </div>
            }
        >
            <Head title={canManage ? 'Paket Ujian' : 'Try Out'} />
            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-5 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-end sm:justify-between">
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <label className="text-sm font-semibold text-slate-700">
                            Mata pelajaran
                            <select value={filters.subject || ''} onChange={(event) => router.get(route('assessments.index'), { subject: event.target.value, type: filters.type || '' }, { preserveState: true, preserveScroll: true })} className="mt-1 block w-full min-w-0 sm:w-64 rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 sm:mt-2">
                                <option value="">Semua mata pelajaran</option>
                                {subjects.map((subject) => <option key={subject.value} value={subject.value}>{subject.code} · {subject.name}</option>)}
                                <option value="mixed">Campuran (lebih dari satu mapel)</option>
                            </select>
                        </label>
                        <label className="text-sm font-semibold text-slate-700">
                            Jenis try out
                            <select value={filters.type || ''} onChange={(event) => router.get(route('assessments.index'), { subject: filters.subject || '', type: event.target.value }, { preserveState: true, preserveScroll: true })} className="mt-1 block w-full min-w-0 sm:w-44 rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 sm:mt-2">
                                <option value="">Semua</option>
                                <option value="regular">Reguler</option>
                                <option value="together">Bersama</option>
                            </select>
                        </label>
                    </div>
                    {!canManage && assessments.length > 1 && (
                        <label className="text-sm font-semibold text-slate-700">
                            Urutkan berdasarkan level
                            <select value={difficultySort} onChange={(event) => setDifficultySort(event.target.value as typeof difficultySort)} className="mt-1 block rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 sm:mt-2">
                                <option value="default">Terbaru</option>
                                <option value="easiest">Termudah</option>
                                <option value="hardest">Tersulit</option>
                            </select>
                        </label>
                    )}
                </div>
                {assessments.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-slate-500">
                        Belum ada paket yang tersedia.
                    </div>
                ) : (
                    <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                        {displayedAssessments.map((assessment) => {
                            const attempt = assessment.attempts?.[0];
                            const canManageAssessment = canManage && assessment.can_manage !== false;
                            const isTogether = assessment.settings?.type === 'together';
                            const schoolSchedule = assessment.schedules?.[0];
                            const effectiveStart = isTogether ? schoolSchedule?.starts_at : assessment.starts_at;
                            const effectiveEnd = isTogether ? schoolSchedule?.ends_at : assessment.ends_at;
                            const startsInFuture = effectiveStart && new Date(effectiveStart).getTime() > Date.now();
                            const hasEnded = effectiveEnd && new Date(effectiveEnd).getTime() < Date.now();

                            // Sub-competency coverage for manage view
                            const slots = assessment.competency_slots ?? [];
                            const coverage = assessment.competency_coverage ?? {};
                            const relevantSubs = subCompetencies.filter(
                                (s) => s.grade_level === assessment.grade_level &&
                                       (!assessment.subject_id || s.subject_id === assessment.subject_id),
                            );
                            const coveredSlots = slots.filter((id) => (coverage[id] ?? 0) > 0);
                            const missingSlots = slots.filter((id) => !(coverage[id] > 0));
                            const hasSlots = slots.length > 0;

                            return (
                                <article
                                    key={assessment.id}
                                    className="flex flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <span
                                            className={`rounded-full px-2.5 py-1 text-xs font-semibold ${assessment.status === 'published' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}
                                        >
                                            {assessment.status}
                                        </span>
                                        <span className="text-xs text-slate-500">Kelas {assessment.grade_level}</span>
                                    </div>

                                    <h2 className="mt-4 text-lg font-semibold text-slate-900">{assessment.title}</h2>
                                    <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-indigo-600">
                                        {assessment.settings?.type_label || 'Try Out Reguler'}
                                    </p>
                                    {!canManageAssessment && assessment.source_school && <p className="mt-1 text-xs text-slate-400">Disediakan oleh {assessment.source_school}</p>}
                                    <span className={`mt-2 w-fit rounded-full px-2.5 py-1 text-xs font-semibold ${assessment.subject_kind === 'mixed' ? 'bg-fuchsia-50 text-fuchsia-700' : 'bg-blue-50 text-blue-700'}`}>{assessment.subject_label}</span>
                                    <p className="mt-2 flex-1 text-sm leading-6 text-slate-500">
                                        {assessment.description || 'Paket Try Out Adaptif.'}
                                    </p>

                                    {!canManage && isTogether && (
                                        <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                                            Saat ini jam operasional sekolah. Try out hanya dapat dikerjakan jika guru sudah mengambil jadwal untuk NPSN sekolahmu.
                                        </div>
                                    )}

                                    {/* Sub-competency coverage badge (manager only) */}
                                    {canManageAssessment && hasSlots && (
                                        <div className="mt-4 rounded-xl border border-slate-100 bg-slate-50 p-3">
                                            <div className="mb-2 flex items-center justify-between">
                                                <span className="text-xs font-semibold text-slate-600">
                                                    Cakupan sub-kompetensi
                                                </span>
                                                <span
                                                    className={`text-xs font-bold ${coveredSlots.length === slots.length ? 'text-emerald-600' : 'text-amber-600'}`}
                                                >
                                                    {coveredSlots.length}/{slots.length}
                                                </span>
                                            </div>
                                            {/* Progress bar */}
                                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-200">
                                                <div
                                                    className={`h-full rounded-full transition-all ${coveredSlots.length === slots.length ? 'bg-emerald-500' : 'bg-amber-400'}`}
                                                    style={{ width: `${Math.round((coveredSlots.length / slots.length) * 100)}%` }}
                                                />
                                            </div>
                                            {/* Missing sub-competencies */}
                                            {missingSlots.length > 0 && (
                                                <div className="mt-2 space-y-1">
                                                    {missingSlots.slice(0, 3).map((id) => {
                                                        const sub = relevantSubs.find((s) => s.id === id);
                                                        if (!sub) return null;
                                                        const displayName = sub.name.length > 20 ? sub.name.substring(0, 20) + '…' : sub.name;
                                                        return (
                                                            <span
                                                                key={id}
                                                                className="mr-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700"
                                                                title={sub.name}
                                                            >
                                                                {displayName}
                                                            </span>
                                                        );
                                                    })}
                                                    {missingSlots.length > 3 && (
                                                        <span className="text-xs text-slate-400">
                                                            +{missingSlots.length - 3} lagi belum ada soal
                                                        </span>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    <div className="mt-5 flex gap-4 border-t border-slate-100 pt-4 text-xs text-slate-500">
                                        <span>{assessment.questions_count} soal</span>
                                        <span>{assessment.duration_minutes} menit</span>
                                        <span title="Rata-rata level seluruh soal yang terpasang pada paket">
                                            Level {assessment.average_difficulty?.toFixed(2) ?? '-'} · {difficultyLabel(assessment.average_difficulty)}
                                        </span>
                                        {canManageAssessment && (assessment.schedules_count || 0) > 0 && (
                                            <span>{assessment.schedules_count} sekolah terjadwal</span>
                                        )}
                                    </div>

                                    {(effectiveStart || effectiveEnd) && (
                                        <p className="mt-3 text-xs text-slate-500">
                                            {schoolSchedule && `Sesi ${schoolSchedule.session_number} · `}
                                            {effectiveStart
                                                ? `Mulai ${new Date(effectiveStart).toLocaleString('id-ID')}`
                                                : 'Tersedia sekarang'}
                                            {effectiveEnd
                                                ? ` · Tutup ${new Date(effectiveEnd).toLocaleString('id-ID')}`
                                                : ''}
                                        </p>
                                    )}

                                    {canManageAssessment ? (
                                        <div className="mt-4 flex flex-wrap gap-2">
                                            <Link
                                                href={route('assessments.show', assessment.id)}
                                                className="flex-1 rounded-lg bg-emerald-50 px-3 py-2 text-center text-sm font-semibold text-emerald-700 hover:bg-emerald-100"
                                            >
                                                Lihat Detail & Soal
                                            </Link>
                                            <Link
                                                href={route('assessments.edit', assessment.id)}
                                                className="rounded-lg border border-slate-300 px-3 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                            >
                                                Edit
                                            </Link>
                                            {assessment.status !== 'published' && (
                                                <button
                                                    onClick={() => router.post(route('assessments.publish', assessment.id))}
                                                    className="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                                                >
                                                    Terbitkan
                                                </button>
                                            )}
                                        </div>
                                    ) : canManage ? (
                                        <Link
                                            href={route('assessments.show', assessment.id)}
                                            className="mt-4 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2 text-center text-sm font-semibold text-indigo-700 hover:bg-indigo-100"
                                        >
                                            Lihat paket bersama
                                        </Link>
                                    ) : isTogether && !schoolSchedule ? (
                                        <button
                                            disabled
                                            className="mt-4 rounded-lg bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-800"
                                        >
                                            Guru belum mengambil jadwal
                                        </button>
                                    ) : startsInFuture ? (
                                        <button
                                            disabled
                                            className="mt-4 rounded-lg bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-800"
                                        >
                                            Belum dimulai
                                        </button>
                                    ) : hasEnded ? (
                                        <button
                                            disabled
                                            className="mt-4 rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-500"
                                        >
                                            Sudah ditutup
                                        </button>
                                    ) : attempt?.status === 'submitted' ? (
                                        <Link
                                            href={route('attempts.result', attempt.public_id)}
                                            className="mt-4 rounded-lg border border-emerald-600 px-4 py-2 text-center text-sm font-semibold text-emerald-700"
                                        >
                                            Lihat hasil
                                        </Link>
                                    ) : attempt ? (
                                        <Link
                                            href={route('attempts.show', attempt.public_id)}
                                            className="mt-4 rounded-lg bg-emerald-600 px-4 py-2 text-center text-sm font-semibold text-white"
                                        >
                                            Lanjutkan
                                        </Link>
                                    ) : (
                                        <button
                                            onClick={() => router.post(route('attempts.start', assessment.id))}
                                            className="mt-4 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white"
                                        >
                                            Mulai try out
                                        </button>
                                    )}
                                </article>
                            );
                        })}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
