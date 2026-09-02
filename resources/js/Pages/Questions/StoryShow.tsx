import InlineQuestionEditor, { InlineEditableQuestion } from '@/Components/InlineQuestionEditor';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';

type Generation = {
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    request_payload: { source?: 'ai' | 'manual'; subject_id?: number; theme: string; format?: 'direct' | 'story'; question_style?: 'direct' | 'reasoning'; use_illustration?: boolean; illustration_mode?: 'lite' | 'pro'; paragraph_count?: number; question_count?: number };
    result_payload?: { title: string; format?: 'direct' | 'story'; story?: string; visual_description?: string; visual_spec?: Record<string, unknown> | null; paragraph_count?: number; question_count: number };
    error?: string;
};

type Question = InlineEditableQuestion & {
    id: number;
    title?: string;
    prompt: string;
    stimulus?: string;
    status: string;
    difficulty: number;
    grade_level: number;
    explanation?: string;
    illustration_url?: string;
    metadata?: {
        accepted_answers?: string[];
        illustration?: { alt?: string };
        matching_pairs?: { left_id: string; left: string; right_id: string; right: string }[];
        matching_distractors?: { id: string; content: string }[];
        matrix_columns?: { id: string; label: string }[];
        matrix_rows?: { id: string; statement: string; correct_column_id: string }[];
    };
    competency: { code: string; name: string; grade_level: number };
    question_blueprint?: { code: string; name: string };
    author: { name: string };
    approver?: { name: string };
    approved_at?: string;
    options: { id: number; label: string; content: string; is_correct: boolean }[];
    verification: {
        required: number;
        count: number;
        remaining: number;
        currentUserVerified: boolean;
        verifiers: { id: number | null; name: string; verifiedAt: string }[];
    };
};

type Illustration = {
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    result_payload?: { batch_state?: string; fallback_used?: boolean };
    error?: string;
};

type DuplicateCandidate = {
    id: number;
    title?: string;
    prompt: string;
    status: string;
    competency: string;
    similarity: number;
    blocking: boolean;
};

export default function StoryShow({ generation, questions, illustration, canVerify }: { generation: Generation; questions: Question[]; illustration?: Illustration; canVerify: boolean }) {
    const waiting = generation.status === 'pending' || generation.status === 'processing';
    const storyMode = generation.request_payload.format !== 'direct';
    const manualBundle = generation.request_payload.source === 'manual';
    const hasRecoverableVisualSpec = Boolean(generation.result_payload?.visual_spec);
    const illustrationRequested = (!manualBundle && storyMode) || generation.request_payload.use_illustration === true || hasRecoverableVisualSpec;
    const routePrefix = storyMode ? 'story-questions' : 'ai-questions';
    const illustrationWaiting = illustration?.status === 'pending' || illustration?.status === 'processing';
    const [publishing, setPublishing] = useState(false);
    const [requestingIllustration, setRequestingIllustration] = useState(false);
    const [editingQuestionId, setEditingQuestionId] = useState<number | null>(null);
    const [verifyingQuestionId, setVerifyingQuestionId] = useState<number | null>(null);
    const [checkingQuestionId, setCheckingQuestionId] = useState<number | null>(null);
    const [duplicateResult, setDuplicateResult] = useState<{ questionId: number; blocking: boolean; candidates: DuplicateCandidate[] } | null>(null);
    const [studentPreviewOpen, setStudentPreviewOpen] = useState(false);
    const [studentPreviewIndex, setStudentPreviewIndex] = useState(0);
    const [previewOptionAnswers, setPreviewOptionAnswers] = useState<Record<number, number[]>>({});
    const [previewMatrixAnswers, setPreviewMatrixAnswers] = useState<Record<number, Record<string, string>>>({});
    const publishedCount = questions.filter((question) => question.status === 'published').length;
    const unpublishedCount = questions.length - publishedCount;
    const allPublished = questions.length > 0 && unpublishedCount === 0;
    const allVerifiedByCurrentUser = questions.length > 0 && questions.every((question) => question.verification.currentUserVerified);
    const previewQuestion = questions[studentPreviewIndex];

    const choosePreviewOption = (question: Question, optionId: number) => {
        setPreviewOptionAnswers((current) => {
            const selected = current[question.id] || [];
            return {
                ...current,
                [question.id]: question.type === 'multiple_choice'
                    ? selected.includes(optionId) ? selected.filter((id) => id !== optionId) : [...selected, optionId]
                    : [optionId],
            };
        });
    };

    const choosePreviewMatrix = (questionId: number, rowId: string, columnId: string) => {
        setPreviewMatrixAnswers((current) => ({
            ...current,
            [questionId]: { ...(current[questionId] || {}), [rowId]: columnId },
        }));
    };

    const verifyBundle = () => {
        if (allVerifiedByCurrentUser || publishing) return;
        if (!window.confirm(`Catat verifikasi Anda untuk seluruh soal dalam bundel ini? Pastikan ${storyMode ? 'cerita, ' : ''}kunci jawaban, dan pembahasannya sudah diperiksa.`)) return;

        router.post(route(`${routePrefix}.publish`, generation.id), {}, {
            preserveScroll: true,
            onStart: () => setPublishing(true),
            onFinish: () => setPublishing(false),
        });
    };

    const requestIllustration = () => {
        if (requestingIllustration || illustrationWaiting) return;

        router.post(route(`${routePrefix}.illustration.store`, generation.id), {}, {
            preserveScroll: true,
            onStart: () => setRequestingIllustration(true),
            onFinish: () => setRequestingIllustration(false),
        });
    };

    const checkDuplicates = async (questionId: number) => {
        if (checkingQuestionId !== null) return;
        setCheckingQuestionId(questionId);
        setDuplicateResult(null);

        try {
            const response = await axios.post(route('questions.duplicate-check', questionId));
            setDuplicateResult({ questionId, ...response.data });
        } catch {
            window.alert('Pemeriksaan duplikasi belum berhasil. Silakan coba lagi.');
        } finally {
            setCheckingQuestionId(null);
        }
    };

    const verifyQuestion = (questionId: number) => {
        if (verifyingQuestionId !== null) return;
        if (duplicateResult?.questionId !== questionId || duplicateResult.blocking) return;
        if (!window.confirm('Pemeriksaan duplikasi selesai. Catat verifikasi Anda untuk soal ini? Soal terbit setelah mencapai tiga guru berbeda.')) return;

        router.post(route('questions.approve', questionId), {}, {
            preserveScroll: true,
            onStart: () => setVerifyingQuestionId(questionId),
            onFinish: () => setVerifyingQuestionId(null),
        });
    };

    const addPublishedVerification = (questionId: number) => {
        if (verifyingQuestionId !== null) return;
        if (!window.confirm('Tambahkan verifikasi Anda pada soal yang sudah terbit ini?')) return;

        router.post(route('questions.approve', questionId), {}, {
            preserveScroll: true,
            onStart: () => setVerifyingQuestionId(questionId),
            onFinish: () => setVerifyingQuestionId(null),
        });
    };

    const deleteQuestion = (questionId: number) => {
        if (!window.confirm('Hapus soal ini? Tindakan ini tidak dapat dibatalkan.')) return;

        router.delete(route('generated-questions.destroy', { generation: generation.id, question: questionId }), {
            preserveScroll: true,
            onSuccess: () => {
                setEditingQuestionId(null);
                setDuplicateResult(null);
            },
        });
    };

    useEffect(() => {
        if (!waiting && !illustrationWaiting) return;

        const timer = window.setInterval(() => {
            router.reload({ only: ['generation', 'questions', 'illustration'] });
        }, waiting ? 2000 : 15000);

        return () => window.clearInterval(timer);
    }, [waiting, illustrationWaiting]);

    useEffect(() => {
        if (generation.status !== 'completed' || storyMode || !illustrationRequested || illustration || requestingIllustration) return;
        requestIllustration();
    }, [generation.status, storyMode, illustrationRequested, illustration?.id, requestingIllustration]);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <p className="text-sm font-medium text-indigo-600">{manualBundle ? 'Paket Soal Cerita Manual' : storyMode ? 'Paket Soal Cerita AI' : 'Paket Soal AI'}</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">{generation.result_payload?.title || generation.request_payload.theme}</h1>
                    </div>
                    <div className="flex gap-2">
                        {generation.status === 'completed' && questions.length > 0 && <button type="button" onClick={() => { setStudentPreviewIndex(0); setStudentPreviewOpen(true); }} className="inline-flex items-center gap-2 rounded-lg border border-sky-300 bg-white px-4 py-2 text-sm font-semibold text-sky-700 hover:bg-sky-50" title="Lihat tampilan saat siswa mengerjakan">
                            <svg viewBox="0 0 24 24" aria-hidden="true" className="h-5 w-5 fill-none stroke-current stroke-2"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" /><circle cx="12" cy="12" r="2.75" /></svg>
                            Pratinjau Siswa
                        </button>}
                        <Link href={route(manualBundle ? 'manual-story-bundles.create' : `${routePrefix}.create`, { subject_id: generation.request_payload.subject_id })} className="rounded-lg border border-indigo-300 bg-white px-4 py-2 text-sm font-semibold text-indigo-700">Buat Paket Baru</Link>
                        <Link href={route('questions.index')} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Bank Soal</Link>
                    </div>
                </div>
            }
        >
            <Head title={manualBundle ? 'Bundle Soal Manual' : storyMode ? 'Hasil Soal Cerita AI' : 'Hasil Soal AI'} />
            <Modal show={studentPreviewOpen} maxWidth="5xl" onClose={() => setStudentPreviewOpen(false)}>
                <div className="flex items-center justify-between border-b border-slate-200 bg-slate-950 px-5 py-4 text-white">
                    <div><p className="text-xs font-semibold uppercase tracking-widest text-emerald-300">Pratinjau siswa</p><h2 className="mt-1 font-bold">Simulasi tampilan saat mengerjakan</h2></div>
                    <button type="button" onClick={() => setStudentPreviewOpen(false)} aria-label="Tutup pratinjau" className="rounded-lg p-2 text-2xl leading-none text-slate-300 hover:bg-white/10 hover:text-white">×</button>
                </div>
                {previewQuestion && <div className="max-h-[78vh] overflow-y-auto bg-slate-100 p-4 sm:p-6">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-3">
                        <p className="text-sm font-semibold text-slate-700">Soal {studentPreviewIndex + 1} dari {questions.length}</p>
                        <div className="flex flex-wrap gap-2">{questions.map((question, index) => <button key={question.id} type="button" onClick={() => setStudentPreviewIndex(index)} className={`h-9 w-9 rounded-lg text-sm font-bold ${index === studentPreviewIndex ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>{index + 1}</button>)}</div>
                    </div>

                    <div className={`grid overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm ${storyMode ? 'lg:grid-cols-2' : ''}`}>
                        {storyMode && <aside className="border-b border-slate-200 bg-slate-50 p-5 lg:border-b-0 lg:border-r lg:p-7">
                            <p className="text-xs font-bold uppercase tracking-wider text-indigo-600">Stimulus</p>
                            <h3 className="mt-2 text-xl font-bold text-slate-900">{generation.result_payload?.title}</h3>
                            <div className="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-700">{generation.result_payload?.story}</div>
                        </aside>}
                        <section className="min-w-0 p-5 lg:p-7">
                            {!storyMode && previewQuestion.stimulus && <div className="mb-5 whitespace-pre-wrap rounded-xl bg-slate-50 p-4 text-sm leading-7 text-slate-700">{previewQuestion.stimulus}</div>}
                            {previewQuestion.illustration_url && <img src={previewQuestion.illustration_url} alt={previewQuestion.metadata?.illustration?.alt || 'Ilustrasi soal'} className="mb-5 max-h-72 w-full rounded-xl border border-slate-200 object-contain" />}
                            <p className="text-sm font-semibold text-emerald-600">Soal {studentPreviewIndex + 1}</p>
                            <h3 className="mt-3 text-lg font-semibold leading-8 text-slate-900">{previewQuestion.prompt}</h3>

                            {previewQuestion.type === 'category_matrix' && previewQuestion.metadata?.matrix_columns && previewQuestion.metadata.matrix_rows ? <div className="mt-5 overflow-x-auto rounded-xl border border-slate-200">
                                <table className="w-full min-w-[520px] text-sm"><thead className="bg-slate-100"><tr><th className="p-3 text-left">Pernyataan</th>{previewQuestion.metadata.matrix_columns.map((column) => <th key={column.id} className="p-3 text-center">{column.label}</th>)}</tr></thead><tbody>{previewQuestion.metadata.matrix_rows.map((row) => <tr key={row.id} className="border-t border-slate-200"><td className="p-3 leading-6">{row.statement}</td>{previewQuestion.metadata?.matrix_columns?.map((column) => {
                                    const selected = previewMatrixAnswers[previewQuestion.id]?.[row.id] === column.id;
                                    return <td key={column.id} className="p-3 text-center"><button type="button" onClick={() => choosePreviewMatrix(previewQuestion.id, row.id, column.id)} aria-label={`${row.statement}: ${column.label}`} className={`inline-flex h-8 w-8 items-center justify-center rounded-full border-2 text-sm font-bold ${selected ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 text-transparent'}`}>✓</button></td>;
                                })}</tr>)}</tbody></table>
                            </div> : previewQuestion.options.length > 0 ? <div className="mt-5 space-y-3">{previewQuestion.options.map((option) => {
                                const selected = (previewOptionAnswers[previewQuestion.id] || []).includes(option.id);
                                return <button key={option.id} type="button" onClick={() => choosePreviewOption(previewQuestion, option.id)} className={`flex w-full items-center gap-3 rounded-xl border p-4 text-left ${selected ? 'border-emerald-500 bg-emerald-50 ring-1 ring-emerald-500' : 'border-slate-200 hover:border-slate-300'}`}>{previewQuestion.type === 'multiple_choice' ? <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded border-2 text-sm font-bold ${selected ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 text-transparent'}`}>✓</span> : <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full border text-sm font-bold ${selected ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 text-slate-600'}`}>{option.label}</span>}<span className="text-sm leading-6 text-slate-800">{option.content}</span></button>;
                            })}</div> : previewQuestion.type === 'short_answer' ? <textarea rows={3} placeholder="Tulis jawabanmu" className="mt-5 block w-full rounded-xl border-slate-300" /> : <p className="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Pratinjau interaktif untuk tipe soal ini belum tersedia.</p>}
                        </section>
                    </div>

                    <div className="mt-4 flex items-center justify-between">
                        <button type="button" disabled={studentPreviewIndex === 0} onClick={() => setStudentPreviewIndex((index) => index - 1)} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 disabled:opacity-40">Sebelumnya</button>
                        {studentPreviewIndex < questions.length - 1 ? <button type="button" onClick={() => setStudentPreviewIndex((index) => index + 1)} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Berikutnya</button> : <button type="button" onClick={() => setStudentPreviewOpen(false)} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Selesai pratinjau</button>}
                    </div>
                </div>}
            </Modal>
            <div className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
                {waiting && (
                    <div className="rounded-2xl border border-amber-200 bg-amber-50 p-8 text-center">
                        <div className="mx-auto h-8 w-8 animate-spin rounded-full border-4 border-amber-200 border-t-amber-600" />
                        <h2 className="mt-4 font-semibold text-amber-950">AI sedang membuat {storyMode ? 'cerita dan soal' : 'soal'}</h2>
                        <p className="mt-1 text-sm text-amber-800">Halaman diperbarui otomatis. Biasanya selesai dalam beberapa detik.</p>
                    </div>
                )}

                {generation.status === 'failed' && (
                    <div className="rounded-2xl border border-rose-200 bg-rose-50 p-6">
                        <h2 className="font-semibold text-rose-900">Pembuatan gagal</h2>
                        <p className="mt-2 text-sm text-rose-700">{generation.error || 'AI tidak dapat menghasilkan paket soal yang valid.'}</p>
                        <button onClick={() => router.post(route(`${routePrefix}.retry`, generation.id))} className="mt-4 rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-600">
                            Coba Proses Lagi
                        </button>
                    </div>
                )}

                {generation.status === 'completed' && generation.result_payload && (
                    <>
                        <article className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-indigo-600">{storyMode ? 'Tema' : 'Topik'}: {generation.request_payload.theme || 'Dipilih oleh AI'}</p>
                                    <h2 className="mt-1 text-xl font-bold text-slate-900">{generation.result_payload.title}</h2>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {storyMode && <span className="rounded-full bg-indigo-50 px-3 py-1 text-sm font-semibold text-indigo-700">{generation.result_payload.paragraph_count || generation.request_payload.paragraph_count || '-'} paragraf</span>}
                                    {!storyMode && <span className="rounded-full bg-violet-50 px-3 py-1 text-sm font-semibold text-violet-700">{generation.request_payload.question_style === 'reasoning' ? 'Penalaran' : 'Langsung'}</span>}
                                    <span className={`rounded-full px-3 py-1 text-sm font-semibold ${allPublished ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                                        {allPublished ? `${publishedCount} soal terbit` : `${unpublishedCount} belum terbit · ${publishedCount} terbit`}
                                    </span>
                                </div>
                            </div>
                            {storyMode && <div className="mt-5 whitespace-pre-wrap rounded-xl bg-slate-50 p-5 leading-7 text-slate-700">{generation.result_payload.story}</div>}
                        </article>

                        <div className="flex flex-col justify-between gap-4 rounded-xl border border-indigo-200 bg-indigo-50 p-4 sm:flex-row sm:items-center">
                            <p className="text-sm text-indigo-900">
                                {manualBundle ? 'Bundle dibuat manual.' : 'AI memakai kompetensi pilihan guru.'} Setiap soal terbit setelah minimal tiga guru berbeda; verifikator tambahan tetap dapat dicatat. {!storyMode && 'Soal langsung diverifikasi satu per satu dan tidak menjadi bundle.'}
                            </p>
                            {storyMode && canVerify && <button
                                type="button"
                                onClick={verifyBundle}
                                disabled={allVerifiedByCurrentUser || publishing || questions.length === 0}
                                className="shrink-0 rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-600 disabled:cursor-not-allowed disabled:bg-emerald-600"
                            >
                                {publishing ? 'Menyimpan...' : allVerifiedByCurrentUser ? 'Anda Sudah Verifikasi' : allPublished ? 'Tambah Verifikasi Bundle' : 'Verifikasi 1 Bundle'}
                            </button>}
                        </div>

                        {illustrationRequested && <section className="flex flex-col justify-between gap-4 rounded-xl border border-sky-200 bg-sky-50 p-5 sm:flex-row sm:items-center">
                            <div className="space-y-2 flex-1">
                                <h2 className="font-semibold text-sky-950 flex items-center gap-2">
                                    <span>🎨 Ilustrasi & Diagram Visual Soal AI</span>
                                </h2>
                                {generation.result_payload?.visual_description && (
                                    <div className="rounded-lg border border-sky-200 bg-white p-3 text-xs text-slate-800 leading-relaxed">
                                        <span className="font-bold text-sky-900 block mb-1">Acuan Visual AI:</span>
                                        {generation.result_payload.visual_description}
                                    </div>
                                )}
                                <p className="text-xs text-sky-800">
                                    Sistem membuat ilustrasi / diagram yang presisi sesuai dengan materi dan kebutuhan soal.
                                </p>
                                {illustration && (
                                    <p className="text-xs text-sky-700 font-medium">
                                        Status: {illustration.status === 'completed' ? '✓ Ilustrasi/Diagram Selesai' : illustration.status === 'failed' ? 'Gagal' : 'Sedang diproses…'}
                                    </p>
                                )}
                                {illustration?.error && <p className="text-xs text-rose-700">{illustration.error}</p>}
                            </div>
                            <button
                                type="button"
                                onClick={requestIllustration}
                                disabled={requestingIllustration || illustrationWaiting || illustration?.status === 'completed'}
                                className="shrink-0 rounded-lg bg-sky-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-sky-600 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {requestingIllustration
                                    ? 'Mengirim...'
                                    : illustrationWaiting
                                      ? 'Sedang diproses...'
                                      : illustration?.status === 'completed'
                                        ? 'Ilustrasi selesai'
                                        : illustration?.status === 'failed'
                                          ? 'Coba buat lagi'
                                          : 'Buat ilustrasi'}
                            </button>
                        </section>}

                        <section className="space-y-4">
                            {questions.map((question, index) => (
                                <article key={question.id} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                                    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                        <div>
                                            <div className="flex flex-wrap gap-2 text-xs font-semibold">
                                                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">Soal {index + 1}</span>
                                                <span className="rounded-full bg-indigo-50 px-2.5 py-1 text-indigo-700">{question.competency.code}</span>
                                                {question.question_blueprint && <span className="rounded-full bg-violet-50 px-2.5 py-1 text-violet-700">{question.question_blueprint.name}</span>}
                                                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">Kelas {question.grade_level}</span>
                                                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">Kesulitan {question.difficulty}</span>
                                                <span className={`rounded-full px-2.5 py-1 ${question.status === 'published' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                                                    {question.status === 'published' ? 'Terbit' : question.status === 'review' ? 'Menunggu verifikasi' : 'Draft'}
                                                </span>
                                                <span className="rounded-full bg-blue-50 px-2.5 py-1 text-blue-700">{question.verification.count} guru · min. {question.verification.required}</span>
                                            </div>
                                            {!storyMode && question.stimulus && <p className="mt-3 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm leading-6 text-slate-700">{question.stimulus}</p>}
                                            <h3 className="mt-3 text-lg font-semibold text-slate-900">{question.prompt}</h3>
                                        </div>
                                        {question.status === 'published' ? (
                                            <div className="flex shrink-0 flex-wrap gap-2">
                                                <span className="rounded-lg bg-emerald-50 px-4 py-2 text-center text-sm font-semibold text-emerald-700">Sudah terbit</span>
                                                {!storyMode && canVerify && !question.verification.currentUserVerified && (
                                                    <button type="button" disabled={verifyingQuestionId !== null} onClick={() => addPublishedVerification(question.id)} className="rounded-lg border border-blue-300 bg-white px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50 disabled:opacity-50">
                                                        {verifyingQuestionId === question.id ? 'Menyimpan...' : 'Tambah Verifikasi'}
                                                    </button>
                                                )}
                                            </div>
                                        ) : question.verification.currentUserVerified ? (
                                            <span className="shrink-0 rounded-lg bg-blue-50 px-4 py-2 text-center text-sm font-semibold text-blue-700">Anda sudah verifikasi</span>
                                        ) : (
                                            <div className="flex shrink-0 flex-wrap gap-2">
                                                <button type="button" onClick={() => setEditingQuestionId(editingQuestionId === question.id ? null : question.id)} className="rounded-lg border border-indigo-200 bg-white px-4 py-2 text-center text-sm font-semibold text-indigo-700 hover:bg-indigo-50">
                                                    {editingQuestionId === question.id ? 'Tutup Editor' : 'Edit Langsung'}
                                                </button>
                                                <button type="button" onClick={() => deleteQuestion(question.id)} className="rounded-lg border border-rose-200 bg-white px-4 py-2 text-center text-sm font-semibold text-rose-700 hover:bg-rose-50">
                                                    Hapus
                                                </button>
                                                {!storyMode && <button type="button" disabled={checkingQuestionId !== null || verifyingQuestionId !== null} onClick={() => checkDuplicates(question.id)} className="rounded-lg bg-emerald-600 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50">
                                                    {checkingQuestionId === question.id ? 'Memeriksa...' : 'Cek Duplikasi'}
                                                </button>}
                                            </div>
                                        )}
                                    </div>

                                    {editingQuestionId === question.id && (
                                        <InlineQuestionEditor
                                            generationId={generation.id}
                                            question={question}
                                            showStimulus={!storyMode}
                                            onCancel={() => setEditingQuestionId(null)}
                                        />
                                    )}

                                    {!storyMode && duplicateResult?.questionId === question.id && (
                                        <div className={`mt-5 rounded-xl border p-4 ${duplicateResult.blocking ? 'border-rose-200 bg-rose-50' : 'border-emerald-200 bg-emerald-50'}`}>
                                            <h4 className={`font-semibold ${duplicateResult.blocking ? 'text-rose-900' : 'text-emerald-900'}`}>
                                                {duplicateResult.blocking ? 'Ditemukan duplikasi kuat' : 'Pemeriksaan duplikasi selesai'}
                                            </h4>
                                            {duplicateResult.candidates.length === 0 ? (
                                                <p className="mt-1 text-sm text-emerald-700">Tidak ditemukan soal lain yang mirip. Soal dapat dilanjutkan ke verifikasi.</p>
                                            ) : (
                                                <div className="mt-3 space-y-2">
                                                    {duplicateResult.candidates.map((candidate) => (
                                                        <Link key={candidate.id} href={route('questions.show', candidate.id)} target="_blank" className={`block rounded-lg border bg-white p-3 text-sm ${candidate.blocking ? 'border-rose-200' : 'border-amber-200'}`}>
                                                            <span className="font-semibold text-slate-900">Kemiripan {candidate.similarity}% · {candidate.competency}</span>
                                                            <span className="mt-1 line-clamp-2 block text-slate-600">{candidate.prompt}</span>
                                                        </Link>
                                                    ))}
                                                </div>
                                            )}
                                            <div className="mt-4 flex flex-wrap justify-end gap-2">
                                                <button type="button" onClick={() => setDuplicateResult(null)} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Tutup</button>
                                                {!duplicateResult.blocking && <button type="button" disabled={verifyingQuestionId !== null} onClick={() => verifyQuestion(question.id)} className="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-600 disabled:opacity-50">
                                                    {verifyingQuestionId === question.id ? 'Memverifikasi...' : 'Lanjut Verifikasi'}
                                                </button>}
                                            </div>
                                        </div>
                                    )}

                                    {question.illustration_url && (
                                        <a
                                            href={question.illustration_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="mt-5 block overflow-hidden rounded-xl border border-slate-200 bg-slate-50 p-3"
                                            title={`Buka ilustrasi soal ${index + 1} ukuran penuh`}
                                        >
                                            <img
                                                src={question.illustration_url}
                                                alt={question.metadata?.illustration?.alt || `Ilustrasi soal ${index + 1}`}
                                                className="mx-auto aspect-video max-h-[520px] w-full object-contain"
                                            />
                                            <span className="mt-2 block text-center text-xs font-medium text-slate-500">Ilustrasi soal {index + 1} · klik untuk ukuran penuh</span>
                                        </a>
                                    )}

                                    {question.type === 'category_matrix' && question.metadata?.matrix_columns && question.metadata?.matrix_rows ? (
                                        <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200">
                                            <table className="w-full min-w-[520px] text-sm"><thead className="bg-slate-100"><tr><th className="p-3 text-left">Pernyataan</th>{question.metadata.matrix_columns.map((column) => <th key={column.id} className="p-3 text-center">{column.label}</th>)}</tr></thead><tbody>{question.metadata.matrix_rows.map((row) => <tr key={row.id} className="border-t border-slate-200"><td className="p-3">{row.statement}</td>{question.metadata?.matrix_columns?.map((column) => <td key={column.id} className="p-3 text-center">{row.correct_column_id === column.id ? '✓' : '○'}</td>)}</tr>)}</tbody></table>
                                        </div>
                                    ) : question.type === 'matching' && question.metadata?.matching_pairs ? (
                                        <div className="mt-4 overflow-hidden rounded-xl border border-slate-200">
                                            {question.metadata.matching_pairs.map((pair) => (
                                                <div key={pair.left_id} className="grid grid-cols-[1fr_32px_1fr] items-center border-b border-slate-100 p-3 text-sm last:border-b-0">
                                                    <span>{pair.left}</span><span className="text-center text-emerald-600">→</span><span className="font-medium text-emerald-800">{pair.right}</span>
                                                </div>
                                            ))}
                                            {(question.metadata.matching_distractors?.length || 0) > 0 && <p className="border-t border-amber-100 bg-amber-50 p-3 text-sm text-amber-800">Distraktor: {question.metadata.matching_distractors?.map((item) => item.content).join(', ')}</p>}
                                        </div>
                                    ) : question.options.length > 0 ? (
                                        <div className="mt-4 grid gap-2 sm:grid-cols-2">
                                            {question.options.map((option) => (
                                                <div key={option.id} className={`rounded-lg border p-3 text-sm ${option.is_correct ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 text-slate-700'}`}>
                                                    {question.type === 'multiple_choice' ? <span className={`mr-2 inline-flex h-5 w-5 items-center justify-center rounded border text-xs ${option.is_correct ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 text-transparent'}`}>✓</span> : <span className="mr-2 font-semibold">{option.label}.</span>}{option.content}
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">Jawaban diterima: {question.metadata?.accepted_answers?.join(', ')}</p>
                                    )}

                                    {question.explanation && <p className="mt-4 border-t border-slate-100 pt-4 text-sm leading-6 text-slate-600"><span className="font-semibold text-slate-800">Pembahasan:</span> {question.explanation}</p>}
                                    <dl className="mt-4 grid gap-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-2">
                                        <div><dt className="text-slate-500">Pembuat</dt><dd className="mt-1 font-medium text-slate-800">{question.author.name}</dd></div>
                                        <div><dt className="text-slate-500">Progres verifikasi</dt><dd className="mt-1 font-medium text-slate-800">{question.verification.count}/{question.verification.required} guru{question.verification.verifiers.length > 0 ? ` · ${question.verification.verifiers.map((item) => item.name).join(', ')}` : ''}</dd></div>
                                    </dl>
                                </article>
                            ))}
                        </section>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
