import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

type ResponseValue = { option_ids?: number[]; text?: string; matches?: Record<string, string>; matrix_answers?: Record<string, string> };
type MatchingItem = { id: string; content: string };
type ExamQuestion = {
    id: number;
    type: 'single_choice' | 'multiple_choice' | 'short_answer' | 'matching' | 'category_matrix';
    title?: string;
    stimulus?: string;
    illustration_url?: string;
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

export default function Show({ attempt }: { attempt: Attempt }) {
    const storageKey = `anbk-attempt-${attempt.public_id}`;
    const initialResponses = Object.fromEntries(attempt.questions.map((question) => [question.id, question.response || {}]));
    const [responses, setResponses] = useState<Record<number, ResponseValue>>(() => {
        const local = window.localStorage.getItem(storageKey);
        return local ? { ...initialResponses, ...JSON.parse(local) } : initialResponses;
    });
    const [currentIndex, setCurrentIndex] = useState(0);
    const [remaining, setRemaining] = useState(attempt.remaining_seconds);
    const [saveStatus, setSaveStatus] = useState('Tersimpan');
    const [selectedLeft, setSelectedLeft] = useState<Record<number, string | undefined>>({});
    const submitted = useRef(false);
    const usedFullscreen = useRef(false);
    const current = attempt.questions[currentIndex];
    const answeredCount = useMemo(() => attempt.questions.filter((question) => hasCompleteAnswer(question, responses[question.id])).length, [attempt.questions, responses]);

    useEffect(() => {
        const timer = window.setInterval(() => setRemaining((value) => Math.max(0, value - 1)), 1000);
        return () => window.clearInterval(timer);
    }, []);

    useEffect(() => {
        if (remaining === 0 && !submitted.current) submitAttempt(true);
    }, [remaining]);

    useEffect(() => {
        window.localStorage.setItem(storageKey, JSON.stringify(responses));
    }, [responses]);

    useEffect(() => {
        const sync = () => {
            void recordEvent('connection_restored');
            Object.entries(responses).forEach(([questionId, response]) => save(Number(questionId), response));
        };
        const offline = () => void recordEvent('connection_lost');
        window.addEventListener('online', sync);
        window.addEventListener('offline', offline);
        return () => {
            window.removeEventListener('online', sync);
            window.removeEventListener('offline', offline);
        };
    }, [responses]);

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

    const save = async (questionId: number, response: ResponseValue) => {
        setSaveStatus('Menyimpan…');
        try {
            await window.axios.put(route('attempts.answers.update', [attempt.public_id, questionId]), response);
            setSaveStatus('Tersimpan');
        } catch {
            setSaveStatus('Tersimpan lokal; menunggu koneksi');
            void recordEvent('autosave_failed', { question_id: questionId });
        }
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

    const submitAttempt = (timeExpired = false) => {
        if (submitted.current) return;
        if (attempt.assessment.require_all_answers && !timeExpired && answeredCount < attempt.questions.length) {
            window.alert(`Masih ada ${attempt.questions.length - answeredCount} soal yang belum dijawab.`);
            return;
        }
        submitted.current = true;
        window.localStorage.removeItem(storageKey);
        router.post(route('attempts.submit', attempt.public_id));
    };

    const minutes = Math.floor(remaining / 60).toString().padStart(2, '0');
    const seconds = (remaining % 60).toString().padStart(2, '0');
    const hasStimulus = Boolean(current.stimulus?.trim() || current.illustration_url);

    return (
        <div className="min-h-screen bg-slate-100">
            <Head title={`Mengerjakan ${attempt.assessment.title}`} />
            <header className="sticky top-0 z-10 border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6">
                    <div><p className="text-xs font-semibold uppercase tracking-wider text-emerald-600">{attempt.assessment.type_label}</p><h1 className="font-semibold text-slate-900">{attempt.assessment.title}</h1></div>
                    <div className="flex items-center gap-4"><button onClick={enterFullscreen} className="hidden rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-600 sm:block">Layar penuh</button><div className="text-right"><p className={`font-mono text-xl font-bold ${remaining < 300 ? 'text-rose-600' : 'text-slate-900'}`}>{minutes}:{seconds}</p><p className="text-xs text-slate-500">{saveStatus}</p></div></div>
                </div>
            </header>

            <div className={`mx-auto grid max-w-7xl gap-6 px-4 py-6 sm:px-6 ${attempt.assessment.show_navigation ? 'lg:grid-cols-[220px_1fr]' : ''}`}>
                {attempt.assessment.show_navigation && <aside className="h-fit rounded-2xl border border-slate-200 bg-white p-4">
                    <div className="flex items-center justify-between text-sm"><span className="font-semibold text-slate-900">Navigasi</span><span className="text-slate-500">{answeredCount}/{attempt.questions.length}</span></div>
                    <div className="mt-4 grid grid-cols-5 gap-2 lg:grid-cols-4">
                        {attempt.questions.map((question, index) => {
                            const answered = hasCompleteAnswer(question, responses[question.id]);
                            return <button key={question.id} onClick={() => setCurrentIndex(index)} className={`aspect-square rounded-lg text-sm font-semibold ${index === currentIndex ? 'bg-slate-900 text-white' : answered ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500'}`}>{index + 1}</button>;
                        })}
                    </div>
                </aside>}

                <main className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className={`grid ${hasStimulus ? 'lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]' : ''}`}>
                        {hasStimulus && (
                            <aside className="border-b border-slate-200 bg-slate-50/60 p-6 sm:p-8 lg:border-b-0 lg:border-r">
                                <div className="lg:sticky lg:top-24">
                                    <p className="text-xs font-semibold uppercase tracking-wider text-indigo-600">Stimulus</p>
                                    {current.title && <h2 className="mt-2 text-xl font-bold leading-8 text-slate-900">{current.title}</h2>}
                                    {current.illustration_url && <img src={current.illustration_url} alt="Ilustrasi stimulus" className="mt-5 aspect-video w-full rounded-xl border border-slate-200 bg-white object-contain" />}
                                    {current.stimulus && <div className="mt-5 whitespace-pre-wrap text-base leading-8 text-slate-700">{current.stimulus}</div>}
                                </div>
                            </aside>
                        )}

                        <section className={`min-w-0 p-6 sm:p-8 ${hasStimulus ? '' : 'mx-auto w-full max-w-4xl'}`}>
                            <p className="text-sm font-semibold text-emerald-600">Soal {currentIndex + 1} dari {attempt.questions.length}</p>
                            <h2 className="mt-5 text-lg font-semibold leading-8 text-slate-900">{current.prompt}</h2>

                            {current.type === 'category_matrix' && current.matrix ? (
                                <div className="mt-6">
                                    <p className="mb-4 text-sm text-slate-600">Pilih satu kategori untuk setiap pernyataan.</p>
                                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                                        <table className="w-full min-w-[560px] text-sm">
                                            <thead className="bg-slate-100 text-slate-700">
                                                <tr>
                                                    <th className="p-4 text-left font-semibold">Pernyataan</th>
                                                    {current.matrix.columns.map((column) => <th key={column.id} className="min-w-28 p-4 text-center font-semibold">{column.label}</th>)}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {current.matrix.rows.map((row) => (
                                                    <tr key={row.id} className="border-t border-slate-200">
                                                        <td className="p-4 leading-6 text-slate-800">{row.statement}</td>
                                                        {current.matrix?.columns.map((column) => {
                                                            const selected = responses[current.id]?.matrix_answers?.[row.id] === column.id;
                                                            return <td key={column.id} className="p-4 text-center"><button type="button" aria-label={`${row.statement}: ${column.label}`} aria-pressed={selected} onClick={() => chooseMatrixAnswer(current, row.id, column.id)} className={`inline-flex h-9 w-9 items-center justify-center rounded-full border-2 transition ${selected ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white text-transparent hover:border-blue-400'}`}>✓</button></td>;
                                                        })}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            ) : current.type === 'matching' && current.matching ? (
                        <div className="mt-6">
                            <div className="rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-800">
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
                                            return <button key={item.id} type="button" onClick={() => setSelectedLeft((values) => ({ ...values, [current.id]: item.id }))} className={`flex w-full items-start gap-3 rounded-xl border-2 p-4 text-left transition ${matched ? style.card : selected ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-200' : 'border-slate-200 hover:border-indigo-300'}`}><span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ${matched ? style.badge : 'bg-slate-100 text-slate-500'}`}>{index + 1}</span><span className="leading-6 text-slate-800">{item.content}</span></button>;
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
                                            return <button key={item.id} type="button" onClick={() => chooseMatch(current, item.id)} className={`flex w-full items-start gap-3 rounded-xl border-2 p-4 text-left transition ${style ? style.card : selectedLeft[current.id] ? 'border-slate-200 hover:border-indigo-400 hover:bg-indigo-50' : 'border-slate-200'}`}><span className={`flex h-7 min-w-7 shrink-0 items-center justify-center rounded-full px-2 text-xs font-bold ${style ? style.badge : 'bg-slate-100 text-slate-400'}`}>{matchedIndex >= 0 ? matchedIndex + 1 : '○'}</span><span className="leading-6 text-slate-800">{item.content}</span></button>;
                                        })}
                                    </div>
                                </section>
                            </div>
                            {Object.keys(responses[current.id]?.matches || {}).length > 0 && <button type="button" onClick={() => updateResponse(current, { matches: {} })} className="mt-4 text-sm font-semibold text-rose-600">Reset semua pasangan</button>}
                        </div>
                            ) : current.type === 'short_answer' ? (
                        <textarea
                            value={responses[current.id]?.text || ''}
                            onChange={(event) => setResponses((values) => ({ ...values, [current.id]: { text: event.target.value } }))}
                            onBlur={() => void save(current.id, responses[current.id] || {})}
                            rows={3}
                            placeholder="Tulis jawabanmu"
                            className="mt-6 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                        />
                            ) : (
                        <div className="mt-6 space-y-3">
                            {current.options.map((option) => {
                                const selected = responses[current.id]?.option_ids?.includes(option.id);
                                return <button key={option.id} onClick={() => chooseOption(current, option.id)} className={`flex w-full gap-4 rounded-xl border p-4 text-left transition ${selected ? 'border-emerald-500 bg-emerald-50 ring-1 ring-emerald-500' : 'border-slate-200 hover:border-slate-300'}`}><span className="font-semibold text-slate-600">{option.label}</span><span className="text-slate-800">{option.content}</span></button>;
                            })}
                        </div>
                            )}

                            <div className="mt-8 flex items-center justify-between border-t border-slate-100 pt-6">
                                <button disabled={currentIndex === 0} onClick={() => setCurrentIndex((value) => value - 1)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 disabled:opacity-30">Sebelumnya</button>
                                {currentIndex < attempt.questions.length - 1 ? <button onClick={() => setCurrentIndex((value) => value + 1)} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Berikutnya</button> : <button onClick={() => window.confirm('Yakin ingin mengakhiri try out?') && submitAttempt()} className="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white">Selesai dan kirim</button>}
                            </div>
                        </section>
                    </div>
                </main>
            </div>
        </div>
    );
}
