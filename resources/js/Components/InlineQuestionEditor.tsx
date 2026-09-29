import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Option = { id: number; label: string; content: string; is_correct: boolean };
type MatchingPair = { left_id: string; left: string; right_id: string; right: string };
type MatchingDistractor = { id: string; content: string };
type MatrixColumn = { id: string; label: string };
type MatrixRow = { id: string; statement: string; correct_column_id: string };

export type InlineEditableQuestion = {
    id: number;
    type: 'single_choice' | 'multiple_choice' | 'short_answer' | 'matching' | 'category_matrix';
    difficulty: number;
    stimulus?: string;
    prompt: string;
    explanation?: string;
    question_blueprint?: { id: number; code: string; name: string } | null;
    options: Option[];
    metadata?: {
        accepted_answers?: string[];
        matching_pairs?: MatchingPair[];
        matching_distractors?: MatchingDistractor[];
        matrix_columns?: MatrixColumn[];
        matrix_rows?: MatrixRow[];
    };
};

type EditorData = {
    difficulty: number;
    stimulus: string;
    prompt: string;
    explanation: string;
    options: Option[];
    accepted_answers: string[];
    matching_pairs: MatchingPair[];
    matching_distractors: MatchingDistractor[];
    matrix_columns: MatrixColumn[];
    matrix_rows: MatrixRow[];
};

export default function InlineQuestionEditor({
    generationId,
    question,
    showStimulus,
    onCancel,
}: {
    generationId: number;
    question: InlineEditableQuestion;
    showStimulus: boolean;
    onCancel: () => void;
}) {
    const { data, setData, put, processing, errors } = useForm<EditorData>({
        difficulty: question.difficulty,
        stimulus: question.stimulus || '',
        prompt: question.prompt,
        explanation: question.explanation || '',
        options: question.options.map((option) => ({ ...option })),
        accepted_answers: question.metadata?.accepted_answers?.length ? [...question.metadata.accepted_answers] : [''],
        matching_pairs: question.metadata?.matching_pairs?.map((pair) => ({ ...pair })) || [],
        matching_distractors: question.metadata?.matching_distractors?.map((item) => ({ ...item })) || [],
        matrix_columns: question.metadata?.matrix_columns?.map((column) => ({ ...column })) || [],
        matrix_rows: question.metadata?.matrix_rows?.map((row) => ({ ...row })) || [],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('generated-questions.inline-update', { generation: generationId, question: question.id }), {
            preserveScroll: true,
            onSuccess: onCancel,
        });
    };

    const updateOption = (index: number, field: keyof Option, value: string | boolean) => {
        const options = data.options.map((option, optionIndex) => {
            if (field === 'is_correct' && value === true && question.type === 'single_choice') {
                return { ...option, is_correct: optionIndex === index };
            }

            return optionIndex === index ? { ...option, [field]: value } : option;
        });
        setData('options', options);
    };

    const firstError = Object.values(errors)[0];

    return (
        <form onSubmit={submit} className="mt-5 space-y-5 rounded-xl border-2 border-indigo-200 bg-indigo-50/60 p-4 sm:p-5">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h4 className="font-semibold text-indigo-950">Edit langsung</h4>
                    <p className="text-xs text-indigo-700">Perubahan disimpan tanpa meninggalkan halaman ini.</p>
                </div>
                <button type="button" onClick={onCancel} className="rounded-lg border border-indigo-200 bg-white px-3 py-1.5 text-sm font-semibold text-indigo-700">Tutup</button>
            </div>

            {showStimulus && (
                <label className="block text-sm font-medium text-slate-700">
                    Stimulus <span className="font-normal text-slate-500">(opsional)</span>
                    <textarea value={data.stimulus} onChange={(event) => setData('stimulus', event.target.value)} rows={3} className="mt-1 block w-full rounded-lg border-slate-300 bg-white focus:border-indigo-500 focus:ring-indigo-500" />
                </label>
            )}
            {question.question_blueprint && (
                <div className="flex items-center justify-between rounded-xl border border-indigo-200 bg-white/80 p-3.5 shadow-sm">
                    <div>
                        <span className="text-[11px] font-bold uppercase tracking-wide text-indigo-700">Tipe Soal</span>
                        <div className="mt-0.5 text-sm font-semibold text-slate-900">{question.question_blueprint.name}</div>
                    </div>
                    <a
                        href={route('question-types.edit', question.question_blueprint.id)}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-700 shadow-sm transition hover:bg-indigo-100 hover:text-indigo-900"
                        title="Customize tipe soal ini di tab baru"
                    >
                        <svg className="h-3.5 w-3.5 text-indigo-600" viewBox="0 0 20 20" fill="currentColor">
                            <path d="m13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
                        </svg>
                        <span>Customize</span>
                    </a>
                </div>
            )}

            <label className="block text-sm font-medium text-slate-700">
                Level soal
                <select value={data.difficulty} onChange={(event) => setData('difficulty', Number(event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300 bg-white focus:border-indigo-500 focus:ring-indigo-500">
                    <option value={1}>Level 1 · Mudah</option>
                    <option value={2}>Level 2 · Sedang</option>
                    <option value={3}>Level 3 · Sulit</option>
                </select>
            </label>

            <label className="block text-sm font-medium text-slate-700">
                Pertanyaan
                <textarea value={data.prompt} onChange={(event) => setData('prompt', event.target.value)} rows={3} className="mt-1 block w-full rounded-lg border-slate-300 bg-white focus:border-indigo-500 focus:ring-indigo-500" />
            </label>

            <div>
                <h5 className="text-sm font-semibold text-slate-800">Jawaban</h5>
                {question.type === 'short_answer' ? (
                    <div className="mt-3 space-y-2">
                        {data.accepted_answers.map((answer, index) => (
                            <div key={index} className="flex gap-2">
                                <input value={answer} onChange={(event) => setData('accepted_answers', data.accepted_answers.map((item, answerIndex) => answerIndex === index ? event.target.value : item))} className="min-w-0 flex-1 rounded-lg border-slate-300 bg-white focus:border-indigo-500 focus:ring-indigo-500" />
                                <button type="button" disabled={data.accepted_answers.length === 1} onClick={() => setData('accepted_answers', data.accepted_answers.filter((_, answerIndex) => answerIndex !== index))} className="rounded-lg border border-rose-200 bg-white px-3 text-rose-600 disabled:opacity-30">×</button>
                            </div>
                        ))}
                        <button type="button" onClick={() => setData('accepted_answers', [...data.accepted_answers, ''])} className="text-sm font-semibold text-indigo-700">+ Tambah alternatif jawaban</button>
                    </div>
                ) : question.type === 'matching' ? (
                    <div className="mt-3 space-y-3">
                        {data.matching_pairs.map((pair, index) => (
                            <div key={pair.left_id} className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_28px_minmax(0,1fr)] sm:items-center">
                                <textarea value={pair.left} onChange={(event) => setData('matching_pairs', data.matching_pairs.map((item, pairIndex) => pairIndex === index ? { ...item, left: event.target.value } : item))} rows={2} className="rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                <span className="hidden text-center text-indigo-500 sm:block">→</span>
                                <textarea value={pair.right} onChange={(event) => setData('matching_pairs', data.matching_pairs.map((item, pairIndex) => pairIndex === index ? { ...item, right: event.target.value } : item))} rows={2} className="rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                        ))}
                        {data.matching_distractors.map((item, index) => (
                            <label key={item.id} className="block text-xs font-medium text-slate-600">
                                Distraktor {index + 1}
                                <input value={item.content} onChange={(event) => setData('matching_distractors', data.matching_distractors.map((distractor, distractorIndex) => distractorIndex === index ? { ...distractor, content: event.target.value } : distractor))} className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </label>
                        ))}
                    </div>
                ) : question.type === 'category_matrix' ? (
                    <div className="mt-3 space-y-4">
                        <div className="grid gap-2 sm:grid-cols-2">
                            {data.matrix_columns.map((column, index) => (
                                <label key={column.id} className="text-xs font-medium text-slate-600">
                                    Kategori {index + 1}
                                    <input value={column.label} onChange={(event) => setData('matrix_columns', data.matrix_columns.map((item, columnIndex) => columnIndex === index ? { ...item, label: event.target.value } : item))} className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                </label>
                            ))}
                        </div>
                        {data.matrix_rows.map((row, index) => (
                            <div key={row.id} className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_220px]">
                                <textarea value={row.statement} onChange={(event) => setData('matrix_rows', data.matrix_rows.map((item, rowIndex) => rowIndex === index ? { ...item, statement: event.target.value } : item))} rows={2} className="rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                <select value={row.correct_column_id} onChange={(event) => setData('matrix_rows', data.matrix_rows.map((item, rowIndex) => rowIndex === index ? { ...item, correct_column_id: event.target.value } : item))} className="rounded-lg border-slate-300 bg-white text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    {data.matrix_columns.map((column) => <option key={column.id} value={column.id}>{column.label}</option>)}
                                </select>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="mt-3 space-y-2">
                        {data.options.map((option, index) => (
                            <div key={index} className="flex items-center gap-3">
                                <input type={question.type === 'single_choice' ? 'radio' : 'checkbox'} name={`correct-option-${question.id}`} checked={option.is_correct} onChange={(event) => updateOption(index, 'is_correct', event.target.checked)} className="border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                {question.type === 'single_choice' && <span className="w-5 text-sm font-semibold text-slate-500">{String.fromCharCode(65 + index)}</span>}
                                <input value={option.content} onChange={(event) => updateOption(index, 'content', event.target.value)} className="min-w-0 flex-1 rounded-lg border-slate-300 bg-white focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <label className="block text-sm font-medium text-slate-700">
                Pembahasan
                <textarea value={data.explanation} onChange={(event) => setData('explanation', event.target.value)} rows={4} className="mt-1 block w-full rounded-lg border-slate-300 bg-white focus:border-indigo-500 focus:ring-indigo-500" />
            </label>

            {firstError && <p className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{firstError}</p>}

            <div className="flex justify-end gap-2">
                <button type="button" onClick={onCancel} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Batal</button>
                <button disabled={processing} className="rounded-lg bg-indigo-700 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-600 disabled:opacity-50">{processing ? 'Menyimpan...' : 'Simpan Perubahan'}</button>
            </div>
        </form>
    );
}
