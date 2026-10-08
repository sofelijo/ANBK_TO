import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import FormattedText from '@/Components/FormattedText';
import StimulusText from '@/Components/StimulusText';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type OptionItem = {
    id: number;
    label?: string;
    content?: string;
    option_text?: string;
    is_correct: boolean;
};

type QuestionItem = {
    id: number;
    title?: string;
    prompt: string;
    stimulus?: string;
    explanation?: string;
    type: string;
    difficulty: number;
    competency?: {
        id: number;
        name: string;
        code: string;
        parent_name: string;
    };
    options?: OptionItem[];
};

type BankQuestionItem = {
    id: number;
    prompt: string;
    stimulus?: string;
    explanation?: string;
    type: string;
    difficulty: number;
    competency_id: number | null;
    competency_name: string;
    competency_code: string;
    parent_competency_name: string;
    is_bundle: boolean;
    story_generation_id: number | null;
    bundle_title?: string | null;
    bundle_question_count: number;
    bundle_questions?: Array<{
        id: number;
        prompt: string;
        explanation?: string;
        type: string;
        difficulty: number;
        competency_name: string;
        options?: OptionItem[];
    }>;
    options?: OptionItem[];
};

type SubCompetencyDetail = {
    id: number;
    name: string;
    code: string;
    parent_name: string;
    is_selected_slot: boolean;
    question_count: number;
};

type AssessmentDetail = {
    id: number;
    title: string;
    description?: string;
    grade_level: number;
    duration_minutes: number;
    status: string;
    starts_at?: string;
    ends_at?: string;
    subject?: { id: number; name: string; code: string } | null;
    questions_count: number;
    attempts_count: number;
    competency_slots: number[];
};

const typeLabels: Record<string, string> = {
    single_choice: 'Pilihan Tunggal',
    multiple_choice: 'Pilihan Kompleks',
    short_answer: 'Isian Singkat',
    matching: 'Menjodohkan',
    category_matrix: 'Pilihan Kategori',
};

export default function Show({
    assessment,
    questions,
    subCompetencies,
    availableBankQuestions = [],
    canManage,
    canPreview,
}: {
    assessment: AssessmentDetail;
    questions: QuestionItem[];
    subCompetencies: SubCompetencyDetail[];
    availableBankQuestions?: BankQuestionItem[];
    canManage: boolean;
    canPreview: boolean;
}) {
    const selectedSlots = subCompetencies.filter((s) => s.is_selected_slot);
    const fulfilledSlots = selectedSlots.filter((s) => s.question_count > 0);
    const missingSlots = selectedSlots.filter((s) => s.question_count === 0);

    // Modal states for Swapping & Adding questions
    const [swapModalOpen, setSwapModalOpen] = useState(false);
    const [targetQuestionToSwap, setTargetQuestionToSwap] = useState<QuestionItem | null>(null);
    const [selectedReplacementId, setSelectedReplacementId] = useState<number | null>(null);
    const [swapSubCompetencyId, setSwapSubCompetencyId] = useState<number | 'all'>('all');
    const [swapSearch, setSwapSearch] = useState('');

    const [addFromBankModalOpen, setAddFromBankModalOpen] = useState(false);
    const [bankSearch, setBankSearch] = useState('');
    const [previewBundle, setPreviewBundle] = useState<BankQuestionItem | null>(null);
    const standaloneBankQuestions = availableBankQuestions.filter((question) => !question.is_bundle);
    const addableBankItems = availableBankQuestions.filter((question, index, allQuestions) => (
        !question.is_bundle
        || allQuestions.findIndex((candidate) => candidate.story_generation_id === question.story_generation_id) === index
    ));

    // Preview Question Modal state
    const [previewQuestion, setPreviewQuestion] = useState<{
        id?: number;
        prompt: string;
        stimulus?: string;
        explanation?: string;
        type: string;
        difficulty: number;
        competency_name?: string;
        options?: OptionItem[];
    } | null>(null);

    const openSwapModal = (question: QuestionItem) => {
        const targetSubCompetencyId = question.competency?.id ?? 'all';
        const initialCandidates = standaloneBankQuestions.filter(
            (candidate) => targetSubCompetencyId === 'all' || candidate.competency_id === targetSubCompetencyId,
        );

        setTargetQuestionToSwap(question);
        setSwapSubCompetencyId(targetSubCompetencyId);
        setSwapSearch('');
        setSelectedReplacementId(initialCandidates[0]?.id ?? null);
        setSwapModalOpen(true);
    };

    const replacementQuestions = standaloneBankQuestions.filter((question) => {
        const matchesSubCompetency =
            swapSubCompetencyId === 'all' || question.competency_id === swapSubCompetencyId;
        const keyword = swapSearch.trim().toLowerCase();
        const matchesSearch =
            keyword === '' ||
            question.prompt.toLowerCase().includes(keyword) ||
            question.competency_name.toLowerCase().includes(keyword) ||
            question.competency_code.toLowerCase().includes(keyword);

        return matchesSubCompetency && matchesSearch;
    });

    const replacementCountBySubCompetency = standaloneBankQuestions.reduce<Record<number, number>>(
        (counts, question) => {
            if (question.competency_id !== null) {
                counts[question.competency_id] = (counts[question.competency_id] ?? 0) + 1;
            }

            return counts;
        },
        {},
    );

    const changeSwapSubCompetency = (value: string) => {
        const subCompetencyId = value === 'all' ? 'all' : Number(value);
        const candidates = standaloneBankQuestions.filter(
            (question) => subCompetencyId === 'all' || question.competency_id === subCompetencyId,
        );

        setSwapSubCompetencyId(subCompetencyId);
        setSwapSearch('');
        setSelectedReplacementId(candidates[0]?.id ?? null);
    };

    const changeSwapSearch = (value: string) => {
        const keyword = value.trim().toLowerCase();
        const candidates = standaloneBankQuestions.filter((question) => {
            const matchesSubCompetency =
                swapSubCompetencyId === 'all' || question.competency_id === swapSubCompetencyId;
            const matchesSearch =
                keyword === '' ||
                question.prompt.toLowerCase().includes(keyword) ||
                question.competency_name.toLowerCase().includes(keyword) ||
                question.competency_code.toLowerCase().includes(keyword);

            return matchesSubCompetency && matchesSearch;
        });

        setSwapSearch(value);
        if (!candidates.some((question) => question.id === selectedReplacementId)) {
            setSelectedReplacementId(candidates[0]?.id ?? null);
        }
    };

    const handleSwapSubmit = () => {
        if (!targetQuestionToSwap || !selectedReplacementId) return;
        router.post(
            route('assessments.questions.swap', assessment.id),
            {
                old_question_id: targetQuestionToSwap.id,
                new_question_id: selectedReplacementId,
            },
            {
                onSuccess: () => {
                    setSwapModalOpen(false);
                    setTargetQuestionToSwap(null);
                },
            },
        );
    };

    const handleRemoveQuestion = (question: QuestionItem) => {
        if (
            confirm(
                `Hapus soal "${question.prompt.substring(0, 40)}..." dari paket ini?\n\n(Soal tetap ada di bank soal, hanya dilepas dari paket ujian ini).`,
            )
        ) {
            router.delete(route('assessments.questions.remove', [assessment.id, question.id]));
        }
    };

    const handleAttachBankQuestion = (question: BankQuestionItem) => {
        router.post(
            route('assessments.questions.attach', assessment.id),
            question.is_bundle
                ? { story_generation_id: question.story_generation_id }
                : { question_id: question.id },
            {
                onSuccess: () => {
                    setAddFromBankModalOpen(false);
                },
            },
        );
    };

    const filteredBankQuestions = addableBankItems.filter((question) => {
        const keyword = bankSearch.trim().toLowerCase();

        return keyword === ''
            || question.prompt.toLowerCase().includes(keyword)
            || (question.stimulus ?? '').toLowerCase().includes(keyword)
            || (question.bundle_title ?? '').toLowerCase().includes(keyword)
            || question.competency_name.toLowerCase().includes(keyword);
    });

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <Link
                            href={route('assessments.index')}
                            className="text-xs font-semibold text-emerald-600 hover:underline"
                        >
                            ← Kembali ke Paket Ujian
                        </Link>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">{assessment.title}</h1>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canPreview ? (
                            <a
                                href={route('assessments.preview', assessment.id)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="rounded-xl border border-indigo-300 bg-white px-4 py-2.5 text-sm font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50"
                            >
                                Preview
                            </a>
                        ) : (
                            <button
                                type="button"
                                disabled
                                title="Preview belum tersedia karena paket belum memiliki soal."
                                className="cursor-not-allowed rounded-xl border border-slate-200 bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-400"
                            >
                                Preview
                            </button>
                        )}
                        {canManage && <Link
                            href={route('assessments.edit', assessment.id)}
                            className="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            Edit Komposisi
                        </Link>}
                    </div>
                </div>
            }
        >
            <Head title={`Detail Paket - ${assessment.title}`} />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
                {/* ── Metadata Header Card ── */}
                <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <span className="text-xs font-medium text-slate-400 uppercase tracking-wide">Mata Pelajaran</span>
                            <p className="mt-1 font-semibold text-slate-900">
                                {assessment.subject ? assessment.subject.name : 'Semua Mapel'}
                            </p>
                        </div>
                        <div>
                            <span className="text-xs font-medium text-slate-400 uppercase tracking-wide">Jenjang & Durasi</span>
                            <p className="mt-1 font-semibold text-slate-900">
                                Kelas {assessment.grade_level} · {assessment.duration_minutes} Menit
                            </p>
                        </div>
                        <div>
                            <span className="text-xs font-medium text-slate-400 uppercase tracking-wide">Status Paket</span>
                            <p className="mt-1">
                                <span
                                    className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-bold ${
                                        assessment.status === 'published'
                                            ? 'bg-emerald-100 text-emerald-800'
                                            : 'bg-amber-100 text-amber-800'
                                    }`}
                                >
                                    {assessment.status}
                                </span>
                            </p>
                        </div>
                        <div>
                            <span className="text-xs font-medium text-slate-400 uppercase tracking-wide">Total Soal & Peserta</span>
                            <p className="mt-1 font-semibold text-slate-900">
                                {assessment.questions_count} Soal · {assessment.attempts_count} Percobaan Ujian
                            </p>
                        </div>
                    </div>

                    {assessment.description && (
                        <p className="mt-4 border-t border-slate-100 pt-4 text-sm text-slate-600">
                            {assessment.description}
                        </p>
                    )}
                </div>

                {/* ── Ringkasan Cakupan Sub-Kompetensi ── */}
                <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-6">
                    <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
                        <div>
                            <h2 className="text-lg font-bold text-slate-900">Komposisi & Cakupan Sub-Kompetensi</h2>
                            <p className="text-xs text-slate-500">
                                Melacak kecukupan soal per sub-kompetensi yang ditargetkan dalam paket ini.
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 border border-emerald-200">
                                ✓ {fulfilledSlots.length} Terpenuhi
                            </span>
                            {missingSlots.length > 0 && (
                                <span className="rounded-full bg-rose-50 px-3 py-1 text-xs font-bold text-rose-700 border border-rose-200">
                                    ✗ {missingSlots.length} Belum Ada Soal
                                </span>
                            )}
                        </div>
                    </div>

                    {/* Missing Sub-competencies Section */}
                    {missingSlots.length > 0 && (
                        <div>
                            <h3 className="text-xs font-bold uppercase tracking-wider text-rose-700 mb-3">
                                Sub-kompetensi yang BELUM Memiliki Soal ({missingSlots.length})
                            </h3>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {missingSlots.map((sub) => (
                                    <div
                                        key={sub.id}
                                        className="flex flex-col justify-between rounded-xl border border-rose-200 bg-rose-50/50 p-4"
                                    >
                                        <div>
                                            <span className="text-[11px] font-semibold text-rose-600 block">
                                                {sub.parent_name}
                                            </span>
                                            <p className="mt-0.5 text-sm font-bold text-slate-900">
                                                {sub.name}
                                            </p>
                                        </div>
                                        <div className="mt-3 flex items-center justify-between pt-2 border-t border-rose-100">
                                            <span className="text-xs font-semibold text-rose-700">0 Soal</span>
                                            {canManage && <Link
                                                href={route('questions.create', { subject_id: assessment.subject?.id })}
                                                className="text-xs font-bold text-indigo-700 hover:underline"
                                            >
                                                + Buat Soal
                                            </Link>}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Fulfilled Sub-competencies Section */}
                    {fulfilledSlots.length > 0 && (
                        <div>
                            <h3 className="text-xs font-bold uppercase tracking-wider text-emerald-700 mb-3">
                                Sub-kompetensi yang SUDAH Memiliki Soal ({fulfilledSlots.length})
                            </h3>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {fulfilledSlots.map((sub) => (
                                    <div
                                        key={sub.id}
                                        className="flex flex-col justify-between rounded-xl border border-emerald-200 bg-emerald-50/40 p-4"
                                    >
                                        <div>
                                            <span className="text-[11px] font-semibold text-emerald-600 block">
                                                {sub.parent_name}
                                            </span>
                                            <p className="mt-0.5 text-sm font-bold text-slate-900">
                                                {sub.name}
                                            </p>
                                        </div>
                                        <div className="mt-3 flex items-center justify-between pt-2 border-t border-emerald-100">
                                            <span className="text-xs font-bold text-emerald-700">
                                                ✓ {sub.question_count} Soal Masuk
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* ── Daftar Soal dalam Paket ── */}
                <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
                    <div className="flex items-center justify-between border-b border-slate-100 pb-4">
                        <div>
                            <h2 className="text-lg font-bold text-slate-900">
                                Daftar Soal dalam Paket Ini ({questions.length})
                            </h2>
                            <p className="text-xs text-slate-500">
                                Anda dapat melihat, mengganti, atau menghapus soal dari paket ini.
                            </p>
                        </div>
                        {canManage && <div className="flex gap-2">
                            <button
                                onClick={() => setAddFromBankModalOpen(true)}
                                className="rounded-xl bg-indigo-50 border border-indigo-200 px-3.5 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-100"
                            >
                                + Pilih dari Bank Soal
                            </button>
                            <Link
                                href={route('questions.create', { subject_id: assessment.subject?.id })}
                                className="rounded-xl bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                            >
                                + Buat Soal Baru
                            </Link>
                        </div>}
                    </div>

                    {questions.length === 0 ? (
                        <div className="p-8 text-center text-sm text-slate-500 border border-dashed border-slate-200 rounded-xl">
                            {canManage ? 'Belum ada soal yang dimasukkan ke paket ini. Klik "+ Pilih dari Bank Soal" atau "+ Buat Soal Baru".' : 'Paket ini belum memiliki soal.'}
                        </div>
                    ) : (
                        <div className="divide-y divide-slate-100">
                            {questions.map((q, index) => (
                                <div key={q.id} className="py-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
                                    <div className="flex-1 space-y-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-bold text-xs text-slate-400">#{index + 1}</span>
                                            <span className="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                                {typeLabels[q.type] || q.type}
                                            </span>
                                            {q.competency && (
                                                <span className="rounded bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700">
                                                    {q.competency.name}
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-sm font-medium text-slate-900 line-clamp-2">
                                            {q.title || q.prompt}
                                        </p>
                                    </div>
                                    <div className="shrink-0 flex flex-wrap items-center gap-2">
                                        <button
                                            onClick={() => setPreviewQuestion({ ...q, competency_name: q.competency?.name })}
                                            className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 flex items-center gap-1"
                                            title="Pratinjau Soal"
                                        >
                                            👁️ Lihat
                                        </button>
                                        {canManage && <button
                                            onClick={() => openSwapModal(q)}
                                            className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100"
                                        >
                                            ⇄ Ganti Soal
                                        </button>}
                                        {canManage && <button
                                            onClick={() => handleRemoveQuestion(q)}
                                            className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100"
                                        >
                                            ✕ Hapus dari Paket
                                        </button>}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* ── MODAL 1: GANTI SOAL ── */}
            <Modal show={swapModalOpen} onClose={() => setSwapModalOpen(false)} maxWidth="2xl">
                <div className="p-6 space-y-5">
                    <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                        <h3 className="text-lg font-bold text-slate-900">Ganti Soal Paket</h3>
                        <button
                            onClick={() => setSwapModalOpen(false)}
                            className="text-slate-400 hover:text-slate-600 font-bold text-lg"
                        >
                            ✕
                        </button>
                    </div>

                    {targetQuestionToSwap && (
                        <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs space-y-1">
                            <span className="font-semibold text-slate-500 uppercase tracking-wide">Soal yang diganti saat ini:</span>
                            <p className="font-bold text-slate-800 text-sm">{targetQuestionToSwap.prompt}</p>
                        </div>
                    )}

                    <div>
                        <label className="block text-xs font-semibold text-slate-700 mb-2">
                            Pilih Soal Pengganti dari Bank Soal ({assessment.subject?.name ?? 'Semua Mapel'} · Kelas {assessment.grade_level}):
                        </label>

                        <div className="mb-3 grid gap-3 sm:grid-cols-2">
                            <label className="text-xs font-semibold text-slate-700">
                                Subkompetensi
                                <select
                                    value={swapSubCompetencyId}
                                    onChange={(event) => changeSwapSubCompetency(event.target.value)}
                                    className="mt-1 block w-full rounded-xl border-slate-300 text-xs focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option value="all">Semua subkompetensi ({standaloneBankQuestions.length} soal)</option>
                                    {subCompetencies.map((subCompetency) => {
                                        const availableCount = replacementCountBySubCompetency[subCompetency.id] ?? 0;

                                        return (
                                            <option
                                                key={subCompetency.id}
                                                value={subCompetency.id}
                                                disabled={availableCount === 0}
                                            >
                                                {subCompetency.parent_name} · {subCompetency.name} ({availableCount})
                                            </option>
                                        );
                                    })}
                                </select>
                            </label>

                            <label className="text-xs font-semibold text-slate-700">
                                Cari soal
                                <input
                                    type="search"
                                    value={swapSearch}
                                    onChange={(event) => changeSwapSearch(event.target.value)}
                                    placeholder="Cari teks atau kode subkompetensi…"
                                    className="mt-1 block w-full rounded-xl border-slate-300 text-xs focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </label>
                        </div>

                        {standaloneBankQuestions.length === 0 ? (
                            <p className="text-xs text-rose-600 font-medium p-4 border rounded-xl bg-rose-50/50">
                                Tidak ada soal terbit pengganti yang tersedia di bank soal untuk jenjang dan mapel ini. Silakan buat soal baru terlebih dahulu.
                            </p>
                        ) : replacementQuestions.length === 0 ? (
                            <p className="rounded-xl border border-amber-200 bg-amber-50/70 p-4 text-xs font-medium text-amber-700">
                                Tidak ada soal terbit yang cocok dengan filter ini. Pilih subkompetensi lain atau tampilkan semua subkompetensi.
                            </p>
                        ) : (
                            <div className="max-h-64 overflow-y-auto divide-y divide-slate-100 border rounded-xl">
                                {replacementQuestions.map((bankQ) => (
                                    <div
                                        key={bankQ.id}
                                        className={`flex items-start justify-between gap-3 p-3 text-xs cursor-pointer hover:bg-slate-50 transition-colors ${
                                            selectedReplacementId === bankQ.id ? 'bg-indigo-50/70 border-l-4 border-indigo-600' : ''
                                        }`}
                                        onClick={() => setSelectedReplacementId(bankQ.id)}
                                    >
                                        <div className="flex items-start gap-3">
                                            <input
                                                type="radio"
                                                name="replacement_q"
                                                checked={selectedReplacementId === bankQ.id}
                                                onChange={() => setSelectedReplacementId(bankQ.id)}
                                                className="mt-0.5 text-indigo-600 focus:ring-indigo-500"
                                            />
                                            <div className="space-y-1">
                                                <span className="font-semibold text-indigo-700 block">
                                                    {bankQ.parent_competency_name && `${bankQ.parent_competency_name} · `}
                                                    {bankQ.competency_name} ({typeLabels[bankQ.type] || bankQ.type})
                                                </span>
                                                <p className="text-slate-800 font-medium">{bankQ.prompt}</p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                setPreviewQuestion(bankQ);
                                            }}
                                            className="shrink-0 rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-100 flex items-center gap-1 shadow-xs"
                                            title="Cek Detail Soal Ini"
                                        >
                                            👁️ Cek Soal
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="flex justify-end gap-3 border-t border-slate-100 pt-4">
                        <button
                            type="button"
                            onClick={() => setSwapModalOpen(false)}
                            className="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            Batal
                        </button>
                        <button
                            type="button"
                            onClick={handleSwapSubmit}
                            disabled={
                                !selectedReplacementId ||
                                !replacementQuestions.some((question) => question.id === selectedReplacementId)
                            }
                            className="rounded-xl bg-indigo-600 px-5 py-2 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            Konfirmasi Ganti Soal
                        </button>
                    </div>
                </div>
            </Modal>

            {/* ── MODAL 2: PILIH DARI BANK SOAL ── */}
            <Modal show={addFromBankModalOpen} onClose={() => setAddFromBankModalOpen(false)} maxWidth="2xl">
                <div className="p-6 space-y-5">
                    <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                        <h3 className="text-lg font-bold text-slate-900">Pilih Soal atau Bundel dari Bank Soal</h3>
                        <button
                            onClick={() => setAddFromBankModalOpen(false)}
                            className="text-slate-400 hover:text-slate-600 font-bold text-lg"
                        >
                            ✕
                        </button>
                    </div>

                    <input
                        type="text"
                        value={bankSearch}
                        onChange={(e) => setBankSearch(e.target.value)}
                        placeholder="Cari soal berdasarkan teks atau kompetensi…"
                        className="w-full rounded-xl border-slate-300 text-xs focus:border-indigo-500 focus:ring-indigo-500"
                    />

                    {filteredBankQuestions.length === 0 ? (
                        <p className="text-xs text-slate-400 py-4 text-center">
                            Tidak ada soal pengganti lain di bank soal.
                        </p>
                    ) : (
                        <div className="max-h-72 overflow-y-auto divide-y divide-slate-100 border rounded-xl">
                            {filteredBankQuestions.map((bankQ) => (
                                <div key={bankQ.is_bundle ? `bundle-${bankQ.story_generation_id}` : bankQ.id} className={`p-3 flex items-center justify-between gap-3 hover:bg-slate-50 ${bankQ.is_bundle ? 'bg-indigo-50/40' : ''}`}>
                                    <div className="text-xs space-y-0.5 flex-1">
                                        <span className="font-semibold text-indigo-700 block">
                                            {bankQ.is_bundle
                                                ? `Bundel · ${bankQ.bundle_question_count} soal`
                                                : `${bankQ.competency_name} · ${typeLabels[bankQ.type] || bankQ.type}`}
                                        </span>
                                        {bankQ.is_bundle && bankQ.bundle_title && (
                                            <p className="font-bold text-slate-900">{bankQ.bundle_title}</p>
                                        )}
                                        <p className={`text-slate-800 font-medium ${bankQ.is_bundle ? 'line-clamp-3 whitespace-pre-line' : ''}`}>
                                            {bankQ.is_bundle ? bankQ.stimulus : bankQ.prompt}
                                        </p>
                                    </div>
                                    <div className="shrink-0 flex items-center gap-2">
                                        {bankQ.is_bundle ? (
                                            <button
                                                type="button"
                                                onClick={() => setPreviewBundle(bankQ)}
                                                className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                                            >
                                                👁️ Lihat Bundel
                                            </button>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => setPreviewQuestion(bankQ)}
                                                className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 flex items-center gap-1"
                                                title="Cek Detail Soal"
                                            >
                                                👁️ Cek
                                            </button>
                                        )}
                                        <button
                                            onClick={() => handleAttachBankQuestion(bankQ)}
                                            className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-500"
                                        >
                                            {bankQ.is_bundle ? '+ Tambahkan Bundel' : '+ Tambahkan'}
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </Modal>

            {/* ── MODAL 3: PRATINJAU BUNDEL ── */}
            <Modal show={!!previewBundle} onClose={() => setPreviewBundle(null)} maxWidth="4xl">
                {previewBundle && (
                    <div className="max-h-[90vh] overflow-y-auto p-6 space-y-5">
                        <div className="flex items-start justify-between gap-4 border-b border-slate-100 pb-3">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900">👁️ Pratinjau Bundel Lengkap</h3>
                                <p className="mt-1 text-sm font-semibold text-indigo-700">
                                    {previewBundle.bundle_title || `Bundel ${previewBundle.bundle_question_count} soal`}
                                </p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Satu stimulus dengan {previewBundle.bundle_question_count} soal, kunci jawaban, dan pembahasan.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setPreviewBundle(null)}
                                className="text-lg font-bold text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>

                        {previewBundle.stimulus && (
                            <div className="rounded-xl border border-amber-200 bg-amber-50/70 p-4 text-sm leading-7 text-slate-800">
                                <span className="mb-2 block text-xs font-bold uppercase tracking-wide text-amber-900">
                                    📄 Stimulus / Teks Bacaan
                                </span>
                                <StimulusText text={previewBundle.stimulus} className="block whitespace-pre-wrap" />
                            </div>
                        )}

                        <div className="space-y-4">
                            {(previewBundle.bundle_questions || []).map((question, questionIndex) => (
                                <section key={question.id} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="rounded-full bg-indigo-600 px-2.5 py-1 text-xs font-bold text-white">
                                            Soal {questionIndex + 1}
                                        </span>
                                        <span className="rounded-md bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700">
                                            {typeLabels[question.type] || question.type}
                                        </span>
                                        <span className="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                            Level {question.difficulty}
                                        </span>
                                        <span className="text-xs font-semibold text-emerald-700">{question.competency_name}</span>
                                    </div>

                                    <p className="mt-3 rounded-lg bg-slate-50 p-3 text-sm font-bold leading-relaxed text-slate-900">
                                        <FormattedText text={question.prompt} />
                                    </p>

                                    {question.options && question.options.length > 0 && (
                                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                            {question.options.map((option, optionIndex) => (
                                                <div
                                                    key={option.id || optionIndex}
                                                    className={`flex items-start gap-2 rounded-lg border p-2.5 text-xs ${option.is_correct ? 'border-emerald-400 bg-emerald-50 font-bold text-emerald-950' : 'border-slate-200 text-slate-700'}`}
                                                >
                                                    <span className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold ${option.is_correct ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600'}`}>
                                                        {option.label || String.fromCharCode(65 + optionIndex)}
                                                    </span>
                                                    <FormattedText text={option.content || option.option_text || ''} />
                                                    {option.is_correct && <span className="ml-auto text-emerald-700">✓ Kunci</span>}
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    {question.explanation && (
                                        <div className="mt-3 rounded-lg border border-indigo-200 bg-indigo-50/70 p-3 text-xs leading-relaxed text-indigo-950">
                                            <span className="font-bold">💡 Pembahasan: </span>
                                            <FormattedText text={question.explanation} className="whitespace-pre-wrap" />
                                        </div>
                                    )}
                                </section>
                            ))}
                        </div>

                        <div className="flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
                            {previewBundle.story_generation_id ? (
                                <Link
                                    href={route('story-questions.show', previewBundle.story_generation_id)}
                                    target="_blank"
                                    className="flex items-center gap-1.5 rounded-xl border border-indigo-300 bg-indigo-50 px-4 py-2 text-xs font-bold text-indigo-700 transition-colors hover:bg-indigo-100"
                                >
                                    ✏️ Edit Bundel
                                </Link>
                            ) : <div />}
                            <button
                                type="button"
                                onClick={() => setPreviewBundle(null)}
                                className="rounded-xl bg-slate-900 px-5 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                            >
                                Tutup Pratinjau
                            </button>
                        </div>
                    </div>
                )}
            </Modal>

            {/* ── MODAL 4: PRATINJAU / CEK DETAIL SOAL (MODAL MATA 👁️) ── */}
            <Modal show={!!previewQuestion} onClose={() => setPreviewQuestion(null)} maxWidth="2xl">
                {previewQuestion && (
                    <div className="p-6 space-y-5">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                            <div>
                                <h3 className="text-base font-bold text-slate-900 flex items-center gap-2">
                                    <span>👁️ Pratinjau Soal Lengkap</span>
                                </h3>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Cek stimulus, pertanyaan, kunci jawaban, dan pembahasan soal.
                                </p>
                            </div>
                            <button
                                onClick={() => setPreviewQuestion(null)}
                                className="text-slate-400 hover:text-slate-600 font-bold text-lg"
                            >
                                ✕
                            </button>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <span className="rounded-md bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700">
                                {typeLabels[previewQuestion.type] || previewQuestion.type}
                            </span>
                            <span className="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                Tingkat Kesulitan {previewQuestion.difficulty}
                            </span>
                            {previewQuestion.competency_name && (
                                <span className="rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                    {previewQuestion.competency_name}
                                </span>
                            )}
                        </div>

                        {previewQuestion.stimulus && (
                            <div className="rounded-xl border border-amber-200 bg-amber-50/70 p-4 text-xs leading-relaxed text-slate-800">
                                <span className="font-bold text-amber-900 block mb-1 text-xs uppercase tracking-wide">
                                    📄 Stimulus / Teks Bacaan:
                                </span>
                                <StimulusText text={previewQuestion.stimulus} className="block whitespace-pre-wrap" />
                            </div>
                        )}

                        <div>
                            <span className="text-xs font-bold text-slate-500 uppercase tracking-wide block mb-1.5">
                                ❓ Teks Pertanyaan:
                            </span>
                            <p className="text-sm font-bold text-slate-900 leading-relaxed bg-slate-50 p-4 rounded-xl border border-slate-200">
                                <FormattedText text={previewQuestion.prompt} />
                            </p>
                        </div>

                        {previewQuestion.options && previewQuestion.options.length > 0 && (
                            <div>
                                <span className="text-xs font-bold text-slate-500 uppercase tracking-wide block mb-2">
                                    🔘 Pilihan & Kunci Jawaban:
                                </span>
                                <div className="space-y-2">
                                    {previewQuestion.options.map((opt, idx) => {
                                        const text = opt.content || opt.option_text || opt.label || '';
                                        const labelLetter = opt.label || String.fromCharCode(65 + idx);
                                        const isMcma = previewQuestion.type === 'multiple_choice';
                                        return (
                                            <div
                                                key={opt.id || idx}
                                                className={`flex items-center gap-3 p-3 rounded-xl border text-xs font-medium transition-colors ${
                                                    opt.is_correct
                                                        ? 'border-emerald-400 bg-emerald-50/90 text-emerald-950 font-bold shadow-xs'
                                                        : 'border-slate-200 bg-white text-slate-700'
                                                }`}
                                            >
                                                <span
                                                    className={`w-6 h-6 ${isMcma ? 'rounded-md border-2' : 'rounded-full'} flex items-center justify-center text-xs font-bold shrink-0 ${
                                                        opt.is_correct
                                                            ? 'border-emerald-600 bg-emerald-600 text-white shadow-xs'
                                                            : isMcma ? 'border-slate-300 bg-white text-transparent' : 'bg-slate-100 text-slate-600'
                                                    }`}
                                                >
                                                    {isMcma ? '✓' : labelLetter}
                                                </span>
                                                <FormattedText text={text} className="flex-1 text-sm" />
                                                {opt.is_correct && (
                                                    <span className="text-[10px] font-extrabold uppercase tracking-wide text-emerald-800 bg-emerald-200/80 px-2.5 py-1 rounded-full shrink-0">
                                                        ✓ Kunci Jawaban
                                                    </span>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                        {previewQuestion.explanation && (
                            <div className="rounded-xl border border-indigo-200 bg-indigo-50/70 p-4 text-xs leading-relaxed text-indigo-950">
                                <span className="font-bold text-indigo-900 block mb-1 uppercase tracking-wide">
                                    💡 Pembahasan:
                                </span>
                                <FormattedText text={previewQuestion.explanation} className="block whitespace-pre-wrap" />
                            </div>
                        )}

                        <div className="flex items-center justify-between pt-3 border-t border-slate-100">
                            {previewQuestion.id ? (
                                <Link
                                    href={route('questions.edit', previewQuestion.id)}
                                    className="rounded-xl border border-indigo-300 bg-indigo-50 px-4 py-2 text-xs font-bold text-indigo-700 hover:bg-indigo-100 flex items-center gap-1.5 transition-colors"
                                    target="_blank"
                                >
                                    ✏️ Edit Soal Ini
                                </Link>
                            ) : <div />}
                            <button
                                onClick={() => setPreviewQuestion(null)}
                                className="rounded-xl bg-slate-900 px-5 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                            >
                                Tutup Pratinjau
                            </button>
                        </div>
                    </div>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
