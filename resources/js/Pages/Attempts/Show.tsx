import ThemeToggle from '@/Components/ThemeToggle';
import StimulusVisual, { StimulusVisualData } from '@/Components/StimulusVisual';
import PositionedImage from '@/Components/PositionedImage';
import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

type ResponseValue = { option_ids?: number[]; text?: string; matches?: Record<string, string>; matrix_answers?: Record<string, string> };
type MatchingItem = { id: string; content: string };
type ExamQuestion = {
    id: number;
    type: 'single_choice' | 'multiple_choice' | 'short_answer' | 'matching' | 'category_matrix';
    stimulus?: string;
    stimulus_visual?: StimulusVisualData;
    illustration_url?: string;
    illustration_display?: { width: number; height: number; zoom: number; offset_x: number; offset_y: number };
    prompt: string;
    position: number;
    options: { id: number; label: string; content: string }[];
    matching?: { left_items: MatchingItem[]; right_items: MatchingItem[] };
    matrix?: { columns: { id: string; label: string }[]; rows: { id: string; statement: string }[] };
    response?: ResponseValue;
};
type Attempt = {
    public_id: string;
    remaining_seconds: number;
    assessment: {
        title: string;
        duration_minutes: number;
        type_label: string;
        show_navigation: boolean;
        require_all_answers: boolean;
    };
    questions: ExamQuestion[];
};
type TextScale = 90 | 100 | 115;

const matchingStyles = [
    { card: 'border-cyan-500 bg-cyan-50', badge: 'bg-cyan-500 text-white' },
    { card: 'border-blue-500 bg-blue-50', badge: 'bg-blue-500 text-white' },
    { card: 'border-emerald-500 bg-emerald-50', badge: 'bg-emerald-500 text-white' },
    { card: 'border-violet-500 bg-violet-50', badge: 'bg-violet-500 text-white' },
    { card: 'border-amber-500 bg-amber-50', badge: 'bg-amber-500 text-white' },
    { card: 'border-rose-500 bg-rose-50', badge: 'bg-rose-500 text-white' },
    { card: 'border-teal-500 bg-teal-50', badge: 'bg-teal-500 text-white' },
    { card: 'border-fuchsia-500 bg-fuchsia-50', badge: 'bg-fuchsia-500 text-white' },
];

const hasCompleteAnswer = (question: ExamQuestion, response?: ResponseValue) => {
    if (question.type === 'matching') {
        return Boolean(question.matching?.left_items.length) && Object.keys(response?.matches || {}).length === question.matching?.left_items.length;
    }

    if (question.type === 'category_matrix') {
        return Boolean(question.matrix?.rows.length) && Object.keys(response?.matrix_answers || {}).length === question.matrix?.rows.length;
    }

    return (response?.option_ids?.length || 0) > 0 || Boolean(response?.text?.trim());
};

const restoreLocalResponses = (
    storageKey: string,
    legacyStorageKey: string,
    initialResponses: Record<number, ResponseValue>,
): Record<number, ResponseValue> => {
    const local = window.localStorage.getItem(storageKey) ?? window.localStorage.getItem(legacyStorageKey);
    if (!local) return initialResponses;

    try {
        const parsed: unknown = JSON.parse(local);
        if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
            throw new Error('Cadangan jawaban bukan objek yang valid.');
        }

        const validQuestionIds = new Set(Object.keys(initialResponses));
        const restored = Object.fromEntries(
            Object.entries(parsed)
                .filter(([questionId, response]) => validQuestionIds.has(questionId)
                    && response !== null
                    && typeof response === 'object'
                    && !Array.isArray(response)),
        ) as Record<number, ResponseValue>;

        return { ...initialResponses, ...restored };
    } catch {
        window.localStorage.removeItem(storageKey);
        window.localStorage.removeItem(legacyStorageKey);

        return initialResponses;
    }
};

const storedTextScale = (): TextScale => {
    try {
        const value = Number(window.localStorage.getItem('toa-exam-text-scale'));
        return [90, 100, 115].includes(value) ? value as TextScale : 100;
    } catch {
        return 100;
    }
};

const storedHighContrast = (): boolean => {
    try {
        return window.localStorage.getItem('toa-exam-high-contrast') === 'true';
    } catch {
        return false;
    }
};

const compactMatrixLabel = (label: string): string =>
    label.trim().toLocaleLowerCase('id-ID') === 'tidak sesuai' ? 'Tidak' : label;

export default function Show({ attempt }: { attempt: Attempt }) {
    const storageKey = `toa-attempt-${attempt.public_id}`;
    const legacyStorageKey = `tka-attempt-${attempt.public_id}`;
    const initialResponses = Object.fromEntries(attempt.questions.map((question) => [question.id, question.response || {}]));
    const [responses, setResponses] = useState<Record<number, ResponseValue>>(() => restoreLocalResponses(storageKey, legacyStorageKey, initialResponses));
    const [currentIndex, setCurrentIndex] = useState(0);
    const [remaining, setRemaining] = useState(() => Math.max(0, Math.ceil(attempt.remaining_seconds)));
    const [saveStatus, setSaveStatus] = useState('Tersimpan');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [textScale, setTextScale] = useState<TextScale>(storedTextScale);
    const [highContrast, setHighContrast] = useState(storedHighContrast);
    const [selectedLeft, setSelectedLeft] = useState<Record<number, string | undefined>>({});
    const submitted = useRef(false);
    const pendingSaves = useRef<Set<Promise<boolean>>>(new Set());
    const usedFullscreen = useRef(false);
    const questionHeading = useRef<HTMLHeadingElement>(null);
    const current = attempt.questions[currentIndex];
    const answeredCount = useMemo(() => attempt.questions.filter((question) => hasCompleteAnswer(question, responses[question.id])).length, [attempt.questions, responses]);

    useEffect(() => {
        const timer = window.setInterval(() => setRemaining((value) => Math.max(0, value - 1)), 1000);
        return () => window.clearInterval(timer);
    }, []);

    useEffect(() => {
        try {
            window.localStorage.setItem('toa-exam-text-scale', String(textScale));
        } catch {
            // Pengaturan tetap berlaku selama halaman ujian aktif.
        }
    }, [textScale]);

    useEffect(() => {
        document.documentElement.classList.toggle('toa-high-contrast', highContrast);

        try {
            window.localStorage.setItem('toa-exam-high-contrast', String(highContrast));
        } catch {
            // Pengaturan tetap berlaku selama halaman ujian aktif.
        }

        return () => document.documentElement.classList.remove('toa-high-contrast');
    }, [highContrast]);

    useEffect(() => {
        if (remaining === 0 && !submitted.current) void submitAttempt(true);
    }, [remaining]);

    useEffect(() => {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(responses));
            window.localStorage.removeItem(legacyStorageKey);
        } catch {
            setSaveStatus('Cadangan browser tidak tersedia');
        }
    }, [responses]);

    useEffect(() => {
        const sync = async () => {
            void recordEvent('connection_restored');
            if (remaining === 0) {
                await submitAttempt(true);
                return;
            }

            await Promise.all(Object.entries(responses).map(([questionId, response]) => save(Number(questionId), response)));
        };
        const offline = () => void recordEvent('connection_lost');
        window.addEventListener('online', sync);
        window.addEventListener('offline', offline);
        return () => {
            window.removeEventListener('online', sync);
            window.removeEventListener('offline', offline);
        };
    }, [responses, remaining]);

    useEffect(() => {
        const visibility = () => document.hidden && void recordEvent('tab_hidden');
        const fullscreen = () => usedFullscreen.current && !document.fullscreenElement && void recordEvent('fullscreen_exit');
        document.addEventListener('visibilitychange', visibility);
        document.addEventListener('fullscreenchange', fullscreen);
        return () => {
            document.removeEventListener('visibilitychange', visibility);
            document.removeEventListener('fullscreenchange', fullscreen);
        };
    }, []);

    const recordEvent = async (eventType: string, payload?: Record<string, unknown>) => {
        try {
            await window.axios.post(route('attempts.events.store', attempt.public_id), {
                event_type: eventType,
                payload,
            });
        } catch {
            return;
        }
    };

    const save = (questionId: number, response: ResponseValue): Promise<boolean> => {
        const request = (async (): Promise<boolean> => {
            setSaveStatus('Menyimpan…');
            try {
                await window.axios.put(route('attempts.answers.update', [attempt.public_id, questionId]), response);
                setSaveStatus('Tersimpan');
                return true;
            } catch {
                setSaveStatus('Tersimpan lokal; menunggu koneksi');
                void recordEvent('autosave_failed', { question_id: questionId });
                return false;
            }
        })();

        pendingSaves.current.add(request);
        void request.finally(() => pendingSaves.current.delete(request));

        return request;
    };

    const waitForPendingSaves = async () => {
        while (pendingSaves.current.size > 0) {
            await Promise.all([...pendingSaves.current]);
        }
    };

    const syncLatestResponses = async (): Promise<boolean> => {
        await waitForPendingSaves();
        const results = await Promise.all(
            Object.entries(responses).map(([questionId, response]) => save(Number(questionId), response)),
        );

        return results.every(Boolean);
    };

    const enterFullscreen = async () => {
        try {
            await document.documentElement.requestFullscreen();
            usedFullscreen.current = true;
        } catch {
            return;
        }
    };

    const updateResponse = (question: ExamQuestion, response: ResponseValue) => {
        setResponses((values) => ({ ...values, [question.id]: response }));
        void save(question.id, response);
    };

    const chooseOption = (question: ExamQuestion, optionId: number) => {
        const selected = responses[question.id]?.option_ids || [];
        const optionIds = question.type === 'single_choice'
            ? [optionId]
            : selected.includes(optionId)
                ? selected.filter((id) => id !== optionId)
                : [...selected, optionId];
        updateResponse(question, { option_ids: optionIds });
    };

    const navigateSingleChoice = (event: React.KeyboardEvent<HTMLButtonElement>, question: ExamQuestion, optionIndex: number) => {
        if (question.type !== 'single_choice' || !['ArrowDown', 'ArrowRight', 'ArrowUp', 'ArrowLeft'].includes(event.key)) return;

        event.preventDefault();
        const direction = ['ArrowDown', 'ArrowRight'].includes(event.key) ? 1 : -1;
        const nextIndex = (optionIndex + direction + question.options.length) % question.options.length;
        chooseOption(question, question.options[nextIndex].id);
        window.requestAnimationFrame(() => document.getElementById(`question-${question.id}-option-${nextIndex}`)?.focus());
    };

    const chooseMatch = (question: ExamQuestion, rightId: string) => {
        const leftId = selectedLeft[question.id];
        if (!leftId) return;

        const matches = { ...(responses[question.id]?.matches || {}) };
        Object.entries(matches).forEach(([existingLeftId, existingRightId]) => {
            if (existingRightId === rightId) delete matches[existingLeftId];
        });
        matches[leftId] = rightId;
        updateResponse(question, { matches });
        setSelectedLeft((values) => ({ ...values, [question.id]: undefined }));
    };

    const chooseMatrixAnswer = (question: ExamQuestion, rowId: string, columnId: string) => {
        updateResponse(question, {
            matrix_answers: {
                ...(responses[question.id]?.matrix_answers || {}),
                [rowId]: columnId,
            },
        });
    };

    const preventQuestionContentAction = (event: React.SyntheticEvent<HTMLElement>) => {
        const target = event.target;
        if (target instanceof HTMLElement && target.closest('input, textarea')) return;

        event.preventDefault();
    };

    useEffect(() => {
        questionHeading.current?.focus({ preventScroll: true });
    }, [currentIndex]);

    useEffect(() => {
        const navigateWithKeyboard = (event: KeyboardEvent) => {
            if (!event.altKey || !['ArrowLeft', 'ArrowRight'].includes(event.key)) return;

            event.preventDefault();
            setCurrentIndex((index) => event.key === 'ArrowLeft'
                ? Math.max(0, index - 1)
                : Math.min(attempt.questions.length - 1, index + 1));
        };

        window.addEventListener('keydown', navigateWithKeyboard);
        return () => window.removeEventListener('keydown', navigateWithKeyboard);
    }, [attempt.questions.length]);

    const submitAttempt = async (timeExpired = false) => {
        if (submitted.current) return;
        if (attempt.assessment.require_all_answers && !timeExpired && answeredCount < attempt.questions.length) {
            window.alert(`Masih ada ${attempt.questions.length - answeredCount} soal yang belum dijawab.`);
            return;
        }

        submitted.current = true;
        setIsSubmitting(true);
        setSaveStatus('Menyinkronkan jawaban…');

        const synchronized = await syncLatestResponses();
        if (!synchronized && !timeExpired) {
            submitted.current = false;
            setIsSubmitting(false);
            setSaveStatus('Tersimpan lokal; kirim ulang saat koneksi stabil');
            window.alert('Sebagian jawaban belum berhasil disimpan ke server. Cadangan jawaban tetap aman di browser. Periksa koneksi lalu tekan Selesai kembali.');
            return;
        }

        setSaveStatus(synchronized ? 'Jawaban tersimpan; mengirim…' : 'Mengirim jawaban yang sudah tersimpan…');
        let submissionSucceeded = false;
        router.post(route('attempts.submit', attempt.public_id), {}, {
            onSuccess: () => {
                submissionSucceeded = true;
                window.localStorage.removeItem(storageKey);
                window.localStorage.removeItem(legacyStorageKey);
            },
            onError: () => {
                setSaveStatus('Pengiriman gagal; cadangan tetap aman');
            },
            onFinish: () => {
                if (!submissionSucceeded) submitted.current = false;
                setIsSubmitting(false);
            },
        });
    };

    const totalDurationSeconds = Math.max(1, attempt.assessment.duration_minutes * 60);
    const hours = Math.floor(remaining / 3600).toString().padStart(2, '0');
    const minutes = Math.floor((remaining % 3600) / 60).toString().padStart(2, '0');
    const seconds = (remaining % 60).toString().padStart(2, '0');
    const remainingPercentage = Math.min(100, Math.max(0, (remaining / totalDurationSeconds) * 100));
    const timerState = remaining <= 60 ? 'critical' : remaining <= 300 ? 'warning' : 'normal';
    const timerStyles = {
        normal: {
            card: 'border-slate-200 bg-white',
            icon: 'bg-emerald-100 text-emerald-700',
            label: 'text-slate-500',
            value: 'text-slate-950',
            progress: 'bg-emerald-500',
        },
        warning: {
            card: 'border-amber-300 bg-amber-50',
            icon: 'bg-amber-200 text-amber-800',
            label: 'text-amber-700',
            value: 'text-amber-950',
            progress: 'bg-amber-500',
        },
        critical: {
            card: 'border-rose-300 bg-rose-50',
            icon: 'bg-rose-200 text-rose-800 animate-pulse',
            label: 'text-rose-700',
            value: 'text-rose-950',
            progress: 'bg-rose-500',
        },
    }[timerState];
    const hasStimulus = Boolean(current.stimulus?.trim() || current.illustration_url || current.stimulus_visual);

    return (
        <div className="flex h-[100dvh] min-h-0 flex-col overflow-hidden bg-slate-100">
            <Head title={`Mengerjakan ${attempt.assessment.title}`} />
            <header className="z-10 shrink-0 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-3 px-3 py-2 sm:px-5">
                    <div className="min-w-0 flex-1">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-emerald-600 sm:text-xs">
                            {attempt.assessment.type_label}
                        </p>
                        <h1 className="truncate text-sm font-semibold text-slate-900 sm:text-base">
                            {attempt.assessment.title}
                        </h1>
                        <p role="status" aria-live="polite" className="mt-0.5 flex items-center gap-1.5 text-[10px] font-medium text-slate-500">
                            <span aria-hidden="true" className={`h-1.5 w-1.5 rounded-full ${saveStatus === 'Tersimpan' ? 'bg-emerald-500' : 'bg-amber-500'}`} />
                            {saveStatus}
                        </p>
                    </div>

                    <div className="order-3 mt-2 flex basis-full items-center gap-1.5 overflow-x-auto border-t border-slate-100 pt-2 lg:order-2 lg:mt-0 lg:basis-auto lg:border-0 lg:pt-0" role="toolbar" aria-label="Pengaturan aksesibilitas ujian">
                        <span className="shrink-0 text-[11px] font-semibold text-slate-600">Teks</span>
                        {([90, 100, 115] as TextScale[]).map((scale, index) => (
                            <button
                                key={scale}
                                type="button"
                                aria-label={scale === 90 ? 'Perkecil teks ujian' : scale === 100 ? 'Gunakan ukuran teks normal' : 'Perbesar teks ujian'}
                                aria-pressed={textScale === scale}
                                onClick={() => setTextScale(scale)}
                                className={`min-h-10 min-w-10 shrink-0 rounded-lg border px-2 text-sm font-bold ${textScale === scale ? 'border-indigo-600 bg-indigo-50 text-indigo-800' : 'border-slate-300 bg-white text-slate-700'}`}
                            >
                                {index === 0 ? 'A−' : index === 1 ? 'A' : 'A+'}
                            </button>
                        ))}
                        <button
                            type="button"
                            aria-pressed={highContrast}
                            onClick={() => setHighContrast((value) => !value)}
                            className={`min-h-10 shrink-0 rounded-lg border px-2.5 text-[11px] font-bold ${highContrast ? 'border-amber-500 bg-amber-50 text-amber-900' : 'border-slate-300 bg-white text-slate-700'}`}
                        >
                            ◐ Kontras
                        </button>
                        <span className="hidden shrink-0 text-[11px] text-slate-500 2xl:inline">Alt + ←/→ pindah soal</span>
                    </div>

                    <div className="order-2 flex shrink-0 items-center gap-2 lg:order-3">
                        <ThemeToggle showLabel={false} />
                        <button
                            type="button"
                            aria-label="Aktifkan tampilan layar penuh"
                            onClick={enterFullscreen}
                            className="hidden min-h-10 rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 xl:block"
                        >
                            Layar penuh
                        </button>

                        <div
                            role="timer"
                            aria-label={`Sisa waktu ${hours} jam ${minutes} menit ${seconds} detik`}
                            className={`rounded-xl border px-2.5 py-1.5 shadow-sm transition-colors sm:px-3 ${timerStyles.card}`}
                        >
                            <p className={`text-[8px] font-bold uppercase tracking-[0.12em] ${timerStyles.label}`}>
                                {timerState === 'critical' ? 'Segera selesai' : timerState === 'warning' ? 'Waktu menipis' : 'Sisa waktu'}
                            </p>
                            <div className={`mt-0.5 flex items-baseline font-mono text-base font-black tabular-nums leading-none sm:text-lg ${timerStyles.value}`}>
                                <span>{hours}</span><span className="mx-0.5 opacity-40">:</span><span>{minutes}</span><span className="mx-0.5 opacity-40">:</span><span>{seconds}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div className="h-1 w-full bg-slate-100" aria-hidden="true">
                    <div
                        className={`ml-auto h-full transition-[width,background-color] duration-1000 ease-linear ${timerStyles.progress}`}
                        style={{ width: `${remainingPercentage}%` }}
                    />
                </div>
            </header>

            <div className={`mx-auto grid h-full min-h-0 w-full max-w-7xl flex-1 gap-3 px-3 py-3 sm:px-5 ${attempt.assessment.show_navigation ? 'grid-rows-[auto_minmax(0,1fr)] lg:grid-cols-[200px_minmax(0,1fr)] lg:grid-rows-1' : 'grid-rows-1'}`}>
                {attempt.assessment.show_navigation && <aside aria-label="Navigasi nomor soal" className="min-h-0 rounded-xl border border-slate-200 bg-white p-2 lg:h-full lg:overflow-y-auto lg:p-3">
                    <div className="flex items-center justify-between text-sm"><span className="font-semibold text-slate-900">Navigasi</span><span className="text-slate-500">{answeredCount}/{attempt.questions.length}</span></div>
                    <div className="mt-2 flex gap-2 overflow-x-auto pb-1 lg:grid lg:grid-cols-4 lg:overflow-visible lg:pb-0">
                        {attempt.questions.map((question, index) => {
                            const answered = hasCompleteAnswer(question, responses[question.id]);
                            return <button key={question.id} type="button" aria-label={`Buka soal ${index + 1}${answered ? ', sudah dijawab' : ', belum dijawab'}`} aria-current={index === currentIndex ? 'step' : undefined} onClick={() => setCurrentIndex(index)} className={`aspect-square min-h-11 min-w-11 rounded-lg text-sm font-semibold ${index === currentIndex ? 'bg-slate-900 text-white' : answered ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500'}`}>{index + 1}</button>;
                        })}
                    </div>
                </aside>}

                <main
                    className="h-full min-h-0 select-none overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                    onCopy={preventQuestionContentAction}
                    onCut={preventQuestionContentAction}
                    onContextMenu={preventQuestionContentAction}
                    onDragStart={preventQuestionContentAction}
                >
                    <div className={`grid h-full min-h-0 ${hasStimulus ? 'grid-rows-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:grid-rows-1' : ''}`}>
                        {hasStimulus && (
                            <aside className="min-h-0 overflow-y-auto border-b border-slate-200 bg-slate-50/60 p-4 lg:border-b-0 lg:border-r lg:p-5">
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wider text-indigo-600">Stimulus</p>
                                    {current.illustration_url && <PositionedImage src={current.illustration_url} alt={`Ilustrasi untuk soal ${currentIndex + 1}`} width={current.illustration_display?.width || 800} height={current.illustration_display?.height || 450} zoom={current.illustration_display?.zoom || 1} offsetX={current.illustration_display?.offset_x || 0} offsetY={current.illustration_display?.offset_y || 0} className="mt-3" />}
                                    {current.stimulus_visual && <StimulusVisual visual={current.stimulus_visual} className="mt-3" />}
                                    {current.stimulus && <div className="mt-3 whitespace-pre-wrap text-sm leading-6 text-slate-700">{current.stimulus}</div>}
                                </div>
                            </aside>
                        )}

                        <section aria-labelledby={`question-heading-${current.id}`} className={`flex h-full min-h-0 min-w-0 flex-col p-4 lg:p-5 ${hasStimulus ? '' : 'mx-auto w-full max-w-4xl'}`}>
                            <div className="min-h-0 flex-1 overflow-y-auto pr-1" style={{ zoom: textScale / 100 }}>
                            <p className="text-xs font-semibold text-emerald-600">Soal {currentIndex + 1} dari {attempt.questions.length}</p>
                            <h2 ref={questionHeading} id={`question-heading-${current.id}`} tabIndex={-1} className="mt-2 text-base font-semibold leading-6 text-slate-900 lg:text-lg">{current.prompt}</h2>

                            {current.type === 'category_matrix' && current.matrix ? (
                                <div className="mt-3">
                                    <p className="mb-2 text-xs text-slate-600">Pilih satu jawaban untuk setiap pernyataan.</p>
                                    <div className="space-y-2">
                                        {current.matrix.rows.map((row, rowIndex) => (
                                            <div key={row.id} className="grid gap-2 rounded-lg border border-slate-200 bg-white p-2.5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                                <p className="text-sm leading-5 text-slate-800">
                                                    <span className="mr-1.5 font-semibold text-slate-500">{rowIndex + 1}.</span>
                                                    {row.statement}
                                                </p>
                                                <div role="radiogroup" aria-label={`Pilihan untuk pernyataan ${rowIndex + 1}`} className="flex flex-wrap gap-1.5 sm:justify-end">
                                                    {current.matrix?.columns.map((column) => {
                                                        const selected = responses[current.id]?.matrix_answers?.[row.id] === column.id;
                                                        return (
                                                            <button
                                                                key={column.id}
                                                                type="button"
                                                                role="radio"
                                                                aria-checked={selected}
                                                                aria-label={`${row.statement}: ${column.label}`}
                                                                onClick={() => chooseMatrixAnswer(current, row.id, column.id)}
                                                                className={`inline-flex min-h-10 min-w-[7rem] flex-1 items-center justify-center gap-2 rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition sm:flex-none ${selected ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-blue-400 hover:bg-blue-50'}`}
                                                            >
                                                                <span aria-hidden="true" className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full border text-[10px] ${selected ? 'border-white bg-white text-blue-600' : 'border-slate-400 text-transparent'}`}>✓</span>
                                                                {compactMatrixLabel(column.label)}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : current.type === 'matching' && current.matching ? (
                        <div className="mt-4">
                            <div className="rounded-xl border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-800">
                                {selectedLeft[current.id]
                                    ? 'Sekarang pilih jawaban yang sesuai di lajur kanan.'
                                    : 'Pilih satu pernyataan di lajur kiri, kemudian pilih pasangannya di lajur kanan.'}
                            </div>
                            <div className={`mt-4 grid gap-5 ${hasStimulus ? '2xl:grid-cols-2' : 'md:grid-cols-2'}`}>
                                <section>
                                    <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Pernyataan</h3>
                                    <div className="space-y-3">
                                        {current.matching.left_items.map((item, index) => {
                                            const matched = Boolean(responses[current.id]?.matches?.[item.id]);
                                            const selected = selectedLeft[current.id] === item.id;
                                            const style = matchingStyles[index % matchingStyles.length];
                                            return <button key={item.id} type="button" aria-pressed={selected} aria-label={`Pernyataan ${index + 1}: ${item.content}${matched ? ', sudah dipasangkan' : ''}`} onClick={() => setSelectedLeft((values) => ({ ...values, [current.id]: item.id }))} className={`flex min-h-11 w-full items-start gap-3 rounded-xl border-2 p-4 text-left transition ${matched ? style.card : selected ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-200' : 'border-slate-200 hover:border-indigo-300'}`}><span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ${matched ? style.badge : 'bg-slate-100 text-slate-500'}`}>{index + 1}</span><span className="leading-6 text-slate-800">{item.content}</span></button>;
                                        })}
                                    </div>
                                </section>
                                <section>
                                    <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Pilihan pasangan</h3>
                                    <div className="space-y-3">
                                        {current.matching.right_items.map((item) => {
                                            const matchedLeftId = Object.entries(responses[current.id]?.matches || {}).find(([, rightId]) => rightId === item.id)?.[0];
                                            const matchedIndex = current.matching?.left_items.findIndex((leftItem) => leftItem.id === matchedLeftId) ?? -1;
                                            const style = matchedIndex >= 0 ? matchingStyles[matchedIndex % matchingStyles.length] : null;
                                            return <button key={item.id} type="button" aria-label={`Pilihan pasangan: ${item.content}${matchedIndex >= 0 ? `, terpasang dengan pernyataan ${matchedIndex + 1}` : ''}`} onClick={() => chooseMatch(current, item.id)} className={`flex min-h-11 w-full items-start gap-3 rounded-xl border-2 p-4 text-left transition ${style ? style.card : selectedLeft[current.id] ? 'border-slate-200 hover:border-indigo-400 hover:bg-indigo-50' : 'border-slate-200'}`}><span className={`flex h-7 min-w-7 shrink-0 items-center justify-center rounded-full px-2 text-xs font-bold ${style ? style.badge : 'bg-slate-100 text-slate-400'}`}>{matchedIndex >= 0 ? matchedIndex + 1 : '○'}</span><span className="leading-6 text-slate-800">{item.content}</span></button>;
                                        })}
                                    </div>
                                </section>
                            </div>
                            {Object.keys(responses[current.id]?.matches || {}).length > 0 && <button type="button" onClick={() => updateResponse(current, { matches: {} })} className="mt-4 text-sm font-semibold text-rose-600">Reset semua pasangan</button>}
                        </div>
                            ) : current.type === 'short_answer' ? (
                        <textarea
                            value={responses[current.id]?.text || ''}
                            aria-label={`Jawaban untuk soal ${currentIndex + 1}`}
                            onChange={(event) => setResponses((values) => ({ ...values, [current.id]: { text: event.target.value } }))}
                            onBlur={() => void save(current.id, responses[current.id] || {})}
                            rows={3}
                            placeholder="Tulis jawabanmu"
                            className="mt-4 block w-full select-text rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                        />
                            ) : (
                        <div className="mt-4 space-y-2" role={current.type === 'single_choice' ? 'radiogroup' : 'group'} aria-label={`Pilihan jawaban soal ${currentIndex + 1}`}>
                            {current.options.map((option, optionIndex) => {
                                const selected = responses[current.id]?.option_ids?.includes(option.id);
                                const noSingleChoiceSelected = current.type === 'single_choice' && !(responses[current.id]?.option_ids?.length);
                                return <button id={`question-${current.id}-option-${optionIndex}`} key={option.id} type="button" role={current.type === 'single_choice' ? 'radio' : 'checkbox'} aria-checked={Boolean(selected)} aria-label={`Pilihan ${option.label}: ${option.content}`} tabIndex={current.type === 'single_choice' ? (selected || (noSingleChoiceSelected && optionIndex === 0) ? 0 : -1) : 0} onKeyDown={(event) => navigateSingleChoice(event, current, optionIndex)} onClick={() => chooseOption(current, option.id)} className={`flex min-h-11 w-full items-center gap-3 rounded-xl border p-3 text-left transition ${selected ? 'border-emerald-500 bg-emerald-50 ring-1 ring-emerald-500' : 'border-slate-200 hover:border-slate-300'}`}>{current.type === 'multiple_choice' ? <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded border-2 text-sm font-bold ${selected ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 bg-white text-transparent'}`}>✓</span> : <span className="font-semibold text-slate-600">{option.label}</span>}<span className="text-slate-800">{option.content}</span></button>;
                            })}
                        </div>
                            )}

                            </div>

                            <div className="mt-3 flex shrink-0 items-center justify-between border-t border-slate-100 pt-3">
                                <button type="button" aria-keyshortcuts="Alt+ArrowLeft" disabled={currentIndex === 0} onClick={() => setCurrentIndex((value) => value - 1)} className="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 disabled:opacity-30">Sebelumnya</button>
                                {currentIndex < attempt.questions.length - 1 ? <button type="button" aria-keyshortcuts="Alt+ArrowRight" onClick={() => setCurrentIndex((value) => value + 1)} className="min-h-11 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Berikutnya</button> : <button type="button" disabled={isSubmitting} onClick={() => window.confirm('Yakin ingin mengakhiri try out?') && void submitAttempt()} className="min-h-11 rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white disabled:cursor-wait disabled:opacity-60">{isSubmitting ? 'Menyimpan jawaban…' : 'Selesai dan kirim'}</button>}
                            </div>
                        </section>
                    </div>
                </main>
            </div>
        </div>
    );
}
