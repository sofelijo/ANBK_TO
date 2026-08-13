import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';

type AssessmentOption = {
    id: number;
    title: string;
    grade_level: number;
    duration_minutes: number;
    attempts_count: number;
    in_progress_count: number;
    schedule?: {
        starts_at: string;
        ends_at: string;
        session_number: number;
    };
};

type CellStatus = 'correct' | 'incorrect' | 'unanswered';

type Monitor = {
    assessment: {
        id: number;
        title: string;
        grade_level: number;
        duration_minutes: number;
    };
    question_count: number;
    participant_count: number;
    in_progress_count: number;
    submitted_count: number;
    refreshed_at: string;
    rows: {
        attempt_id: number;
        student: {
            name: string;
            nisn?: string;
            grade_level?: number;
        };
        status: 'in_progress' | 'submitted';
        started_at: string;
        submitted_at?: string;
        answered_count: number;
        correct_count: number;
        incorrect_count: number;
        cells: {
            position: number;
            question_id: number;
            label: string;
            answered: boolean;
            status: CellStatus;
        }[];
    }[];
};

const formatTime = (value?: string) =>
    value
        ? new Intl.DateTimeFormat('id-ID', {
              hour: '2-digit',
              minute: '2-digit',
              second: '2-digit',
          }).format(new Date(value))
        : '-';

const statusStyles: Record<CellStatus, string> = {
    correct: 'border-emerald-200 bg-emerald-100 text-emerald-700',
    incorrect: 'border-rose-200 bg-rose-100 text-rose-700',
    unanswered: 'border-slate-200 bg-slate-50 text-slate-400',
};

const statusLabels: Record<CellStatus, string> = {
    correct: 'Benar',
    incorrect: 'Salah',
    unanswered: 'Belum dijawab',
};

const statusIcons: Record<CellStatus, string> = {
    correct: '✓',
    incorrect: '×',
    unanswered: '–',
};

export default function Index({
    assessments,
    selectedAssessmentId,
    monitor,
}: {
    assessments: AssessmentOption[];
    selectedAssessmentId?: number;
    monitor?: Monitor | null;
}) {
    useEffect(() => {
        if (!selectedAssessmentId) return;

        const refreshTimer = window.setInterval(() => {
            router.reload({
                only: ['monitor'],
            });
        }, 5000);

        return () => window.clearInterval(refreshTimer);
    }, [selectedAssessmentId]);

    const selectAssessment = (assessmentId: string) => {
        router.get(
            route('monitoring.index'),
            assessmentId ? { assessment_id: assessmentId } : {},
            { preserveState: true, replace: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <p className="text-sm font-medium text-emerald-600">
                            Pelaksanaan Sekolah
                        </p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">
                            Monitoring TO Serentak
                        </h1>
                    </div>
                    {monitor && (
                        <p className="text-xs text-slate-500">
                            Diperbarui otomatis · {formatTime(monitor.refreshed_at)}
                        </p>
                    )}
                </div>
            }
        >
            <Head title="Monitoring TO" />

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <label className="block text-sm font-semibold text-slate-700">
                        Pilih Try Out
                        <select
                            value={selectedAssessmentId || ''}
                            onChange={(event) => selectAssessment(event.target.value)}
                            className="mt-2 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                        >
                            {assessments.length === 0 && (
                                <option value="">Belum ada TO terjadwal atau dikerjakan</option>
                            )}
                            {assessments.map((assessment) => (
                                <option key={assessment.id} value={assessment.id}>
                                    {assessment.title} · Kelas {assessment.grade_level} · {assessment.in_progress_count} sedang mengerjakan
                                </option>
                            ))}
                        </select>
                    </label>
                </section>

                {!monitor ? (
                    <section className="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center">
                        <p className="font-semibold text-slate-800">
                            Belum ada aktivitas Try Out
                        </p>
                        <p className="mt-2 text-sm text-slate-500">
                            Ambil jadwal sekolah terlebih dahulu. Peserta akan muncul setelah mulai mengerjakan.
                        </p>
                    </section>
                ) : (
                    <>
                        <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <SummaryCard label="Peserta masuk" value={monitor.participant_count} tone="slate" />
                            <SummaryCard label="Sedang mengerjakan" value={monitor.in_progress_count} tone="amber" />
                            <SummaryCard label="Sudah selesai" value={monitor.submitted_count} tone="emerald" />
                            <SummaryCard label="Jumlah soal" value={monitor.question_count} tone="indigo" />
                        </section>

                        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <div className="flex flex-col justify-between gap-3 border-b border-slate-200 px-5 py-4 lg:flex-row lg:items-center">
                                <div>
                                    <h2 className="font-bold text-slate-900">
                                        {monitor.assessment.title}
                                    </h2>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Kelas {monitor.assessment.grade_level} · {monitor.assessment.duration_minutes} menit · Nomor mengikuti urutan soal masing-masing siswa
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-3 text-xs font-medium text-slate-600">
                                    <Legend status="correct" />
                                    <Legend status="incorrect" />
                                    <Legend status="unanswered" />
                                </div>
                            </div>

                            {monitor.rows.length === 0 ? (
                                <div className="p-12 text-center text-sm text-slate-500">
                                    Belum ada siswa yang mulai mengerjakan TO ini.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-max border-separate border-spacing-0 text-sm">
                                        <thead>
                                            <tr className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                <th className="sticky left-0 z-20 min-w-64 border-b border-r border-slate-200 bg-slate-50 px-5 py-4 text-left">
                                                    Nama Siswa
                                                </th>
                                                <th className="sticky left-64 z-20 min-w-36 border-b border-r border-slate-200 bg-slate-50 px-4 py-4 text-left">
                                                    Progres
                                                </th>
                                                {Array.from({ length: monitor.question_count }, (_, index) => (
                                                    <th key={index} className="h-14 w-14 border-b border-r border-slate-200 text-center">
                                                        {index + 1}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {monitor.rows.map((row) => (
                                                <tr key={row.attempt_id} className="group hover:bg-slate-50/70">
                                                    <td className="sticky left-0 z-10 border-b border-r border-slate-200 bg-white px-5 py-4 group-hover:bg-slate-50">
                                                        <p className="font-semibold text-slate-900">
                                                            {row.student.name}
                                                        </p>
                                                        <p className="mt-1 font-mono text-xs text-slate-500">
                                                            NISN {row.student.nisn || '-'}
                                                        </p>
                                                    </td>
                                                    <td className="sticky left-64 z-10 border-b border-r border-slate-200 bg-white px-4 py-4 group-hover:bg-slate-50">
                                                        <div className="flex items-center gap-2">
                                                            <span className={`rounded-full px-2 py-1 text-[10px] font-bold uppercase ${row.status === 'submitted' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                                                                {row.status === 'submitted' ? 'Selesai' : 'Mengerjakan'}
                                                            </span>
                                                            <span className="text-xs font-semibold text-slate-700">
                                                                {row.answered_count}/{monitor.question_count}
                                                            </span>
                                                        </div>
                                                        <p className="mt-2 text-[11px] text-slate-500">
                                                            {row.correct_count} benar · {row.incorrect_count} salah
                                                        </p>
                                                    </td>
                                                    {Array.from({ length: monitor.question_count }, (_, index) => {
                                                        const cell = row.cells[index];
                                                        const status = cell?.status || 'unanswered';

                                                        return (
                                                            <td key={index} className="border-b border-r border-slate-200 p-2 text-center">
                                                                <span
                                                                    title={cell ? `Nomor ${index + 1}: ${statusLabels[status]} · ${cell.label}` : 'Tidak ada soal pada posisi ini'}
                                                                    className={`inline-flex h-9 w-9 items-center justify-center rounded-lg border text-base font-bold ${statusStyles[status]}`}
                                                                >
                                                                    {statusIcons[status]}
                                                                </span>
                                                            </td>
                                                        );
                                                    })}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function SummaryCard({ label, value, tone }: { label: string; value: number; tone: 'slate' | 'amber' | 'emerald' | 'indigo' }) {
    const tones = {
        slate: 'bg-slate-100 text-slate-700',
        amber: 'bg-amber-100 text-amber-700',
        emerald: 'bg-emerald-100 text-emerald-700',
        indigo: 'bg-indigo-100 text-indigo-700',
    };

    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p className="text-sm font-medium text-slate-500">{label}</p>
            <span className={`mt-3 inline-flex min-w-14 justify-center rounded-xl px-3 py-2 text-2xl font-bold ${tones[tone]}`}>
                {value}
            </span>
        </div>
    );
}

function Legend({ status }: { status: CellStatus }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span className={`inline-flex h-6 w-6 items-center justify-center rounded-md border font-bold ${statusStyles[status]}`}>
                {statusIcons[status]}
            </span>
            {statusLabels[status]}
        </span>
    );
}
