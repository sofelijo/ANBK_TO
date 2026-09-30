import InlineQuestionEditor, { InlineEditableQuestion } from '@/Components/InlineQuestionEditor';
import FormattedText from '@/Components/FormattedText';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, useEffect, useMemo, useState } from 'react';

type Generation = {
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    request_payload: { source?: 'ai' | 'manual'; subject_id?: number; theme: string; format?: 'direct' | 'story'; question_style?: 'direct' | 'reasoning'; use_illustration?: boolean; has_stimulus_image?: boolean; illustration_mode?: 'lite' | 'pro'; paragraph_count?: number; question_count?: number };
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
    question_blueprint?: { id: number; code: string; name: string };
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

type AvailableCompetency = {
    id: number;
    code: string;
    name: string;
    grade_level: number;
};

type AvailableBlueprint = {
    id: number;
    code: string;
    name: string;
    description?: string;
};

type TextScale = 90 | 100 | 115;

function QuestionInteraction({
    question,
    previewOptionAnswers,
    previewMatrixAnswers,
    shortAnswers,
    setShortAnswers,
    choosePreviewOption,
    choosePreviewMatrix,
}: {
    question: Question;
    previewOptionAnswers: Record<number, number[]>;
    previewMatrixAnswers: Record<number, Record<string, string>>;
    shortAnswers: Record<number, string>;
    setShortAnswers: React.Dispatch<React.SetStateAction<Record<number, string>>>;
    choosePreviewOption: (question: Question, optionId: number) => void;
    choosePreviewMatrix: (questionId: number, rowId: string, columnId: string) => void;
}) {
    if (question.type === 'category_matrix' && question.metadata?.matrix_columns && question.metadata.matrix_rows) {
        return (
            <div className="mt-5 overflow-x-auto rounded-xl border border-slate-200">
                <table className="w-full min-w-[430px] table-fixed text-sm">
                    <thead className="border-b-2 border-slate-700 bg-slate-100">
                        <tr>
                            <th className="w-12 p-2.5 text-center">#</th>
                            <th className="p-2.5 text-left">Pernyataan</th>
                            {question.metadata.matrix_columns.map((column) => (
                                <th key={column.id} className="w-20 break-words p-2 text-center">{column.label}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {question.metadata.matrix_rows.map((row, rowIndex) => (
                            <tr key={row.id} className="border-t border-slate-200">
                                <td className="p-3 text-center font-semibold text-slate-600">{String.fromCharCode(65 + rowIndex)}.</td>
                                <td className="p-3 leading-6">{row.statement}</td>
                                {question.metadata?.matrix_columns?.map((column) => {
                                    const selected = previewMatrixAnswers[question.id]?.[row.id] === column.id;
                                    return (
                                        <td key={column.id} className="p-3 text-center">
                                            <button
                                                type="button"
                                                onClick={() => choosePreviewMatrix(question.id, row.id, column.id)}
                                                aria-label={`${row.statement}: ${column.label}`}
                                                className={`inline-flex h-8 w-8 items-center justify-center rounded-full border-2 text-sm font-bold transition ${
                                                    selected ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 text-transparent hover:border-slate-400'
                                                }`}
                                            >
                                                ✓
                                            </button>
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        );
    }

    if (question.options.length > 0) {
        return (
            <div className="mt-5 space-y-3">
                {question.options.map((option) => {
                    const selected = (previewOptionAnswers[question.id] || []).includes(option.id);
                    return (
                        <button
                            key={option.id}
                            type="button"
                            onClick={() => choosePreviewOption(question, option.id)}
                            className={`flex w-full items-center gap-3 rounded-xl border p-4 text-left transition ${
                                selected
                                    ? 'border-emerald-500 bg-emerald-50 ring-1 ring-emerald-500'
                                    : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                            }`}
                        >
                            {question.type === 'multiple_choice' ? (
                                <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded border-2 text-sm font-bold ${selected ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 text-transparent'}`}>
                                    ✓
                                </span>
                            ) : (
                                <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full border text-sm font-bold ${selected ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 text-slate-600'}`}>
                                    {option.label}
                                </span>
                            )}
                            <FormattedText text={option.content} className="text-sm leading-6 text-slate-800" />
                        </button>
                    );
                })}
            </div>
        );
    }

    if (question.type === 'short_answer') {
        return (
            <textarea
                rows={3}
                value={shortAnswers[question.id] || ''}
                onChange={(e) => {
                    const val = e.target.value;
                    setShortAnswers((prev) => ({ ...prev, [question.id]: val }));
                }}
                placeholder="Tulis jawabanmu di sini..."
                className="mt-5 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm leading-6"
            />
        );
    }

    return (
        <p className="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">
            Pratinjau interaktif untuk tipe soal ini belum tersedia.
        </p>
    );
}

type QuestionTypeOption = {
    value: string;
    label: string;
    description?: string;
    active?: boolean;
};

const QUESTION_TYPE_DISPLAY_LABELS: Record<string, string> = {
    single_choice: 'Pilihan Ganda Biasa',
    multiple_choice: 'Pilihan Ganda Kompleks',
    category_matrix: 'Tabel Pernyataan Benar/Salah',
    matching: 'Menjodohkan',
    short_answer: 'Isian Singkat',
};

export default function StoryShow({
    generation,
    questions,
    illustration,
    canVerify,
    verificationLocked = false,
    canSubmitForReview = false,
    canRevertToDraft = false,
    isAuthor = false,
    canDeleteBundle = false,
    availableCompetencies = [],
    availableBlueprints = [],
    questionTypes = [],
}: {
    generation: Generation;
    questions: Question[];
    illustration?: Illustration;
    canVerify: boolean;
    verificationLocked?: boolean;
    canSubmitForReview?: boolean;
    canRevertToDraft?: boolean;
    isAuthor?: boolean;
    canDeleteBundle?: boolean;
    availableCompetencies?: AvailableCompetency[];
    availableBlueprints?: AvailableBlueprint[];
    questionTypes?: QuestionTypeOption[];
}) {
    const activeQuestionTypes = useMemo(() => {
        if (questionTypes && questionTypes.length > 0) {
            return questionTypes.filter((t) => t.active !== false);
        }
        return [
            { value: 'single_choice', label: 'Pilihan Ganda Biasa', active: true },
            { value: 'multiple_choice', label: 'Pilihan Ganda Kompleks', active: true },
            { value: 'category_matrix', label: 'Tabel Pernyataan Benar/Salah', active: true },
            { value: 'matching', label: 'Menjodohkan', active: true },
            { value: 'short_answer', label: 'Isian Singkat', active: true },
        ];
    }, [questionTypes]);

    const activeManualTypes = useMemo(() => {
        return activeQuestionTypes.filter((t) => ['single_choice', 'multiple_choice'].includes(t.value));
    }, [activeQuestionTypes]);

    const waiting = generation.status === 'pending' || generation.status === 'processing';
    const storyMode = generation.request_payload.format !== 'direct';
    const manualBundle = generation.request_payload.source === 'manual';
    const hasRecoverableVisualSpec = Boolean(generation.result_payload?.visual_spec);
    const illustrationRequested = (!manualBundle && storyMode) || generation.request_payload.use_illustration === true || hasRecoverableVisualSpec;
    const routePrefix = storyMode ? 'story-questions' : 'ai-questions';
    const illustrationWaiting = illustration?.status === 'pending' || illustration?.status === 'processing';
    const [publishing, setPublishing] = useState(false);
    const [submittingForReview, setSubmittingForReview] = useState(false);
    const [revertingToDraft, setRevertingToDraft] = useState(false);
    const [togglingQuestionId, setTogglingQuestionId] = useState<number | null>(null);
    const [requestingIllustration, setRequestingIllustration] = useState(false);
    const [editingQuestionId, setEditingQuestionId] = useState<number | null>(null);
    const [verifyingQuestionId, setVerifyingQuestionId] = useState<number | null>(null);
    const [checkingQuestionId, setCheckingQuestionId] = useState<number | null>(null);
    const [duplicateResult, setDuplicateResult] = useState<{ questionId: number; blocking: boolean; candidates: DuplicateCandidate[] } | null>(null);
    const [questionActionMenuId, setQuestionActionMenuId] = useState<number | null>(null);
    const [studentPreviewOpen, setStudentPreviewOpen] = useState(false);
    const [isFullPreview, setIsFullPreview] = useState(false);
    const [studentPreviewIndex, setStudentPreviewIndex] = useState(0);
    const [previewOptionAnswers, setPreviewOptionAnswers] = useState<Record<number, number[]>>({});
    const [previewMatrixAnswers, setPreviewMatrixAnswers] = useState<Record<number, Record<string, string>>>({});
    const [shortAnswers, setShortAnswers] = useState<Record<number, string>>({});

    // Aksesibilitas POV Siswa (Ukuran Teks, Kontras, Ragu-ragu)
    const [textScale, setTextScale] = useState<TextScale>(100);
    const [highContrast, setHighContrast] = useState(false);
    const [doubtfulQuestions, setDoubtfulQuestions] = useState<Record<number, boolean>>({});

    // Mode Edit Stimulus
    const [editingStimulus, setEditingStimulus] = useState(false);
    const [stimulusTitle, setStimulusTitle] = useState(generation.result_payload?.title || '');
    const [stimulusStory, setStimulusStory] = useState(generation.result_payload?.story || '');
    const [savingStimulus, setSavingStimulus] = useState(false);

    // Hapus Bundle, Tambah Soal, Generate Ulang Soal
    const [deletingBundle, setDeletingBundle] = useState(false);
    const [showAddQuestionModal, setShowAddQuestionModal] = useState<'ai' | 'manual' | null>(null);
    const [generatingAiQuestion, setGeneratingAiQuestion] = useState(false);
    const [addingManualQuestion, setAddingManualQuestion] = useState(false);
    const [showRegenerateModal, setShowRegenerateModal] = useState<number | null>(null);
    const [regeneratingQuestionId, setRegeneratingQuestionId] = useState<number | null>(null);
    const [regenerateInstruction, setRegenerateInstruction] = useState('');

    // Form Tambah Soal via AI
    const [aiForm, setAiForm] = useState({
        competency_id: availableCompetencies[0]?.id ? String(availableCompetencies[0].id) : '',
        question_blueprint_id: '',
        answer_format: activeQuestionTypes[0]?.value || 'single_choice',
        difficulty: 2,
        cognitive_level: 'Pemahaman Inferensial (Level 2)',
        instruction: '',
    });

    // Form Tambah Soal Manual
    const [manualForm, setManualForm] = useState({
        competency_id: availableCompetencies[0]?.id ? String(availableCompetencies[0].id) : '',
        question_blueprint_id: '',
        type: (activeManualTypes[0]?.value || 'single_choice') as 'single_choice' | 'multiple_choice',
        prompt: '',
        explanation: '',
        difficulty: 2,
        cognitive_level: 'Pemahaman Inferensial (Level 2)',
        options: [
            { label: 'A', content: '', is_correct: true },
            { label: 'B', content: '', is_correct: false },
            { label: 'C', content: '', is_correct: false },
            { label: 'D', content: '', is_correct: false },
        ],
    });

    useEffect(() => {
        if (activeQuestionTypes.length > 0 && !activeQuestionTypes.some((t) => t.value === aiForm.answer_format)) {
            setAiForm((prev) => ({ ...prev, answer_format: activeQuestionTypes[0].value }));
        }
    }, [activeQuestionTypes]);

    useEffect(() => {
        if (activeManualTypes.length > 0 && !activeManualTypes.some((t) => t.value === manualForm.type)) {
            setManualForm((prev) => ({ ...prev, type: activeManualTypes[0].value as 'single_choice' | 'multiple_choice' }));
        }
    }, [activeManualTypes]);

    useEffect(() => {
        if (availableCompetencies.length > 0 && !aiForm.competency_id) {
            setAiForm((prev) => ({ ...prev, competency_id: String(availableCompetencies[0].id) }));
            setManualForm((prev) => ({ ...prev, competency_id: String(availableCompetencies[0].id) }));
        }
    }, [availableCompetencies]);

    useEffect(() => {
        setStimulusTitle(generation.result_payload?.title || '');
        setStimulusStory(generation.result_payload?.story || '');
    }, [generation.result_payload?.title, generation.result_payload?.story]);

    // Efek high contrast
    useEffect(() => {
        document.documentElement.classList.toggle('toa-high-contrast', highContrast);
        return () => document.documentElement.classList.remove('toa-high-contrast');
    }, [highContrast]);

    // Buka preview layar penuh jika URL mengandung ?preview=full atau ?preview=1
    useEffect(() => {
        if (typeof window !== 'undefined') {
            const previewParam = new URLSearchParams(window.location.search).get('preview');
            if ((previewParam === '1' || previewParam === 'full') && questions.length > 0) {
                setStudentPreviewIndex(0);
                setIsFullPreview(true);
            }
        }
    }, [questions.length]);

    // Keyboard shortcut navigasi soal Alt + ArrowLeft / ArrowRight
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.altKey && e.key === 'ArrowLeft') {
                e.preventDefault();
                setStudentPreviewIndex((idx) => Math.max(0, idx - 1));
            } else if (e.altKey && e.key === 'ArrowRight') {
                e.preventDefault();
                setStudentPreviewIndex((idx) => Math.min(questions.length - 1, idx + 1));
            }
        };
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [questions.length]);

    const publishedCount = questions.filter((question) => question.status === 'published').length;
    const unpublishedCount = questions.length - publishedCount;
    const allPublished = questions.length > 0 && unpublishedCount === 0;
    const allVerifiedByCurrentUser = questions.length > 0 && questions.every((question) => question.verification.currentUserVerified);
    const previewQuestion = questions[studentPreviewIndex];

    const isQuestionAnswered = (questionId: number) => {
        return Boolean(
            (previewOptionAnswers[questionId]?.length || 0) > 0 ||
            Object.keys(previewMatrixAnswers[questionId] || {}).length > 0 ||
            Boolean(shortAnswers[questionId]?.trim())
        );
    };

    const resetQuestionAnswer = (questionId: number) => {
        setPreviewOptionAnswers((prev) => {
            const next = { ...prev };
            delete next[questionId];
            return next;
        });
        setPreviewMatrixAnswers((prev) => {
            const next = { ...prev };
            delete next[questionId];
            return next;
        });
        setShortAnswers((prev) => {
            const next = { ...prev };
            delete next[questionId];
            return next;
        });
        setDoubtfulQuestions((prev) => {
            const next = { ...prev };
            delete next[questionId];
            return next;
        });
    };

    const saveStimulus = (e: FormEvent) => {
        e.preventDefault();
        if (savingStimulus) return;

        router.put(
            route(`${routePrefix}.update-stimulus`, generation.id),
            { title: stimulusTitle, story: stimulusStory },
            {
                preserveScroll: true,
                onStart: () => setSavingStimulus(true),
                onFinish: () => setSavingStimulus(false),
                onSuccess: () => setEditingStimulus(false),
            }
        );
    };

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

    const submitForReview = () => {
        if (submittingForReview) return;
        if (!window.confirm('Ajukan semua soal dalam bundle ini ke status menunggu verifikasi agar dapat diverifikasi rekan guru?')) return;

        router.post(route(`${routePrefix}.submit-review`, generation.id), {}, {
            preserveScroll: true,
            onStart: () => setSubmittingForReview(true),
            onFinish: () => setSubmittingForReview(false),
        });
    };

    const revertToDraft = () => {
        if (revertingToDraft) return;
        if (!window.confirm('Kembalikan bundle ini ke status draft pribadi? Verifikasi yang belum terbit akan direset dan guru lain tidak dapat melihat bundle ini.')) return;

        router.post(route(`${routePrefix}.revert-draft`, generation.id), {}, {
            preserveScroll: true,
            onStart: () => setRevertingToDraft(true),
            onFinish: () => setRevertingToDraft(false),
        });
    };

    const deleteBundle = () => {
        if (deletingBundle) return;
        if (!window.confirm('Hapus seluruh bundle ini beserta seluruh butir soal di dalamnya? Tindakan ini permanen dan tidak dapat dibatalkan.')) return;

        router.delete(route(`${routePrefix}.destroy`, generation.id), {
            onStart: () => setDeletingBundle(true),
            onFinish: () => setDeletingBundle(false),
        });
    };

    const handleRegenerateQuestion = (questionId: number) => {
        if (regeneratingQuestionId !== null) return;

        router.post(
            route(`${routePrefix}.questions.regenerate`, [generation.id, questionId]),
            { instruction: regenerateInstruction },
            {
                preserveScroll: true,
                onStart: () => setRegeneratingQuestionId(questionId),
                onFinish: () => {
                    setRegeneratingQuestionId(null);
                    setShowRegenerateModal(null);
                    setRegenerateInstruction('');
                },
            }
        );
    };

    const submitAiQuestion = (e: FormEvent) => {
        e.preventDefault();
        if (generatingAiQuestion) return;

        router.post(
            route(`${routePrefix}.questions.generate-ai`, generation.id),
            aiForm,
            {
                preserveScroll: true,
                onStart: () => setGeneratingAiQuestion(true),
                onFinish: () => {
                    setGeneratingAiQuestion(false);
                    setShowAddQuestionModal(null);
                },
            }
        );
    };

    const submitManualQuestion = (e: FormEvent) => {
        e.preventDefault();
        if (addingManualQuestion) return;

        if (manualForm.prompt.trim() === '') {
            window.alert('Pertanyaan tidak boleh kosong.');
            return;
        }

        const validOptions = manualForm.options.filter((o) => o.content.trim() !== '');
        if (validOptions.length < 2) {
            window.alert('Harap isi minimal 2 pilihan jawaban.');
            return;
        }

        if (!validOptions.some((o) => o.is_correct)) {
            window.alert('Harap pilih minimal satu kunci jawaban yang benar.');
            return;
        }

        router.post(
            route(`${routePrefix}.questions.store`, generation.id),
            manualForm,
            {
                preserveScroll: true,
                onStart: () => setAddingManualQuestion(true),
                onFinish: () => {
                    setAddingManualQuestion(false);
                    setShowAddQuestionModal(null);
                    setManualForm((prev) => ({
                        ...prev,
                        prompt: '',
                        explanation: '',
                        options: [
                            { label: 'A', content: '', is_correct: true },
                            { label: 'B', content: '', is_correct: false },
                            { label: 'C', content: '', is_correct: false },
                            { label: 'D', content: '', is_correct: false },
                        ],
                    }));
                },
            }
        );
    };

    const toggleQuestionStatus = (questionId: number, targetStatus: 'draft' | 'review') => {
        if (togglingQuestionId !== null) return;
        const confirmMsg = targetStatus === 'review'
            ? 'Ajukan soal ini ke status menunggu verifikasi agar dapat diverifikasi guru lain?'
            : 'Kembalikan soal ini ke status draft pribadi?';
        if (!window.confirm(confirmMsg)) return;

        router.post(route('questions.update-status', questionId), { status: targetStatus }, {
            preserveScroll: true,
            onStart: () => setTogglingQuestionId(questionId),
            onFinish: () => setTogglingQuestionId(null),
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

    // Verifikasi soal cerita satu per satu (tanpa cek duplikasi, sudah dicek saat verif bundle)
    const verifyQuestionDirectly = (questionId: number) => {
        if (verifyingQuestionId !== null) return;
        if (!window.confirm('Catat verifikasi Anda untuk soal ini saja? Soal terbit setelah dicapai jumlah minimal verifikasi guru.')) return;

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

    if (isFullPreview && previewQuestion) {
        const answeredCount = questions.filter((q) => isQuestionAnswered(q.id)).length;
        const doubtfulCount = Object.values(doubtfulQuestions).filter(Boolean).length;

        return (
            <div className="flex min-h-[100dvh] flex-col bg-slate-100 lg:h-[100dvh] lg:min-h-0 lg:overflow-hidden font-sans text-slate-900">
                <Head title={`Pratinjau Siswa: ${generation.result_payload?.title || generation.request_payload.theme || 'Paket Soal'}`} />

                {/* Top Header Bar */}
                <header className="z-10 shrink-0 border-b border-slate-200 bg-white shadow-sm">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-2.5 sm:px-6">
                        <div className="min-w-0">
                            <div className="flex items-center gap-2">
                                <span className="inline-flex items-center rounded-md bg-emerald-600 px-2 py-0.5 text-xs font-bold text-white uppercase tracking-wider">
                                    Simulasi Siswa
                                </span>
                                <span className="text-xs font-medium text-slate-500">
                                    {manualBundle ? 'Paket Cerita Manual' : storyMode ? 'Paket Cerita AI' : 'Paket Soal AI'}
                                </span>
                            </div>
                            <h1 className="mt-0.5 truncate text-base font-bold text-slate-900 max-w-xs sm:max-w-md">
                                {generation.result_payload?.title || generation.request_payload.theme}
                            </h1>
                        </div>

                        {/* Toolbar Aksesibilitas POV Siswa */}
                        <div className="flex flex-wrap items-center gap-2 sm:gap-3">
                            {/* Ukuran Font */}
                            <div className="flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 px-2 py-1" role="toolbar" aria-label="Ukuran font">
                                <span className="text-[11px] font-semibold text-slate-500 mr-1">Teks:</span>
                                {([90, 100, 115] as const).map((scale, i) => (
                                    <button
                                        key={scale}
                                        type="button"
                                        onClick={() => setTextScale(scale)}
                                        className={`h-7 px-2 rounded text-xs font-bold transition ${
                                            textScale === scale ? 'bg-indigo-600 text-white shadow-sm' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-100'
                                        }`}
                                        title={scale === 90 ? 'Font Kecil (90%)' : scale === 100 ? 'Font Normal (100%)' : 'Font Besar (115%)'}
                                    >
                                        {i === 0 ? 'A−' : i === 1 ? 'A' : 'A+'}
                                    </button>
                                ))}
                            </div>

                            {/* Mode Kontras */}
                            <button
                                type="button"
                                onClick={() => setHighContrast((v) => !v)}
                                className={`h-9 px-2.5 rounded-lg border text-xs font-bold transition flex items-center gap-1.5 ${
                                    highContrast ? 'border-amber-500 bg-amber-50 text-amber-900 ring-1 ring-amber-400' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                }`}
                                title="Mode Kontras Tinggi"
                            >
                                <span>◐</span>
                                <span className="hidden sm:inline">Kontras</span>
                            </button>

                            {/* Layar Penuh */}
                            <button
                                type="button"
                                onClick={() => {
                                    if (!document.fullscreenElement) {
                                        document.documentElement.requestFullscreen().catch(() => {});
                                    } else {
                                        document.exitFullscreen().catch(() => {});
                                    }
                                }}
                                className="hidden md:inline-flex items-center gap-1.5 h-9 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition"
                                title="Layar Penuh"
                            >
                                <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4 fill-none stroke-current stroke-2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                                <span>Layar Penuh</span>
                            </button>

                            {/* Shortcut info */}
                            <span className="hidden 2xl:inline text-[11px] text-slate-400 font-medium">Alt + ←/→ pindah soal</span>

                            {/* Tombol Nomor Soal */}
                            <div className="flex max-w-full items-center gap-1.5 overflow-x-auto p-1">
                                <span className="text-xs font-semibold text-slate-500 mr-0.5 hidden lg:inline">Soal:</span>
                                {questions.map((q, idx) => {
                                    const isAnswered = isQuestionAnswered(q.id);
                                    const isDoubtful = Boolean(doubtfulQuestions[q.id]);
                                    const isActive = idx === studentPreviewIndex;

                                    let btnStyle = 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50';
                                    if (isActive) {
                                        btnStyle = isDoubtful
                                            ? 'border-2 border-amber-600 bg-amber-400 text-amber-950 font-black ring-2 ring-amber-300'
                                            : 'border-2 border-indigo-600 bg-indigo-600 text-white font-black ring-2 ring-indigo-300 shadow';
                                    } else if (isDoubtful) {
                                        btnStyle = 'border border-amber-400 bg-amber-300 text-amber-950 font-bold';
                                    } else if (isAnswered) {
                                        btnStyle = 'border border-emerald-500 bg-emerald-600 text-white font-bold';
                                    }

                                    return (
                                        <button
                                            key={q.id}
                                            type="button"
                                            onClick={() => setStudentPreviewIndex(idx)}
                                            className={`h-11 w-11 shrink-0 rounded-lg text-xs font-bold transition flex items-center justify-center ${btnStyle}`}
                                            title={`Soal ${idx + 1}${isDoubtful ? ' (Ragu-ragu)' : isAnswered ? ' (Sudah dijawab)' : ' (Belum dijawab)'}`}
                                        >
                                            {idx + 1}
                                        </button>
                                    );
                                })}
                            </div>

                            {/* Kembali ke Detail */}
                            <button
                                type="button"
                                onClick={() => {
                                    setIsFullPreview(false);
                                    if (typeof window !== 'undefined') {
                                        window.history.replaceState({}, '', window.location.pathname);
                                    }
                                }}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                            >
                                ← Kembali ke Detail
                            </button>
                        </div>
                    </div>
                </header>

                {/* Main Content: Split Screen */}
                <main className="flex-1 min-h-0 p-3 lg:overflow-hidden sm:p-5">
                    <div className="mx-auto max-w-7xl lg:h-full">
                        <div className={`grid lg:h-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm ${storyMode ? 'lg:grid-cols-2' : ''}`}>
                            {storyMode && (
                                <aside className="lg:h-full lg:overflow-y-auto border-b border-slate-200 bg-slate-50 p-6 lg:border-b-0 lg:border-r lg:p-8">
                                    <div style={{ zoom: textScale / 100 }}>
                                        <div className="inline-block rounded-md bg-indigo-100 px-2.5 py-1 text-xs font-bold uppercase tracking-wider text-indigo-700">
                                            Stimulus Teks
                                        </div>
                                        <h2 className="mt-3 text-xl font-bold text-slate-900">{generation.result_payload?.title}</h2>
                                        <div className="mt-4 whitespace-pre-wrap text-sm leading-8 text-slate-700">
                                            {generation.result_payload?.story}
                                        </div>
                                        {manualBundle && previewQuestion.illustration_url && (
                                            <img
                                                src={previewQuestion.illustration_url}
                                                alt={previewQuestion.metadata?.illustration?.alt || 'Gambar stimulus'}
                                                className="mt-6 max-h-96 w-full rounded-xl border border-slate-200 bg-white object-contain"
                                            />
                                        )}
                                    </div>
                                </aside>
                            )}

                            <section className="lg:h-full lg:overflow-y-auto p-6 lg:p-8 flex flex-col justify-between">
                                <div style={{ zoom: textScale / 100 }}>
                                    {!storyMode && previewQuestion.stimulus && (
                                        <div className="mb-5 whitespace-pre-wrap rounded-xl bg-slate-50 p-5 text-sm leading-7 text-slate-700">
                                            <FormattedText text={previewQuestion.stimulus} />
                                        </div>
                                    )}
                                    {previewQuestion.illustration_url && (!storyMode || !manualBundle) && (
                                        <img
                                            src={previewQuestion.illustration_url}
                                            alt={previewQuestion.metadata?.illustration?.alt || 'Ilustrasi soal'}
                                            className="mb-5 max-h-80 w-full rounded-xl border border-slate-200 object-contain"
                                        />
                                    )}
                                    <div className="flex items-center gap-2">
                                        <span className="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-800">
                                            Soal {studentPreviewIndex + 1} dari {questions.length}
                                        </span>
                                        <span className="text-xs text-slate-500">
                                            {previewQuestion.type === 'single_choice' ? 'Pilihan Ganda (1 jawaban)' :
                                             previewQuestion.type === 'multiple_choice' ? 'Pilihan Ganda Kompleks (bisa >1 jawaban)' :
                                             previewQuestion.type === 'category_matrix' ? 'Matriks Kategori' :
                                             previewQuestion.type === 'short_answer' ? 'Isian Singkat' : 'Soal'}
                                        </span>
                                    </div>
                                    <h3 className="mt-3 text-lg font-semibold leading-8 text-slate-900">
                                        <FormattedText text={previewQuestion.prompt} />
                                    </h3>

                                    <QuestionInteraction
                                        question={previewQuestion}
                                        previewOptionAnswers={previewOptionAnswers}
                                        previewMatrixAnswers={previewMatrixAnswers}
                                        shortAnswers={shortAnswers}
                                        setShortAnswers={setShortAnswers}
                                        choosePreviewOption={choosePreviewOption}
                                        choosePreviewMatrix={choosePreviewMatrix}
                                    />
                                </div>

                                {/* Bar Ragu-ragu dan Hapus Jawaban */}
                                <div className="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                                    <label className="inline-flex items-center gap-2 cursor-pointer select-none text-xs font-semibold text-amber-900 bg-amber-50 hover:bg-amber-100 px-3 py-1.5 rounded-lg border border-amber-300 transition">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(doubtfulQuestions[previewQuestion.id])}
                                            onChange={(e) => setDoubtfulQuestions((prev) => ({ ...prev, [previewQuestion.id]: e.target.checked }))}
                                            className="rounded border-amber-400 text-amber-600 focus:ring-amber-500 h-4 w-4"
                                        />
                                        <span>Ragu-ragu</span>
                                    </label>
                                    {isQuestionAnswered(previewQuestion.id) && (
                                        <button
                                            type="button"
                                            onClick={() => resetQuestionAnswer(previewQuestion.id)}
                                            className="text-xs font-semibold text-rose-600 hover:text-rose-700 hover:underline"
                                        >
                                            Hapus Pilihan Jawaban
                                        </button>
                                    )}
                                </div>
                            </section>
                        </div>
                    </div>
                </main>

                {/* Bottom Navigation */}
                <footer className="shrink-0 border-t border-slate-200 bg-white px-4 py-3 shadow-inner">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3">
                        <button
                            type="button"
                            disabled={studentPreviewIndex === 0}
                            onClick={() => setStudentPreviewIndex((i) => i - 1)}
                            className="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-40"
                        >
                            ← Sebelumnya
                        </button>
                        <span className="hidden sm:inline text-xs font-medium text-slate-600">
                            {answeredCount} dari {questions.length} terjawab{doubtfulCount > 0 ? ` · ${doubtfulCount} ragu-ragu` : ''}
                        </span>
                        {studentPreviewIndex < questions.length - 1 ? (
                            <button
                                type="button"
                                onClick={() => setStudentPreviewIndex((i) => i + 1)}
                                className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Berikutnya →
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={() => {
                                    setIsFullPreview(false);
                                    if (typeof window !== 'undefined') {
                                        window.history.replaceState({}, '', window.location.pathname);
                                    }
                                }}
                                className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500"
                            >
                                ✓ Selesai Pratinjau
                            </button>
                        )}
                    </div>
                </footer>
            </div>
        );
    }

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <p className="text-sm font-medium text-indigo-600">{manualBundle ? 'Paket Soal Cerita Manual' : storyMode ? 'Paket Soal Cerita AI' : 'Paket Soal AI'}</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">{generation.result_payload?.title || generation.request_payload.theme}</h1>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {generation.status === 'completed' && questions.length > 0 && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => { setStudentPreviewIndex(0); setStudentPreviewOpen(true); }}
                                    className="inline-flex items-center gap-2 rounded-lg border border-sky-300 bg-white px-3.5 py-2 text-sm font-semibold text-sky-700 hover:bg-sky-50"
                                    title="Lihat pratinjau popup siswa"
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4 fill-none stroke-current stroke-2"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6Z" /><circle cx="12" cy="12" r="2.75" /></svg>
                                    Pratinjau Siswa
                                </button>
                                <a
                                    href={`${typeof window !== 'undefined' ? window.location.pathname : ''}?preview=full`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500"
                                    title="Buka pratinjau layar penuh di tab baru"
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true" className="h-4 w-4 fill-none stroke-current stroke-2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" /><polyline points="15 3 21 3 21 9" /><line x1="10" y1="14" x2="21" y2="3" /></svg>
                                    Pratinjau Full (Tab Baru)
                                </a>
                            </>
                        )}
                        <Link href={route(manualBundle ? 'manual-story-bundles.create' : `${routePrefix}.create`, { subject_id: generation.request_payload.subject_id })} className="rounded-lg border border-indigo-300 bg-white px-4 py-2 text-sm font-semibold text-indigo-700">Buat Paket Baru</Link>
                        <Link href={route('questions.index')} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Bank Soal</Link>
                    </div>
                </div>
            }
        >
            <Head title={manualBundle ? 'Bundle Soal Manual' : storyMode ? 'Hasil Soal Cerita AI' : 'Hasil Soal AI'} />
            <Modal show={studentPreviewOpen} maxWidth="5xl" onClose={() => setStudentPreviewOpen(false)}>
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-950 px-5 py-3 text-white">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-widest text-emerald-300">Pratinjau Siswa</p>
                        <h2 className="mt-0.5 text-sm font-bold text-white">Simulasi tampilan saat siswa mengerjakan</h2>
                    </div>
                    <div className="flex items-center gap-2.5">
                        {/* Ukuran Font di Modal */}
                        <div className="flex items-center gap-1 rounded border border-slate-800 bg-slate-900 px-1.5 py-0.5">
                            <span className="text-[10px] text-slate-400 font-semibold mr-0.5">Teks:</span>
                            {([90, 100, 115] as const).map((scale, i) => (
                                <button
                                    key={scale}
                                    type="button"
                                    onClick={() => setTextScale(scale)}
                                    className={`h-6 px-1.5 rounded text-[11px] font-bold transition ${
                                        textScale === scale ? 'bg-indigo-600 text-white' : 'text-slate-300 hover:text-white hover:bg-white/10'
                                    }`}
                                    title={scale === 90 ? 'Font Kecil' : scale === 100 ? 'Font Normal' : 'Font Besar'}
                                >
                                    {i === 0 ? 'A−' : i === 1 ? 'A' : 'A+'}
                                </button>
                            ))}
                        </div>

                        <a
                            href={`${typeof window !== 'undefined' ? window.location.pathname : ''}?preview=full`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-700 hover:text-white"
                            title="Buka pratinjau layar penuh di tab baru"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true" className="h-3.5 w-3.5 fill-none stroke-current stroke-2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" /><polyline points="15 3 21 3 21 9" /><line x1="10" y1="14" x2="21" y2="3" /></svg>
                            Buka Layar Penuh (Tab Baru)
                        </a>
                        <button type="button" onClick={() => setStudentPreviewOpen(false)} aria-label="Tutup pratinjau" className="rounded-lg p-2 text-2xl leading-none text-slate-300 hover:bg-white/10 hover:text-white">×</button>
                    </div>
                </div>
                {previewQuestion && (
                    <div className="max-h-[78vh] overflow-y-auto bg-slate-100 p-4 sm:p-6">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-3">
                            <p className="text-sm font-semibold text-slate-700">Soal {studentPreviewIndex + 1} dari {questions.length}</p>
                            <div className="flex flex-wrap gap-2">
                                {questions.map((question, index) => {
                                    const isAnswered = isQuestionAnswered(question.id);
                                    const isDoubtful = Boolean(doubtfulQuestions[question.id]);
                                    const isActive = index === studentPreviewIndex;

                                    let btnStyle = 'border border-slate-300 bg-slate-100 text-slate-700 hover:bg-slate-200';
                                    if (isActive) {
                                        btnStyle = isDoubtful
                                            ? 'border-2 border-amber-600 bg-amber-400 text-amber-950 font-black ring-2 ring-amber-300'
                                            : 'border-2 border-indigo-600 bg-slate-900 text-white font-black ring-2 ring-indigo-300';
                                    } else if (isDoubtful) {
                                        btnStyle = 'border border-amber-400 bg-amber-300 text-amber-950 font-bold';
                                    } else if (isAnswered) {
                                        btnStyle = 'border border-emerald-500 bg-emerald-600 text-white font-bold';
                                    }

                                    return (
                                        <button
                                            key={question.id}
                                            type="button"
                                            onClick={() => setStudentPreviewIndex(index)}
                                            className={`h-9 w-9 rounded-lg text-sm font-bold transition flex items-center justify-center ${btnStyle}`}
                                            title={`Soal ${index + 1}${isDoubtful ? ' (Ragu-ragu)' : isAnswered ? ' (Sudah dijawab)' : ''}`}
                                        >
                                            {index + 1}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        <div className={`grid overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm ${storyMode ? 'lg:grid-cols-2' : ''}`}>
                            {storyMode && (
                                <aside className="border-b border-slate-200 bg-slate-50 p-5 lg:border-b-0 lg:border-r lg:p-7">
                                    <div style={{ zoom: textScale / 100 }}>
                                        <p className="text-xs font-bold uppercase tracking-wider text-indigo-600">Stimulus</p>
                                        <h3 className="mt-2 text-xl font-bold text-slate-900">{generation.result_payload?.title}</h3>
                                        <div className="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-700">{generation.result_payload?.story}</div>
                                        {manualBundle && previewQuestion.illustration_url && (
                                            <img src={previewQuestion.illustration_url} alt={previewQuestion.metadata?.illustration?.alt || 'Gambar stimulus'} className="mt-4 max-h-72 w-full rounded-xl border border-slate-200 bg-white object-contain" />
                                        )}
                                    </div>
                                </aside>
                            )}
                            <section className="min-w-0 p-5 lg:p-7 flex flex-col justify-between">
                                <div style={{ zoom: textScale / 100 }}>
                                    {!storyMode && previewQuestion.stimulus && (
                                        <FormattedText text={previewQuestion.stimulus} className="mb-5 block whitespace-pre-wrap rounded-xl bg-slate-50 p-4 text-sm leading-7 text-slate-700" />
                                    )}
                                    {previewQuestion.illustration_url && (!storyMode || !manualBundle) && (
                                        <img src={previewQuestion.illustration_url} alt={previewQuestion.metadata?.illustration?.alt || 'Ilustrasi soal'} className="mb-5 max-h-72 w-full rounded-xl border border-slate-200 object-contain" />
                                    )}
                                    <p className="text-sm font-semibold text-emerald-600">Soal {studentPreviewIndex + 1}</p>
                                    <h3 className="mt-3 text-lg font-semibold leading-8 text-slate-900"><FormattedText text={previewQuestion.prompt} /></h3>

                                    <QuestionInteraction
                                        question={previewQuestion}
                                        previewOptionAnswers={previewOptionAnswers}
                                        previewMatrixAnswers={previewMatrixAnswers}
                                        shortAnswers={shortAnswers}
                                        setShortAnswers={setShortAnswers}
                                        choosePreviewOption={choosePreviewOption}
                                        choosePreviewMatrix={choosePreviewMatrix}
                                    />
                                </div>

                                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-2">
                                    <label className="inline-flex items-center gap-2 cursor-pointer select-none text-xs font-semibold text-amber-900 bg-amber-50 hover:bg-amber-100 px-2.5 py-1 rounded-lg border border-amber-300 transition">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(doubtfulQuestions[previewQuestion.id])}
                                            onChange={(e) => setDoubtfulQuestions((prev) => ({ ...prev, [previewQuestion.id]: e.target.checked }))}
                                            className="rounded border-amber-400 text-amber-600 focus:ring-amber-500 h-3.5 w-3.5"
                                        />
                                        <span>Ragu-ragu</span>
                                    </label>
                                    {isQuestionAnswered(previewQuestion.id) && (
                                        <button
                                            type="button"
                                            onClick={() => resetQuestionAnswer(previewQuestion.id)}
                                            className="text-xs font-semibold text-rose-600 hover:text-rose-700 hover:underline"
                                        >
                                            Hapus Pilihan Jawaban
                                        </button>
                                    )}
                                </div>
                            </section>
                        </div>

                        <div className="mt-4 flex items-center justify-between">
                            <button type="button" disabled={studentPreviewIndex === 0} onClick={() => setStudentPreviewIndex((index) => index - 1)} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 disabled:opacity-40">Sebelumnya</button>
                            {studentPreviewIndex < questions.length - 1 ? (
                                <button type="button" onClick={() => setStudentPreviewIndex((index) => index + 1)} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Berikutnya</button>
                            ) : (
                                <button type="button" onClick={() => setStudentPreviewOpen(false)} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Selesai pratinjau</button>
                            )}
                        </div>
                    </div>
                )}
            </Modal>
            <div className="mx-auto max-w-6xl space-y-5 px-4 py-6 sm:px-6">
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
                                <div className="flex flex-wrap items-center gap-2">
                                    {(isAuthor || canVerify) && storyMode && !editingStimulus && (
                                        <button
                                            type="button"
                                            onClick={() => setEditingStimulus(true)}
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 transition"
                                        >
                                            <svg viewBox="0 0 24 24" aria-hidden="true" className="h-3.5 w-3.5 fill-none stroke-current stroke-2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                            Edit Stimulus
                                        </button>
                                    )}
                                    {canDeleteBundle && (
                                        <button
                                            type="button"
                                            onClick={deleteBundle}
                                            disabled={deletingBundle}
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100 disabled:opacity-50 transition"
                                            title="Hapus seluruh bundle ini beserta seluruh butir soalnya"
                                        >
                                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                            <span>{deletingBundle ? 'Menghapus...' : 'Hapus Bundle'}</span>
                                        </button>
                                    )}
                                    {storyMode && <span className="rounded-full bg-indigo-50 px-3 py-1 text-sm font-semibold text-indigo-700">{generation.result_payload.paragraph_count || generation.request_payload.paragraph_count || '-'} paragraf</span>}
                                    {!storyMode && <span className="rounded-full bg-violet-50 px-3 py-1 text-sm font-semibold text-violet-700">{generation.request_payload.question_style === 'reasoning' ? 'Penalaran' : 'Langsung'}</span>}
                                    <span className={`rounded-full px-3 py-1 text-sm font-semibold ${verificationLocked ? 'bg-amber-100 text-amber-900 border border-amber-300' : allPublished ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700 border border-blue-200'}`}>
                                        {verificationLocked ? '🔒 Draft Pribadi' : allPublished ? '✓ Terbit' : '⏳ Menunggu Verifikasi'}
                                    </span>
                                    <span className={`rounded-full px-3 py-1 text-sm font-semibold ${allPublished ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                                        {allPublished ? `${publishedCount} soal terbit` : `${unpublishedCount} belum terbit · ${publishedCount} terbit`}
                                    </span>
                                </div>
                            </div>

                            {editingStimulus ? (
                                <form onSubmit={saveStimulus} className="mt-5 space-y-4 rounded-xl border border-indigo-200 bg-indigo-50/60 p-5">
                                    <div className="flex items-center justify-between">
                                        <h4 className="font-semibold text-indigo-950">Edit Teks & Judul Stimulus</h4>
                                        <span className="text-xs text-indigo-700">Perubahan otomatis diterapkan ke seluruh soal dalam bundel.</span>
                                    </div>
                                    <div>
                                        <label htmlFor="stimulus-title-input" className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                            Judul Stimulus
                                        </label>
                                        <input
                                            id="stimulus-title-input"
                                            type="text"
                                            value={stimulusTitle}
                                            onChange={(e) => setStimulusTitle(e.target.value)}
                                            required
                                            className="mt-1 block w-full rounded-lg border-slate-300 text-sm font-semibold shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            placeholder="Judul stimulus..."
                                        />
                                    </div>
                                    <div>
                                        <label htmlFor="stimulus-story-input" className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                            Teks Cerita / Stimulus
                                        </label>
                                        <textarea
                                            id="stimulus-story-input"
                                            rows={11}
                                            value={stimulusStory}
                                            onChange={(e) => setStimulusStory(e.target.value)}
                                            required
                                            className="mt-1 block w-full rounded-lg border-slate-300 text-sm leading-7 text-slate-800 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            placeholder="Teks stimulus lengkap..."
                                        />
                                    </div>
                                    <div className="flex items-center gap-2 pt-1">
                                        <button
                                            type="submit"
                                            disabled={savingStimulus || !stimulusTitle.trim() || !stimulusStory.trim()}
                                            className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50 transition"
                                        >
                                            {savingStimulus ? 'Menyimpan...' : 'Simpan Perubahan Stimulus'}
                                        </button>
                                        <button
                                            type="button"
                                            disabled={savingStimulus}
                                            onClick={() => {
                                                setStimulusTitle(generation.result_payload?.title || '');
                                                setStimulusStory(generation.result_payload?.story || '');
                                                setEditingStimulus(false);
                                            }}
                                            className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition"
                                        >
                                            Batal
                                        </button>
                                    </div>
                                </form>
                            ) : (
                                <>
                                    {storyMode && <div className="mt-5 whitespace-pre-wrap rounded-xl bg-slate-50 p-5 leading-7 text-slate-700">{generation.result_payload.story}</div>}
                                    {storyMode && manualBundle && questions[0]?.illustration_url && <img src={questions[0].illustration_url} alt={questions[0].metadata?.illustration?.alt || 'Gambar stimulus'} className="mt-5 max-h-[520px] w-full rounded-xl border border-slate-200 bg-slate-50 object-contain" />}
                                </>
                            )}
                        </article>

                        <div className={`flex flex-col justify-between gap-4 rounded-xl border p-4 sm:flex-row sm:items-center ${
                            verificationLocked ? 'border-amber-200 bg-amber-50/70' : allPublished ? 'border-emerald-200 bg-emerald-50/70' : 'border-blue-200 bg-blue-50/70'
                        }`}>
                            <div className="space-y-1">
                                {verificationLocked ? (
                                    <>
                                        <div className="flex items-center gap-2">
                                            <span className="inline-flex items-center rounded-full bg-amber-200 px-2.5 py-0.5 text-xs font-bold text-amber-900">
                                                🔒 Draft Pribadi
                                            </span>
                                            <p className="text-sm font-bold text-amber-950">Status: Belum Diajukan</p>
                                        </div>
                                        <p className="text-xs text-amber-900 max-w-2xl">
                                            Bundle ini masih berstatus draft pribadi (hanya Anda dan Admin yang dapat melihat). Rekan guru belum bisa melihat maupun memverifikasi.
                                        </p>
                                    </>
                                ) : allPublished ? (
                                    <>
                                        <div className="flex items-center gap-2">
                                            <span className="inline-flex items-center rounded-full bg-emerald-200 px-2.5 py-0.5 text-xs font-bold text-emerald-900">
                                                ✓ Seluruh Soal Terbit
                                            </span>
                                            <p className="text-sm font-bold text-emerald-950">Status: Terbit</p>
                                        </div>
                                        <p className="text-xs text-emerald-900 max-w-2xl">
                                            Seluruh butir soal dalam bundel ini telah memenuhi syarat minimal verifikasi guru dan siap digunakan dalam simulasi ujian.
                                        </p>
                                    </>
                                ) : (
                                    <>
                                        <div className="flex items-center gap-2">
                                            <span className="inline-flex items-center rounded-full bg-blue-200 px-2.5 py-0.5 text-xs font-bold text-blue-900">
                                                ⏳ Menunggu Verifikasi
                                            </span>
                                            <p className="text-sm font-bold text-blue-950">Status: Terbuka untuk Peninjauan Guru</p>
                                        </div>
                                        <p className="text-xs text-blue-900 max-w-2xl">
                                            Bundle ini dapat ditinjau dan diverifikasi oleh rekan guru. Soal otomatis terbit setelah mencapai batas minimal verifikasi guru yang ditentukan.
                                        </p>
                                    </>
                                )}
                            </div>

                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                {canSubmitForReview && (
                                    <button
                                        type="button"
                                        onClick={submitForReview}
                                        disabled={submittingForReview}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 disabled:opacity-50 transition"
                                    >
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <span>{submittingForReview ? 'Mengajukan...' : 'Ajukan Verifikasi'}</span>
                                    </button>
                                )}

                                {canRevertToDraft && (
                                    <button
                                        type="button"
                                        onClick={revertToDraft}
                                        disabled={revertingToDraft}
                                        className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-50 transition"
                                        title="Kembalikan bundle ke status draft pribadi agar tidak dapat dilihat guru lain"
                                    >
                                        <svg className="h-3.5 w-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                        </svg>
                                        <span>{revertingToDraft ? 'Mengembalikan...' : 'Kembalikan ke Draft'}</span>
                                    </button>
                                )}

                                {verificationLocked && manualBundle && (
                                    <Link
                                        href={route('manual-story-bundles.create', { subject_id: generation.request_payload.subject_id, draft_id: generation.id })}
                                        className="rounded-lg border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50 text-center transition"
                                    >
                                        Lanjutkan Isi Draft
                                    </Link>
                                )}

                                {!verificationLocked && storyMode && canVerify && (
                                    <button
                                        type="button"
                                        onClick={verifyBundle}
                                        disabled={allVerifiedByCurrentUser || publishing || questions.length === 0}
                                        className={`inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition ${
                                            allVerifiedByCurrentUser
                                                ? 'cursor-default bg-emerald-600'
                                                : 'bg-indigo-600 hover:bg-indigo-500'
                                        }`}
                                    >
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                        </svg>
                                        <span>
                                            {publishing
                                                ? 'Menyimpan...'
                                                : allVerifiedByCurrentUser
                                                  ? '✓ Anda Sudah Verifikasi Bundle'
                                                  : allPublished
                                                    ? 'Tambah Verifikasi Bundle'
                                                    : 'Verifikasi 1 Bundle (Semua Soal)'}
                                        </span>
                                    </button>
                                )}
                            </div>
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

                        <div className="flex flex-col gap-3 pt-1 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900">Daftar Butir Soal ({questions.length})</h3>
                                <p className="text-xs text-slate-500">{storyMode ? 'Semua butir mengacu pada stimulus di atas.' : 'Tinjau isi, kunci jawaban, dan status verifikasi setiap soal.'}</p>
                            </div>
                            {isAuthor && (
                                <button
                                    type="button"
                                    onClick={() => setShowAddQuestionModal('ai')}
                                    className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition self-start sm:self-auto"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                    </svg>
                                    <span>Tambah Soal</span>
                                </button>
                            )}
                        </div>

                        <section className="space-y-3">
                            {questions.map((question, index) => (
                                <article key={question.id} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                                    <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-start">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-1.5 text-xs font-semibold">
                                                <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-900 font-bold text-white">{index + 1}</span>
                                                <span className="rounded-full bg-indigo-50 px-2.5 py-1 text-indigo-700">{question.competency.code}</span>
                                                {question.question_blueprint && (
                                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-violet-50 px-2.5 py-1 text-violet-700">
                                                        <span>Tipe: {question.question_blueprint.name}</span>
                                                        <Link
                                                            href={route('question-types.edit', question.question_blueprint.id)}
                                                            target="_blank"
                                                            className="inline-flex items-center rounded p-0.5 text-violet-600 hover:bg-violet-100 hover:text-violet-900"
                                                            title={`Customize tipe soal ${question.question_blueprint.name} (buka di tab baru)`}
                                                        >
                                                            <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                                <path d="m13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
                                                            </svg>
                                                        </Link>
                                                    </span>
                                                )}
                                                <span className={`rounded-full px-2.5 py-1 ${question.status === 'published' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                                                    {question.status === 'published' ? 'Terbit' : question.status === 'review' ? 'Menunggu verifikasi' : 'Draft'}
                                                </span>
                                            </div>
                                            <p className="mt-2 text-xs text-slate-500">Kelas {question.grade_level} · Kesulitan {question.difficulty} · {question.verification.count}/{question.verification.required} verifikasi</p>
                                            {!storyMode && question.stimulus && <FormattedText text={question.stimulus} className="mt-2 block whitespace-pre-wrap rounded-lg bg-slate-50 px-3 py-2 text-sm leading-6 text-slate-700" />}
                                            <h3 className="mt-2 text-base font-semibold leading-7 text-slate-900"><FormattedText text={question.prompt} /></h3>
                                        </div>
                                        {question.status === 'published' ? (
                                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                                <span className="rounded-lg bg-emerald-50 px-2.5 py-1.5 text-center text-xs font-semibold text-emerald-700">✓ Terbit</span>
                                                {canVerify && !question.verification.currentUserVerified && (
                                                    <button type="button" disabled={verifyingQuestionId !== null} onClick={() => storyMode ? verifyQuestionDirectly(question.id) : addPublishedVerification(question.id)} className="rounded-lg border border-blue-300 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50 disabled:opacity-50 transition">
                                                        {verifyingQuestionId === question.id ? 'Menyimpan...' : 'Tambah Verifikasi'}
                                                    </button>
                                                )}
                                            </div>
                                        ) : (
                                            <div className="relative flex shrink-0 flex-wrap items-center gap-1.5">
                                                {question.verification.currentUserVerified && (
                                                    <span className="rounded-lg bg-blue-50 px-3 py-1.5 text-center text-xs font-semibold text-blue-700">✓ Anda sudah verifikasi</span>
                                                )}
                                                {isAuthor && question.status === 'draft' && (
                                                    <button
                                                        type="button"
                                                        disabled={togglingQuestionId === question.id}
                                                        onClick={() => toggleQuestionStatus(question.id, 'review')}
                                                        className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-center text-xs font-semibold text-amber-800 hover:bg-amber-100 disabled:opacity-50 transition"
                                                        title="Ajukan soal ini ke status menunggu verifikasi"
                                                    >
                                                        {togglingQuestionId === question.id ? 'Menyimpan...' : 'Ajukan Verifikasi'}
                                                    </button>
                                                )}
                                                {isAuthor && question.status === 'review' && (
                                                    <button
                                                        type="button"
                                                        disabled={togglingQuestionId === question.id}
                                                        onClick={() => toggleQuestionStatus(question.id, 'draft')}
                                                        className="rounded-lg border border-slate-300 bg-slate-50 px-3 py-1.5 text-center text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50 transition"
                                                        title="Kembalikan soal ini ke status draft"
                                                    >
                                                        {togglingQuestionId === question.id ? 'Menyimpan...' : 'Jadikan Draft'}
                                                    </button>
                                                )}
                                                {storyMode && canVerify && !question.verification.currentUserVerified && (
                                                    <button type="button" disabled={verifyingQuestionId !== null} onClick={() => verifyQuestionDirectly(question.id)} className="rounded-lg bg-emerald-600 px-3 py-1.5 text-center text-xs font-semibold text-white hover:bg-emerald-500 disabled:opacity-50 transition">
                                                        {verifyingQuestionId === question.id ? 'Menyimpan...' : 'Verifikasi Soal'}
                                                    </button>
                                                )}
                                                {!storyMode && !question.verification.currentUserVerified && (
                                                    <button type="button" disabled={checkingQuestionId !== null || verifyingQuestionId !== null} onClick={() => checkDuplicates(question.id)} className="rounded-lg bg-emerald-600 px-3 py-1.5 text-center text-xs font-semibold text-white hover:bg-emerald-500 disabled:opacity-50 transition">
                                                        {checkingQuestionId === question.id ? 'Memeriksa...' : 'Cek Duplikasi'}
                                                    </button>
                                                )}
                                                <>
                                                        <button
                                                            type="button"
                                                            onClick={() => setQuestionActionMenuId(questionActionMenuId === question.id ? null : question.id)}
                                                            className="inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                                            aria-expanded={questionActionMenuId === question.id}
                                                        >
                                                            Lainnya <span className="text-[10px]">▾</span>
                                                        </button>
                                                        {questionActionMenuId === question.id && (
                                                            <div className="absolute right-0 top-9 z-20 w-48 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl">
                                                                {isAuthor && <button
                                                                    type="button"
                                                                    disabled={regeneratingQuestionId === question.id}
                                                                    onClick={() => {
                                                                        setQuestionActionMenuId(null);
                                                                        setShowRegenerateModal(question.id);
                                                                        setRegenerateInstruction('');
                                                                    }}
                                                                    className="block w-full px-3 py-2 text-left text-xs font-semibold text-purple-700 hover:bg-purple-50 disabled:opacity-50"
                                                                >
                                                                    ✨ Generate ulang
                                                                </button>}
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        setQuestionActionMenuId(null);
                                                                        setEditingQuestionId(editingQuestionId === question.id ? null : question.id);
                                                                    }}
                                                                    className="block w-full px-3 py-2 text-left text-xs font-semibold text-indigo-700 hover:bg-indigo-50"
                                                                >
                                                                    {editingQuestionId === question.id ? 'Tutup editor' : 'Edit langsung'}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        setQuestionActionMenuId(null);
                                                                        deleteQuestion(question.id);
                                                                    }}
                                                                    className="block w-full border-t border-slate-100 px-3 py-2 text-left text-xs font-semibold text-rose-700 hover:bg-rose-50"
                                                                >
                                                                    Hapus soal
                                                                </button>
                                                            </div>
                                                        )}
                                                </>
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
                                        <div className={`mt-3 rounded-xl border p-3 ${duplicateResult.blocking ? 'border-rose-200 bg-rose-50' : 'border-emerald-200 bg-emerald-50'}`}>
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <h4 className={`text-sm font-bold ${duplicateResult.blocking ? 'text-rose-900' : 'text-emerald-900'}`}>
                                                        {duplicateResult.blocking ? 'Duplikasi kuat ditemukan' : 'Tidak ada duplikasi kuat'}
                                                    </h4>
                                                    <p className={`mt-0.5 text-xs ${duplicateResult.blocking ? 'text-rose-700' : 'text-emerald-700'}`}>
                                                        {duplicateResult.candidates.length} soal dengan pola serupa ditemukan.
                                                    </p>
                                                </div>
                                                <button type="button" onClick={() => setDuplicateResult(null)} aria-label="Tutup hasil duplikasi" className="flex h-7 w-7 items-center justify-center rounded-lg bg-white text-lg text-slate-400 hover:text-slate-700">×</button>
                                            </div>
                                            {duplicateResult.candidates.length === 0 ? (
                                                <p className="mt-2 text-sm text-emerald-700">Soal dapat dilanjutkan ke verifikasi.</p>
                                            ) : (
                                                <div className="mt-3 grid gap-2 md:grid-cols-2">
                                                    {duplicateResult.candidates.map((candidate) => (
                                                        <Link key={candidate.id} href={route('questions.show', candidate.id)} target="_blank" className={`block rounded-lg border bg-white px-3 py-2 text-xs transition hover:shadow-sm ${candidate.blocking ? 'border-rose-200' : 'border-amber-200'}`}>
                                                            <span className="flex items-center justify-between gap-2">
                                                                <strong className="truncate text-slate-800">{candidate.competency}</strong>
                                                                <span className={`shrink-0 rounded-full px-2 py-0.5 font-bold ${candidate.blocking ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700'}`}>{candidate.similarity}%</span>
                                                            </span>
                                                            <span className="mt-1 line-clamp-1 block text-slate-600">{candidate.prompt}</span>
                                                        </Link>
                                                    ))}
                                                </div>
                                            )}
                                            {!duplicateResult.blocking && <div className="mt-3 flex justify-end">
                                                <button type="button" disabled={verifyingQuestionId !== null} onClick={() => verifyQuestion(question.id)} className="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-600 disabled:opacity-50">
                                                    {verifyingQuestionId === question.id ? 'Memverifikasi...' : 'Lanjut Verifikasi'}
                                                </button>
                                            </div>}
                                        </div>
                                    )}

                                    {question.illustration_url && (!storyMode || !manualBundle) && (
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
                                                <div key={pair.left_id} className="grid grid-cols-[minmax(0,1fr)_32px_minmax(0,1fr)] items-center border-b border-slate-100 p-3 text-sm last:border-b-0">
                                                    <span>{pair.left}</span><span className="text-center text-emerald-600">→</span><span className="font-medium text-emerald-800">{pair.right}</span>
                                                </div>
                                            ))}
                                            {(question.metadata.matching_distractors?.length || 0) > 0 && <p className="border-t border-amber-100 bg-amber-50 p-3 text-sm text-amber-800">Distraktor: {question.metadata.matching_distractors?.map((item) => item.content).join(', ')}</p>}
                                        </div>
                                    ) : question.options.length > 0 ? (
                                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                            {question.options.map((option) => (
                                                <div key={option.id} className={`rounded-lg border px-3 py-2.5 text-sm ${option.is_correct ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 text-slate-700'}`}>
                                                    {question.type === 'multiple_choice' ? <span className={`mr-2 inline-flex h-5 w-5 items-center justify-center rounded border text-xs ${option.is_correct ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 text-transparent'}`}>✓</span> : <span className="mr-2 font-semibold">{option.label}.</span>}<FormattedText text={option.content} />
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">Jawaban diterima: {question.metadata?.accepted_answers?.join(', ')}</p>
                                    )}

                                    {question.explanation && <p className="mt-3 border-t border-slate-100 pt-3 text-sm leading-6 text-slate-600"><span className="font-semibold text-slate-800">Pembahasan:</span> <FormattedText text={question.explanation} /></p>}
                                    <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs text-slate-500">
                                        <span>Pembuat: <strong className="text-slate-700">{question.author.name}</strong></span>
                                        <span>Verifikasi: <strong className="text-slate-700">{question.verification.count}/{question.verification.required}</strong>{question.verification.verifiers.length > 0 ? ` · ${question.verification.verifiers.map((item) => item.name).join(', ')}` : ''}</span>
                                    </div>
                                </article>
                            ))}
                        </section>
                    </>
                )}

                {/* Modal Tambah Soal ke Bundle */}
                <Modal show={showAddQuestionModal !== null} onClose={() => setShowAddQuestionModal(null)} maxWidth="2xl">
                    <div className="p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900">Tambah Soal ke Bundle Ini</h3>
                                <p className="text-xs text-slate-500">Soal baru akan otomatis mengacu pada stimulus cerita bundle saat ini.</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowAddQuestionModal(null)}
                                className="text-2xl font-bold leading-none text-slate-400 hover:text-slate-600"
                            >
                                ×
                            </button>
                        </div>

                        {/* Tab Buttons */}
                        <div className="mt-4 flex border-b border-slate-200">
                            <button
                                type="button"
                                onClick={() => setShowAddQuestionModal('ai')}
                                className={`flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition ${
                                    showAddQuestionModal === 'ai'
                                        ? 'border-indigo-600 text-indigo-600'
                                        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                                }`}
                            >
                                <span>✨ Buat dengan AI</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setShowAddQuestionModal('manual')}
                                className={`flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition ${
                                    showAddQuestionModal === 'manual'
                                        ? 'border-indigo-600 text-indigo-600'
                                        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                                }`}
                            >
                                <span>✍️ Tulis Manual</span>
                            </button>
                        </div>

                        {/* Tab 1: AI Generation */}
                        {showAddQuestionModal === 'ai' && (
                            <form onSubmit={submitAiQuestion} className="mt-5 space-y-4">
                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                        Kompetensi Dasar / Capaian Pembelajaran
                                    </label>
                                    <select
                                        value={aiForm.competency_id}
                                        onChange={(e) => setAiForm({ ...aiForm, competency_id: e.target.value })}
                                        required
                                        className="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        {availableCompetencies.map((comp) => (
                                            <option key={comp.id} value={comp.id}>
                                                [{comp.code}] {comp.name} (Kelas {comp.grade_level})
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                            Bentuk / Tipe Jawaban
                                        </label>
                                        <select
                                            value={aiForm.answer_format}
                                            onChange={(e) => setAiForm({ ...aiForm, answer_format: e.target.value })}
                                            className="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            {activeQuestionTypes.map((type) => (
                                                <option key={type.value} value={type.value}>
                                                    {QUESTION_TYPE_DISPLAY_LABELS[type.value] || type.label}
                                                </option>
                                            ))}
                                        </select>
                                        {questionTypes && questionTypes.length > 0 && questionTypes.some((t) => t.active === false) && (
                                            <p className="mt-1 text-[11px] text-slate-500">
                                                * Beberapa tipe soal dinonaktifkan oleh admin sekolah pada pengaturan bentuk soal.
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                            Blueprint / Format Soal (Opsional)
                                        </label>
                                        <select
                                            value={aiForm.question_blueprint_id}
                                            onChange={(e) => setAiForm({ ...aiForm, question_blueprint_id: e.target.value })}
                                            className="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option value="">-- Bebas / Otomatis --</option>
                                            {availableBlueprints.map((bp) => (
                                                <option key={bp.id} value={bp.id}>
                                                    {bp.name} ({bp.code})
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                            Tingkat Kesulitan
                                        </label>
                                        <select
                                            value={aiForm.difficulty}
                                            onChange={(e) => setAiForm({ ...aiForm, difficulty: Number(e.target.value) })}
                                            className="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option value={1}>1 - Mudah</option>
                                            <option value={2}>2 - Sedang</option>
                                            <option value={3}>3 - Sulit / Menantang</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                            Level Kognitif
                                        </label>
                                        <select
                                            value={aiForm.cognitive_level}
                                            onChange={(e) => setAiForm({ ...aiForm, cognitive_level: e.target.value })}
                                            className="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option value="Pengetahuan dan Pemahaman (Level 1)">Pengetahuan & Pemahaman (L1)</option>
                                            <option value="Pemahaman Inferensial (Level 2)">Aplikasi / Pemahaman Inferensial (L2)</option>
                                            <option value="Penalaran dan Refleksi (Level 3)">Penalaran & Refleksi (L3)</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                        Petunjuk Tambahan untuk AI <span className="font-normal text-slate-400">(Opsional)</span>
                                    </label>
                                    <textarea
                                        rows={2}
                                        value={aiForm.instruction}
                                        onChange={(e) => setAiForm({ ...aiForm, instruction: e.target.value })}
                                        placeholder="Contoh: Buat pertanyaan mengenai watak tokoh atau amanat cerita..."
                                        className="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>

                                <div className="flex justify-end gap-2 pt-3 border-t border-slate-100">
                                    <button
                                        type="button"
                                        onClick={() => setShowAddQuestionModal(null)}
                                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={generatingAiQuestion}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 transition"
                                    >
                                        {generatingAiQuestion ? '✨ AI Sedang Membuat Soal...' : '✨ Generate Soal Baru'}
                                    </button>
                                </div>
                            </form>
                        )}

                        {/* Tab 2: Manual Creation */}
                        {showAddQuestionModal === 'manual' && (
                            <form onSubmit={submitManualQuestion} className="mt-5 space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                            Kompetensi Dasar
                                        </label>
                                        <select
                                            value={manualForm.competency_id}
                                            onChange={(e) => setManualForm({ ...manualForm, competency_id: e.target.value })}
                                            required
                                            className="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            {availableCompetencies.map((comp) => (
                                                <option key={comp.id} value={comp.id}>
                                                    [{comp.code}] {comp.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                            Tipe Soal
                                        </label>
                                        <select
                                            value={manualForm.type}
                                            onChange={(e) => setManualForm({ ...manualForm, type: e.target.value as 'single_choice' | 'multiple_choice' })}
                                            className="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            {activeManualTypes.map((type) => (
                                                <option key={type.value} value={type.value}>
                                                    {type.value === 'single_choice' ? 'Pilihan Ganda Biasa (1 Jawaban Benar)' : 'Pilihan Ganda Kompleks (Bisa >1 Jawaban Benar)'}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                            Tingkat Kesulitan
                                        </label>
                                        <select
                                            value={manualForm.difficulty}
                                            onChange={(e) => setManualForm({ ...manualForm, difficulty: Number(e.target.value) })}
                                            className="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option value={1}>1 - Mudah</option>
                                            <option value={2}>2 - Sedang</option>
                                            <option value={3}>3 - Sulit</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                            Blueprint (Opsional)
                                        </label>
                                        <select
                                            value={manualForm.question_blueprint_id}
                                            onChange={(e) => setManualForm({ ...manualForm, question_blueprint_id: e.target.value })}
                                            className="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option value="">-- Tanpa Blueprint --</option>
                                            {availableBlueprints.map((bp) => (
                                                <option key={bp.id} value={bp.id}>
                                                    {bp.name} ({bp.code})
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                        Pertanyaan / Butir Soal
                                    </label>
                                    <textarea
                                        rows={3}
                                        value={manualForm.prompt}
                                        onChange={(e) => setManualForm({ ...manualForm, prompt: e.target.value })}
                                        required
                                        placeholder="Tuliskan butir pertanyaan di sini..."
                                        className="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>

                                <div>
                                    <div className="flex items-center justify-between mb-1">
                                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                            Pilihan Jawaban (Tandai Jawaban yang Benar)
                                        </label>
                                        {manualForm.options.length < 5 && (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    const nextLabel = String.fromCharCode(65 + manualForm.options.length);
                                                    setManualForm({
                                                        ...manualForm,
                                                        options: [...manualForm.options, { label: nextLabel, content: '', is_correct: false }],
                                                    });
                                                }}
                                                className="text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                                            >
                                                + Tambah Pilihan
                                            </button>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        {manualForm.options.map((option, idx) => (
                                            <div key={option.label} className="flex items-center gap-2">
                                                <input
                                                    type={manualForm.type === 'single_choice' ? 'radio' : 'checkbox'}
                                                    name="manual_correct_answer"
                                                    checked={option.is_correct}
                                                    onChange={(e) => {
                                                        const checked = e.target.checked;
                                                        setManualForm({
                                                            ...manualForm,
                                                            options: manualForm.options.map((opt, i) => {
                                                                if (manualForm.type === 'single_choice') {
                                                                    return { ...opt, is_correct: i === idx };
                                                                }
                                                                return i === idx ? { ...opt, is_correct: checked } : opt;
                                                            }),
                                                        });
                                                    }}
                                                    className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                                    title="Pilih sebagai jawaban benar"
                                                />
                                                <span className="w-6 text-center text-sm font-bold text-slate-600">{option.label}.</span>
                                                <input
                                                    type="text"
                                                    value={option.content}
                                                    onChange={(e) => {
                                                        const val = e.target.value;
                                                        setManualForm({
                                                            ...manualForm,
                                                            options: manualForm.options.map((opt, i) => (i === idx ? { ...opt, content: val } : opt)),
                                                        });
                                                    }}
                                                    placeholder={`Teks pilihan ${option.label}...`}
                                                    className="flex-1 rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                />
                                                {manualForm.options.length > 2 && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            const filtered = manualForm.options.filter((_, i) => i !== idx).map((opt, i) => ({ ...opt, label: String.fromCharCode(65 + i) }));
                                                            setManualForm({ ...manualForm, options: filtered });
                                                        }}
                                                        className="text-rose-500 hover:text-rose-700 text-xs font-bold px-1"
                                                        title="Hapus pilihan"
                                                    >
                                                        ✕
                                                    </button>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                        Pembahasan Jawaban
                                    </label>
                                    <textarea
                                        rows={2}
                                        value={manualForm.explanation}
                                        onChange={(e) => setManualForm({ ...manualForm, explanation: e.target.value })}
                                        required
                                        placeholder="Tuliskan penjelasan dan alasan kunci jawaban..."
                                        className="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>

                                <div className="flex justify-end gap-2 pt-3 border-t border-slate-100">
                                    <button
                                        type="button"
                                        onClick={() => setShowAddQuestionModal(null)}
                                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={addingManualQuestion}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 disabled:opacity-50 transition"
                                    >
                                        {addingManualQuestion ? 'Menyimpan...' : 'Simpan Soal'}
                                    </button>
                                </div>
                            </form>
                        )}
                    </div>
                </Modal>

                {/* Modal Generate Ulang Soal Tertentu */}
                <Modal show={showRegenerateModal !== null} onClose={() => setShowRegenerateModal(null)} maxWidth="lg">
                    <div className="p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3">
                            <h3 className="text-lg font-bold text-slate-900 flex items-center gap-2">
                                <span>✨ Generate Ulang Soal dengan AI</span>
                            </h3>
                            <button
                                type="button"
                                onClick={() => setShowRegenerateModal(null)}
                                className="text-2xl font-bold leading-none text-slate-400 hover:text-slate-600"
                            >
                                ×
                            </button>
                        </div>
                        <div className="mt-4 space-y-4">
                            <p className="text-sm text-slate-600 leading-relaxed">
                                AI akan membuat ulang pertanyaan, pilihan jawaban, dan pembahasan untuk butir soal ini dengan tetap mengacu pada teks stimulus cerita dalam bundel.
                            </p>
                            {showRegenerateModal !== null && (
                                <div className="rounded-lg bg-slate-50 p-3 border border-slate-200">
                                    <span className="text-xs font-semibold text-slate-500 block mb-1">Pertanyaan saat ini:</span>
                                    <p className="text-sm font-medium text-slate-800 line-clamp-3">
                                        {questions.find((q) => q.id === showRegenerateModal)?.prompt}
                                    </p>
                                </div>
                            )}
                            <div>
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                    Petunjuk / Arahan Tambahan untuk AI <span className="font-normal text-slate-400">(Opsional)</span>
                                </label>
                                <textarea
                                    rows={3}
                                    value={regenerateInstruction}
                                    onChange={(e) => setRegenerateInstruction(e.target.value)}
                                    placeholder="Contoh: Fokus pada paragraf ke-2 cerita, atau buat opsi jawaban yang lebih menantang..."
                                    className="w-full rounded-lg border-slate-300 text-sm focus:border-purple-500 focus:ring-purple-500"
                                />
                            </div>
                            <div className="flex justify-end gap-2 pt-2 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setShowRegenerateModal(null)}
                                    className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition"
                                >
                                    Batal
                                </button>
                                <button
                                    type="button"
                                    disabled={regeneratingQuestionId !== null}
                                    onClick={() => showRegenerateModal && handleRegenerateQuestion(showRegenerateModal)}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white hover:bg-purple-500 disabled:opacity-50 transition"
                                >
                                    {regeneratingQuestionId !== null ? 'Sedang Membuat Ulang...' : 'Mulai Generate Ulang'}
                                </button>
                            </div>
                        </div>
                    </div>
                </Modal>
            </div>
        </AuthenticatedLayout>
    );
}
