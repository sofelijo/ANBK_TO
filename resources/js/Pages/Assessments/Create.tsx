import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';

type Question = {
    id: number;
    type: string;
    title?: string;
    prompt: string;
    grade_level: number;
    difficulty: number;
    competency: { id: number; code: string; name: string; subject_id: number | null; parent_id: number | null };
};

type Subject = { id: number; code: string; name: string };
type Competency = {
    id: number;
    parent_id: number | null;
    code: string;
    name: string;
    grade_level: number;
    subject_id: number | null;
};
type CompetencyRow = { competency_id: number; count: number };
type BlueprintRow = { competency_id: number; type: string; difficulty: number; count: number };

type AssessmentForm = {
    subject_id: number | '';
    competency_slots: number[];
    title: string;
    description: string;
    grade_level: number;
    duration_minutes: number;
    assessment_type: string;
    custom_type_name: string;
    selection_mode: 'manual' | 'automatic' | 'competency' | 'blueprint';
    question_count: number;
    question_ids: number[];
    competency_rows: CompetencyRow[];
    blueprint_rows: BlueprintRow[];
    starts_at: string;
    ends_at: string;
    shuffle_questions: boolean;
    shuffle_options: boolean;
    show_navigation: boolean;
    require_all_answers: boolean;
};

type ExistingAssessment = AssessmentForm & {
    id: number;
    competency_coverage?: Record<number, number>;
};

type Props = {
    questions: Question[];
    competencies: Competency[];
    subjects: Subject[];
    assessmentTypes: Record<string, string>;
    questionTypes: Record<string, string>;
    assessment?: ExistingAssessment;
};

export default function Create({ questions, competencies, subjects, assessmentTypes, questionTypes, assessment }: Props) {
    const emptyForm: AssessmentForm = {
        subject_id: '',
        competency_slots: [],
        title: '',
        description: '',
        grade_level: 6,
        duration_minutes: 90,
        assessment_type: 'regular',
        custom_type_name: '',
        selection_mode: 'automatic',
        question_count: 30,
        question_ids: [],
        competency_rows: [],
        blueprint_rows: [],
        starts_at: '',
        ends_at: '',
        shuffle_questions: true,
        shuffle_options: true,
        show_navigation: true,
        require_all_answers: true,
    };

    const { data, setData, post, put, processing, errors, transform } = useForm<AssessmentForm>(
        assessment
            ? {
                  subject_id: assessment.subject_id ?? '',
                  competency_slots: assessment.competency_slots ?? [],
                  title: assessment.title,
                  description: assessment.description,
                  grade_level: assessment.grade_level,
                  duration_minutes: assessment.duration_minutes,
                  assessment_type: assessment.assessment_type,
                  custom_type_name: assessment.custom_type_name,
                  selection_mode: assessment.selection_mode ?? 'automatic',
                  question_count: assessment.question_count,
                  question_ids: assessment.question_ids,
                  competency_rows: assessment.competency_rows,
                  blueprint_rows: assessment.blueprint_rows,
                  starts_at: assessment.starts_at,
                  ends_at: assessment.ends_at,
                  shuffle_questions: assessment.shuffle_questions,
                  shuffle_options: assessment.shuffle_options,
                  show_navigation: assessment.show_navigation,
                  require_all_answers: assessment.require_all_answers,
              }
            : emptyForm,
    );
    const [scheduleEnabled, setScheduleEnabled] = useState(
        !!(assessment?.starts_at || assessment?.ends_at),
    );

    // Sub-competencies are competencies WITH a parent (level 2)
    const subCompetencies = useMemo(
        () => competencies.filter((c) => c.parent_id !== null),
        [competencies],
    );

    // Root competencies (no parent)
    const rootCompetencies = useMemo(
        () => competencies.filter((c) => c.parent_id === null),
        [competencies],
    );

    // Sub-competencies filtered by current grade_level and selected subject
    const availableSubCompetencies = useMemo(
        () =>
            subCompetencies.filter((c) => {
                if (c.grade_level !== data.grade_level) return false;
                if (!data.subject_id) return true;
                return c.subject_id === Number(data.subject_id);
            }),
        [subCompetencies, data.grade_level, data.subject_id],
    );

    const availableQuestions = useMemo(
        () =>
            questions.filter((q) => {
                if (q.grade_level !== data.grade_level) return false;
                if (!data.subject_id) return true;
                return q.competency.subject_id === Number(data.subject_id);
            }),
        [questions, data.grade_level, data.subject_id],
    );

    const availableCompetencies = useMemo(
        () =>
            competencies.filter((c) => {
                if (c.grade_level !== data.grade_level) return false;
                if (!data.subject_id) return true;
                return c.subject_id === Number(data.subject_id);
            }),
        [competencies, data.grade_level, data.subject_id],
    );

    const defaultBlueprintRow = (gradeLevel = data.grade_level): BlueprintRow => ({
        competency_id: competencies.find((c) => c.grade_level === gradeLevel)?.id || 0,
        type: 'single_choice',
        difficulty: 1,
        count: 1,
    });

    const defaultCompetencyRow = (gradeLevel = data.grade_level): CompetencyRow => ({
        competency_id: competencies.find((c) => c.grade_level === gradeLevel)?.id || 0,
        count: 1,
    });

    const updateCompetencyRows = (rows: CompetencyRow[]) => {
        setData((current) => ({
            ...current,
            competency_rows: rows,
            question_count: rows.reduce((total, row) => total + row.count, 0),
            question_ids: [],
        }));
    };

    const updateBlueprintRows = (rows: BlueprintRow[]) => {
        setData((current) => ({
            ...current,
            blueprint_rows: rows,
            question_count: rows.reduce((total, row) => total + row.count, 0),
            question_ids: [],
        }));
    };

    const blueprintAvailability = (row: BlueprintRow) =>
        availableQuestions.filter(
            (q) => q.competency.id === row.competency_id && q.type === row.type && q.difficulty === row.difficulty,
        ).length;

    const competencyAvailability = (row: CompetencyRow) =>
        availableQuestions.filter((q) => q.competency.id === row.competency_id).length;

    const toggleQuestion = (id: number) => {
        if (data.question_ids.includes(id)) {
            setData('question_ids', data.question_ids.filter((qId) => qId !== id));
            return;
        }
        if (data.question_ids.length < data.question_count) {
            setData('question_ids', [...data.question_ids, id]);
        }
    };

    const updateQuestionCount = (count: number) => {
        setData((current) => ({
            ...current,
            question_count: count,
            question_ids: current.question_ids.slice(0, count),
        }));
    };

    const toggleSlot = (id: number) => {
        setData('competency_slots', data.competency_slots.includes(id)
            ? data.competency_slots.filter((s) => s !== id)
            : [...data.competency_slots, id]);
    };

    const selectAllSlots = () => {
        setData('competency_slots', availableSubCompetencies.map((c) => c.id));
    };

    const clearAllSlots = () => {
        setData('competency_slots', []);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        transform((currentData) => ({
            ...currentData,
            subject_id: currentData.subject_id ? Number(currentData.subject_id) : null,
            starts_at: scheduleEnabled && currentData.starts_at ? currentData.starts_at : null,
            ends_at: scheduleEnabled && currentData.ends_at ? currentData.ends_at : null,
            custom_type_name: null,
            description: currentData.description || null,
        }));

        if (assessment) {
            put(route('assessments.update', assessment.id));
        } else {
            post(route('assessments.store'));
        }
    };

    // Group sub-competencies by their root parent for display
    const slotsByRoot = useMemo(() => {
        const grouped: Record<number, { root: Competency; subs: Competency[] }> = {};
        availableSubCompetencies.forEach((sub) => {
            if (!sub.parent_id) return;
            const root = rootCompetencies.find((r) => r.id === sub.parent_id);
            if (!root) return;
            if (!grouped[root.id]) grouped[root.id] = { root, subs: [] };
            grouped[root.id].subs.push(sub);
        });
        return Object.values(grouped);
    }, [availableSubCompetencies, rootCompetencies]);

    const coverageMap: Record<number, number> = assessment?.competency_coverage
        ? Object.fromEntries(Object.entries(assessment.competency_coverage).map(([k, v]) => [Number(k), v]))
        : {};

    const allSelected = availableSubCompetencies.length > 0 &&
        availableSubCompetencies.every((c) => data.competency_slots.includes(c.id));

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-sm font-medium text-emerald-600">Paket Ujian</p>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900">
                        {assessment ? 'Edit Paket Try Out' : 'Buat Jenis Try Out Baru'}
                    </h1>
                </div>
            }
        >
            <Head title={assessment ? 'Edit Paket' : 'Buat Paket'} />
            <form onSubmit={submit} className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
                {Object.keys(errors).length > 0 && (
                    <div className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 font-medium">
                        <p className="font-bold mb-1">Gagal menyimpan paket. Periksa kolom berikut:</p>
                        <ul className="list-disc pl-5 space-y-0.5">
                            {Object.entries(errors).map(([key, msg]) => (
                                <li key={key}>{msg}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* ── Section 1: Identitas paket ── */}
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div>
                        <h2 className="font-semibold text-slate-900">Identitas paket</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Jenis custom memungkinkan sekolah membuat format ujian baru tanpa perubahan aplikasi.
                        </p>
                    </div>
                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        <label className="text-sm font-medium text-slate-700">
                            Judul
                            <input
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                placeholder="Contoh: Simulasi Adaptif Semester Ganjil"
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            />
                            <InputError message={errors.title} />
                        </label>

                        <label className="text-sm font-medium text-slate-700">
                            Mata pelajaran
                            <select
                                value={data.subject_id}
                                onChange={(e) => setData('subject_id', e.target.value === '' ? '' : Number(e.target.value))}
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option value="">— Semua / tidak dispesifikasi —</option>
                                {subjects.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.code} · {s.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.subject_id} />
                        </label>

                        <label className="text-sm font-medium text-slate-700">
                            Jenis try out
                            <select
                                value={data.assessment_type}
                                onChange={(e) => setData('assessment_type', e.target.value)}
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                {Object.entries(assessmentTypes).map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                        </label>

                        {data.assessment_type === 'together' && (
                            <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:col-span-2">
                                Saat ini jam operasional sekolah. Try out hanya dapat dikerjakan jika guru sudah mengambil jadwal untuk NPSN sekolahmu.
                            </div>
                        )}

                        <label className="text-sm font-medium text-slate-700">
                            Jenjang
                            <select
                                value={data.grade_level}
                                onChange={(e) => {
                                    const gradeLevel = Number(e.target.value);
                                    setData((current) => ({
                                        ...current,
                                        grade_level: gradeLevel,
                                        question_ids: [],
                                        competency_slots: [],
                                        competency_rows:
                                            current.selection_mode === 'competency' ? [defaultCompetencyRow(gradeLevel)] : [],
                                        blueprint_rows:
                                            current.selection_mode === 'blueprint' ? [defaultBlueprintRow(gradeLevel)] : [],
                                        question_count: ['competency', 'blueprint'].includes(current.selection_mode)
                                            ? 1
                                            : current.question_count,
                                    }));
                                }}
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option value={6}>Kelas 6</option>
                                <option value={9}>Kelas 9</option>
                                <option value={12}>Kelas 12</option>
                            </select>
                        </label>

                        <label className="text-sm font-medium text-slate-700">
                            Durasi pengerjaan
                            <input
                                type="number"
                                min={5}
                                max={480}
                                value={data.duration_minutes}
                                onChange={(e) => setData('duration_minutes', Number(e.target.value))}
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            />
                            <span className="mt-1 block text-xs text-slate-400">5–480 menit</span>
                            <InputError message={errors.duration_minutes} />
                        </label>

                        <label className="text-sm font-medium text-slate-700">
                            Jumlah soal
                            <input
                                type="number"
                                min={1}
                                max={100}
                                value={data.question_count}
                                onChange={(e) => setData('question_count', Number(e.target.value))}
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            />
                            <span className="mt-1 block text-xs text-slate-400">1–100 soal</span>
                            <InputError message={errors.question_count} />
                        </label>

                        <label className="text-sm font-medium text-slate-700 sm:col-span-2">
                            Deskripsi / petunjuk
                            <textarea
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                rows={3}
                                placeholder="Petunjuk yang akan dibaca peserta sebelum mengerjakan."
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            />
                        </label>
                    </div>
                </section>

                {/* ── Section 2: Komposisi sub-kompetensi ── */}
                {availableSubCompetencies.length > 0 && (
                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h2 className="font-semibold text-slate-900">Komposisi sub-kompetensi</h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    Centang sub-kompetensi yang akan diujikan dalam paket ini. Ini membantu melacak cakupan materi.
                                </p>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                <button
                                    type="button"
                                    onClick={allSelected ? clearAllSlots : selectAllSlots}
                                    className="rounded-lg border border-emerald-300 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50"
                                >
                                    {allSelected ? 'Hapus semua' : 'Pilih semua'}
                                </button>
                            </div>
                        </div>

                        <div className="mt-5 space-y-4">
                            {slotsByRoot.map(({ root, subs }) => (
                                <div key={root.id} className="rounded-xl border border-slate-100 bg-slate-50 p-4">
                                    <p className="mb-3 text-xs font-bold uppercase tracking-wide text-slate-500">
                                        {root.name}
                                    </p>
                                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        {subs.map((sub) => {
                                            const checked = data.competency_slots.includes(sub.id);
                                            const coverageCount = coverageMap[sub.id] ?? 0;
                                            const hasCoverage = coverageCount > 0;
                                            return (
                                                <label
                                                    key={sub.id}
                                                    className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors ${
                                                        checked
                                                            ? 'border-emerald-300 bg-emerald-50'
                                                            : 'border-slate-200 bg-white hover:border-slate-300'
                                                    }`}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => toggleSlot(sub.id)}
                                                        className="mt-0.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                                    />
                                                    <span className="flex-1 min-w-0">
                                                        <span className="block text-xs font-semibold text-slate-800 line-clamp-2">
                                                            {sub.name}
                                                        </span>
                                                        {assessment && (
                                                            <span
                                                                className={`mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                                                                    hasCoverage
                                                                        ? 'bg-emerald-100 text-emerald-700'
                                                                        : 'bg-amber-100 text-amber-700'
                                                                }`}
                                                            >
                                                                {hasCoverage ? `✓ ${coverageCount} soal` : '✗ belum ada soal'}
                                                            </span>
                                                        )}
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="mt-4 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                            <span className="font-semibold text-slate-800">{data.competency_slots.length}</span>
                            dari {availableSubCompetencies.length} sub-kompetensi dipilih
                            {assessment && (
                                <span className="ml-auto text-xs text-slate-400">
                                    {Object.keys(coverageMap).length} sudah ada soalnya
                                </span>
                            )}
                        </div>
                    </section>
                )}


                {/* ── Section 4: Jadwal akses ── */}
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex items-center justify-between">
                        <div>
                            <h2 className="font-semibold text-slate-900">Jadwal akses</h2>
                            <p className="mt-1 text-sm text-slate-500">
                                {scheduleEnabled
                                    ? 'Paket hanya dapat dikerjakan dalam rentang waktu yang ditentukan.'
                                    : 'Paket selalu aktif setelah diterbitkan, tanpa batasan waktu.'}
                            </p>
                        </div>
                        <label className="flex cursor-pointer items-center gap-2">
                            <span className="text-sm font-medium text-slate-700">Batasi waktu</span>
                            <button
                                type="button"
                                role="switch"
                                aria-checked={scheduleEnabled}
                                onClick={() => {
                                    const next = !scheduleEnabled;
                                    setScheduleEnabled(next);
                                    if (!next) setData((current) => ({ ...current, starts_at: '', ends_at: '' }));
                                }}
                                className={`relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 ${scheduleEnabled ? 'bg-emerald-600' : 'bg-slate-200'}`}
                            >
                                <span
                                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition-transform ${scheduleEnabled ? 'translate-x-5' : 'translate-x-0'}`}
                                />
                            </button>
                        </label>
                    </div>

                    {scheduleEnabled && (
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <label className="text-sm font-medium text-slate-700">
                                Mulai tersedia
                                <input
                                    type="datetime-local"
                                    value={data.starts_at}
                                    onChange={(e) => setData('starts_at', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                                />
                                <InputError message={errors.starts_at} />
                            </label>
                            <label className="text-sm font-medium text-slate-700">
                                Ditutup pada
                                <input
                                    type="datetime-local"
                                    value={data.ends_at}
                                    onChange={(e) => setData('ends_at', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                                />
                                <InputError message={errors.ends_at} />
                            </label>
                        </div>
                    )}
                </section>

                {/* ── Section 5: Perilaku saat ujian ── */}
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Perilaku saat ujian</h2>
                    <div className="mt-5 grid gap-3 sm:grid-cols-2">
                        {(
                            [
                                ['shuffle_questions', 'Acak urutan soal', 'Urutan stabil untuk setiap peserta tetapi berbeda antar peserta.'],
                                ['shuffle_options', 'Acak pilihan jawaban', 'Label A–D disusun ulang tanpa mengubah kunci jawaban.'],
                                ['show_navigation', 'Tampilkan navigasi nomor', 'Peserta dapat berpindah langsung ke nomor soal tertentu.'],
                                ['require_all_answers', 'Wajib jawab semua soal', 'Pengiriman ditolak sampai semua soal terisi atau waktu habis.'],
                            ] as const
                        ).map(([key, title, description]) => (
                            <label key={key} className="flex cursor-pointer gap-3 rounded-xl border border-slate-200 p-4">
                                <input
                                    type="checkbox"
                                    checked={data[key] as boolean}
                                    onChange={(e) => setData(key, e.target.checked as never)}
                                    className="mt-1 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                />
                                <span>
                                    <span className="block text-sm font-semibold text-slate-900">{title}</span>
                                    <span className="mt-1 block text-xs leading-5 text-slate-500">{description}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                </section>

                <div className="space-y-2 text-right">
                    {(errors.title || errors.question_ids) && (
                        <p className="text-xs font-semibold text-rose-600">
                            {errors.title || errors.question_ids}
                        </p>
                    )}
                    <div className="flex justify-end gap-3">
                        <Link
                            href={route('assessments.index')}
                            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            Batal
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
                        >
                            {processing
                                ? 'Menyimpan…'
                                : assessment
                                  ? 'Simpan perubahan'
                                  : 'Simpan sebagai draft'}
                        </button>
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
