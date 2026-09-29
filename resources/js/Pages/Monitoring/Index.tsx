import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Combobox, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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

type SchoolOption = {
    id: number;
    name: string;
    npsn: string;
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

type WeakCompetency = {
    code: string;
    name: string;
    domain: string;
    question_count: number;
    response_count: number;
    incorrect_count: number;
    incorrect_percentage: number;
};

type DifficultQuestion = {
    question_id: number;
    title: string;
    competency_code: string;
    competency_name: string;
    response_count: number;
    incorrect_count: number;
    incorrect_percentage: number;
};

type AiAnalysisResult = {
    summary: string;
    teacher_recommendations: {
        competency_code: string;
        finding: string;
        action: string;
        suggested_activity: string;
    }[];
    practice_questions: {
        source_question_id: number;
        competency_code: string;
        prompt: string;
        difficulty: number;
        options: {
            content: string;
            is_correct: boolean;
        }[];
        explanation: string;
    }[];
};

type AiAnalysis = {
    sample_size: number;
    weak_competencies: WeakCompetency[];
    difficult_questions: DifficultQuestion[];
    generation?: {
        id: number;
        status: 'pending' | 'processing' | 'completed' | 'failed';
        provider: string;
        model: string;
        is_stale: boolean;
        result?: AiAnalysisResult | null;
        error?: string | null;
        created_at: string;
        completed_at?: string | null;
    } | null;
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
    canChooseSchool,
    schools,
    selectedSchoolId,
    selectedSchool,
    assessments,
    selectedAssessmentId,
    monitor,
    aiAnalysis,
}: {
    canChooseSchool: boolean;
    schools: SchoolOption[];
    selectedSchoolId?: number;
    selectedSchool?: SchoolOption | null;
    assessments: AssessmentOption[];
    selectedAssessmentId?: number;
    monitor?: Monitor | null;
    aiAnalysis?: AiAnalysis | null;
}) {
    const [requestingAnalysis, setRequestingAnalysis] = useState(false);
    const [analysisRequestError, setAnalysisRequestError] = useState<string | null>(null);
    const [schoolQuery, setSchoolQuery] = useState('');
    const filteredSchools = schoolQuery.trim() === ''
        ? schools
        : schools.filter((school) => `${school.name} ${school.npsn}`.toLocaleLowerCase('id-ID').includes(schoolQuery.trim().toLocaleLowerCase('id-ID')));

    useEffect(() => {
        if (!selectedAssessmentId) return;

        const refreshTimer = window.setInterval(() => {
            router.reload({
                only: ['monitor', 'aiAnalysis'],
            });
        }, 5000);

        return () => window.clearInterval(refreshTimer);
    }, [selectedAssessmentId, selectedSchoolId]);

    const selectSchool = (schoolId: string) => {
        setSchoolQuery('');
        router.get(
            route('monitoring.index'),
            schoolId ? { school_id: schoolId } : {},
            { preserveState: true, replace: true },
        );
    };

    const selectAssessment = (assessmentId: string) => {
        router.get(
            route('monitoring.index'),
            {
                ...(canChooseSchool && selectedSchoolId ? { school_id: selectedSchoolId } : {}),
                ...(assessmentId ? { assessment_id: assessmentId } : {}),
            },
            { preserveState: true, replace: true },
        );
    };

    const generateAnalysis = () => {
        if (!monitor) return;

        router.post(
            route('monitoring.ai-analysis.store', monitor.assessment.id),
            canChooseSchool && selectedSchoolId ? { school_id: selectedSchoolId } : {},
            {
                preserveScroll: true,
                onStart: () => {
                    setRequestingAnalysis(true);
                    setAnalysisRequestError(null);
                },
                onError: (errors) => {
                    setAnalysisRequestError(
                        String(errors.analysis || 'Permintaan analisis AI belum dapat diproses.'),
                    );
                },
                onFinish: () => setRequestingAnalysis(false),
            },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <p className="text-sm font-medium text-emerald-600">
                            {canChooseSchool ? 'Pemantauan Lintas Sekolah' : 'Pelaksanaan Sekolah'}
                        </p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">
                            {canChooseSchool ? 'Monitoring TO Lintas Sekolah' : 'Monitoring TO Serentak'}
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
                <section className={`grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm ${canChooseSchool ? 'md:grid-cols-2' : ''}`}>
                    {canChooseSchool && <div className="block text-sm font-semibold text-slate-700">
                        <span>Pilih Sekolah</span>
                        <Combobox value={selectedSchool || null} onChange={(school: SchoolOption | null) => school && selectSchool(String(school.id))} by="id">
                            <div className="relative mt-2">
                                <ComboboxInput displayValue={(school: SchoolOption | null) => school ? `${school.name} · NPSN ${school.npsn}` : ''} onChange={(event) => setSchoolQuery(event.target.value)} onFocus={(event) => event.currentTarget.select()} placeholder="Cari nama sekolah atau NPSN..." className="block w-full rounded-xl border-slate-300 pe-10 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                                <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-slate-400">⌄</span>
                                <ComboboxOptions className="absolute z-30 mt-2 max-h-72 w-full overflow-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl empty:hidden">
                                    {filteredSchools.length === 0 ? <div className="px-3 py-4 text-center text-sm font-normal text-slate-500">Sekolah atau NPSN tidak ditemukan.</div> : filteredSchools.map((school) => <ComboboxOption key={school.id} value={school} className="group cursor-pointer rounded-lg px-3 py-2.5 text-sm font-normal text-slate-700 data-[focus]:bg-emerald-50 data-[focus]:text-emerald-800 data-[selected]:font-semibold">
                                        <span className="block truncate">{school.name}</span>
                                        <span className="mt-0.5 block font-mono text-xs text-slate-500 group-data-[focus]:text-emerald-700">NPSN {school.npsn}</span>
                                    </ComboboxOption>)}
                                </ComboboxOptions>
                            </div>
                        </Combobox>
                    </div>}
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
                            Belum ada aktivitas Try Out{selectedSchool ? ` di ${selectedSchool.name}` : ''}
                        </p>
                        <p className="mt-2 text-sm text-slate-500">
                            {canChooseSchool ? 'Pilih sekolah lain atau tunggu sampai siswa sekolah ini mulai mengerjakan.' : 'Ambil jadwal sekolah terlebih dahulu. Peserta akan muncul setelah mulai mengerjakan.'}
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

                        {aiAnalysis && (
                            <AiAnalysisPanel
                                analysis={aiAnalysis}
                                requesting={requestingAnalysis}
                                requestError={analysisRequestError}
                                onGenerate={generateAnalysis}
                            />
                        )}

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
                                                <th className="md:sticky md:left-0 z-20 min-w-48 md:min-w-64 border-b border-r border-slate-200 bg-slate-50 px-5 py-4 text-left">
                                                    Nama Siswa
                                                </th>
                                                <th className="md:sticky md:left-64 z-20 min-w-36 border-b border-r border-slate-200 bg-slate-50 px-4 py-4 text-left">
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
                                                    <td className="md:sticky md:left-0 z-10 border-b border-r border-slate-200 bg-white px-5 py-4 group-hover:bg-slate-50">
                                                        <p className="font-semibold text-slate-900">
                                                            {row.student.name}
                                                        </p>
                                                        <p className="mt-1 font-mono text-xs text-slate-500">
                                                            NISN {row.student.nisn || '-'}
                                                        </p>
                                                    </td>
                                                    <td className="md:sticky md:left-64 z-10 border-b border-r border-slate-200 bg-white px-4 py-4 group-hover:bg-slate-50">
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

function AiAnalysisPanel({
    analysis,
    requesting,
    requestError,
    onGenerate,
}: {
    analysis: AiAnalysis;
    requesting: boolean;
    requestError: string | null;
    onGenerate: () => void;
}) {
    const generation = analysis.generation;
    const waiting = generation?.status === 'pending' || generation?.status === 'processing';
    const result = generation?.status === 'completed' ? generation.result : null;
    const buttonLabel = waiting
        ? 'AI sedang menganalisis...'
        : requesting
          ? 'Mengirim permintaan...'
          : generation?.status === 'failed'
            ? 'Coba analisis lagi'
            : generation?.is_stale
              ? 'Perbarui analisis AI'
              : result
                ? 'Analisis ulang AI'
                : 'Buat analisis & latihan AI';

    return (
        <section className="overflow-hidden rounded-2xl border border-indigo-200 bg-white shadow-sm">
            <div className="bg-gradient-to-r from-indigo-700 via-indigo-600 to-violet-600 px-5 py-5 text-white sm:px-6">
                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div className="flex items-start gap-3">
                        <span className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/15 text-xl font-bold ring-1 ring-white/25">
                            AI
                        </span>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-100">
                                Analisis pembelajaran sekolah
                            </p>
                            <h2 className="mt-1 text-xl font-bold">
                                Rekomendasi guru & latihan penguatan
                            </h2>
                            <p className="mt-1 max-w-3xl text-sm leading-6 text-indigo-100">
                                AI membaca statistik agregat soal yang paling banyak salah. Nama, NISN, dan jawaban per siswa tidak dikirim ke provider AI.
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onGenerate}
                        disabled={requesting || waiting || analysis.sample_size === 0}
                        className="inline-flex min-w-52 items-center justify-center rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-indigo-700 shadow-sm transition hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {buttonLabel}
                    </button>
                </div>
            </div>

            <div className="space-y-6 p-5 sm:p-6">
                {requestError && (
                    <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                        {requestError}
                    </div>
                )}

                {analysis.sample_size === 0 ? (
                    <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-center">
                        <p className="font-semibold text-slate-800">Belum ada hasil yang dapat dianalisis</p>
                        <p className="mt-1 text-sm text-slate-500">
                            Tombol AI aktif setelah minimal satu siswa menyelesaikan paket.
                        </p>
                    </div>
                ) : (
                    <>
                        <div>
                            <div className="flex flex-wrap items-end justify-between gap-2">
                                <div>
                                    <h3 className="font-bold text-slate-900">Indikasi kompetensi terlemah</h3>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Berdasarkan {analysis.sample_size} pengerjaan selesai · semakin tinggi persentase, semakin perlu diprioritaskan
                                    </p>
                                </div>
                                {generation?.is_stale && (
                                    <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-700">
                                        Ada hasil siswa yang lebih baru
                                    </span>
                                )}
                            </div>
                            <div className="mt-4 grid gap-3 lg:grid-cols-3">
                                {analysis.weak_competencies.map((competency, index) => (
                                    <div key={competency.code} className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="text-[11px] font-bold uppercase tracking-wide text-slate-400">
                                                    Prioritas {index + 1} · {competency.domain}
                                                </p>
                                                <p className="mt-1 font-semibold text-slate-900">{competency.name}</p>
                                            </div>
                                            <span className="rounded-lg bg-rose-100 px-2.5 py-1 text-sm font-bold text-rose-700">
                                                {competency.incorrect_percentage}% salah
                                            </span>
                                        </div>
                                        <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                            <div
                                                className="h-full rounded-full bg-rose-500"
                                                style={{ width: `${Math.min(100, competency.incorrect_percentage)}%` }}
                                            />
                                        </div>
                                        <p className="mt-2 text-xs text-slate-500">
                                            {competency.incorrect_count} dari {competency.response_count} respons · {competency.question_count} soal
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div>
                            <h3 className="font-bold text-slate-900">Soal yang paling banyak salah</h3>
                            <div className="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-5">
                                {analysis.difficult_questions.map((question, index) => (
                                    <div key={question.question_id} className="rounded-xl border border-slate-200 p-3">
                                        <p className="text-[11px] font-bold uppercase tracking-wide text-indigo-600">
                                            Peringkat {index + 1}
                                        </p>
                                        <p className="mt-1 line-clamp-2 text-sm font-semibold text-slate-800" title={question.title}>
                                            {question.title}
                                        </p>
                                        <p className="mt-2 text-xs text-slate-500">{question.competency_name}</p>
                                        <p className="mt-2 font-bold text-rose-700">
                                            {question.incorrect_count}/{question.response_count} salah
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </>
                )}

                {waiting && (
                    <div className="flex items-center gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-4 text-sm text-indigo-800">
                        <span className="h-5 w-5 animate-spin rounded-full border-2 border-indigo-200 border-t-indigo-600" />
                        <div>
                            <p className="font-semibold">AI sedang menyusun rekomendasi dan soal latihan.</p>
                            <p className="mt-0.5 text-xs text-indigo-600">Halaman diperbarui otomatis setiap 5 detik.</p>
                        </div>
                    </div>
                )}

                {generation?.status === 'failed' && (
                    <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        <p className="font-semibold">{generation.error}</p>
                        <p className="mt-1 text-xs">Periksa konfigurasi provider atau queue, kemudian jalankan analisis kembali.</p>
                    </div>
                )}

                {result && (
                    <>
                        <div className="rounded-2xl border border-indigo-100 bg-indigo-50/70 p-5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="font-bold text-indigo-950">Ringkasan AI</h3>
                                <span className="text-[11px] font-medium text-indigo-500">
                                    {generation?.provider} · {generation?.model}
                                </span>
                            </div>
                            <p className="mt-3 whitespace-pre-line text-sm leading-7 text-indigo-950">
                                {result.summary}
                            </p>
                        </div>

                        <div>
                            <h3 className="font-bold text-slate-900">Tindak lanjut untuk Bapak/Ibu Guru</h3>
                            <div className="mt-3 grid gap-4 lg:grid-cols-3">
                                {result.teacher_recommendations.map((recommendation, index) => {
                                    const competency = analysis.weak_competencies.find(
                                        (item) => item.code === recommendation.competency_code,
                                    );

                                    return (
                                        <article key={`${recommendation.competency_code}-${index}`} className="rounded-xl border border-slate-200 p-4">
                                            <p className="text-xs font-bold uppercase tracking-wide text-indigo-600">
                                                {competency?.name || recommendation.competency_code}
                                            </p>
                                            <div className="mt-3 space-y-3 text-sm leading-6 text-slate-700">
                                                <div>
                                                    <p className="text-xs font-bold text-slate-900">Temuan</p>
                                                    <p>{recommendation.finding}</p>
                                                </div>
                                                <div>
                                                    <p className="text-xs font-bold text-slate-900">Yang perlu ditingkatkan</p>
                                                    <p>{recommendation.action}</p>
                                                </div>
                                                <div className="rounded-lg bg-emerald-50 p-3 text-emerald-900">
                                                    <p className="text-xs font-bold">Aktivitas yang disarankan</p>
                                                    <p>{recommendation.suggested_activity}</p>
                                                </div>
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                        </div>

                        <div>
                            <div>
                                <h3 className="font-bold text-slate-900">Contoh latihan penguatan dari AI</h3>
                                <p className="mt-1 text-xs text-slate-500">
                                    Soal baru dibuat dari konsep yang paling banyak salah. Guru tetap perlu meninjau kunci dan redaksi sebelum diberikan kepada siswa.
                                </p>
                            </div>
                            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                                {result.practice_questions.map((question, questionIndex) => {
                                    const competency = analysis.weak_competencies.find(
                                        (item) => item.code === question.competency_code,
                                    );

                                    return (
                                        <article key={`${question.source_question_id}-${questionIndex}`} className="rounded-2xl border border-slate-200 p-5">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="rounded-full bg-indigo-100 px-2.5 py-1 text-[11px] font-bold text-indigo-700">
                                                    Latihan {questionIndex + 1} · Kesulitan {question.difficulty}
                                                </span>
                                                <span className="text-[11px] font-medium text-slate-400">
                                                    {competency?.name || question.competency_code}
                                                </span>
                                            </div>
                                            <p className="mt-4 text-sm font-semibold leading-6 text-slate-900">
                                                {question.prompt}
                                            </p>
                                            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                                {question.options.map((option, optionIndex) => (
                                                    <div
                                                        key={optionIndex}
                                                        className={`flex items-start gap-2 rounded-lg border px-3 py-2 text-sm ${
                                                            option.is_correct
                                                                ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
                                                                : 'border-slate-200 bg-slate-50 text-slate-700'
                                                        }`}
                                                    >
                                                        <span className="font-bold">{String.fromCharCode(65 + optionIndex)}.</span>
                                                        <span>{option.content}</span>
                                                        {option.is_correct && (
                                                            <span className="ms-auto text-[10px] font-bold uppercase text-emerald-700">Kunci</span>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                            <div className="mt-3 rounded-lg bg-slate-100 px-3 py-2 text-xs leading-5 text-slate-600">
                                                <span className="font-bold text-slate-800">Pembahasan: </span>
                                                {question.explanation}
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                        </div>
                    </>
                )}
            </div>
        </section>
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
