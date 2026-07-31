import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Competency = {
    id: number;
    code: string;
    domain: string;
    name: string;
    grade_level: number;
};

type MatchingPair = { left_id?: string; left: string; right_id?: string; right: string };
type MatchingDistractor = { id?: string; content: string };
type MatrixColumn = { id?: string; label: string };
type MatrixRow = { id?: string; statement: string; correct_column_index: number };

type QuestionForm = {
    competency_id: string;
    type: 'single_choice' | 'multiple_choice' | 'short_answer' | 'matching' | 'category_matrix';
    title: string;
    stimulus: string;
    prompt: string;
    explanation: string;
    difficulty: number;
    grade_level: number;
    cognitive_level: string;
    options: { content: string; is_correct: boolean }[];
    accepted_answers: string[];
    matching_pairs: MatchingPair[];
    matching_distractors: MatchingDistractor[];
    matrix_columns: MatrixColumn[];
    matrix_rows: MatrixRow[];
};

type ExistingQuestion = {
    id: number;
    competency_id: number;
    type: QuestionForm['type'];
    title?: string;
    stimulus?: string;
    prompt: string;
    explanation?: string;
    difficulty: number;
    grade_level: number;
    cognitive_level?: string;
    options: { content: string; is_correct: boolean }[];
    metadata?: {
        accepted_answers?: string[];
        matching_pairs?: MatchingPair[];
        matching_distractors?: MatchingDistractor[];
        matrix_columns?: MatrixColumn[];
        matrix_rows?: { id: string; statement: string; correct_column_id: string }[];
    };
};

export default function Create({ competencies, question }: { competencies: Competency[]; question?: ExistingQuestion }) {
    const defaultOptions = [
            { content: '', is_correct: true },
            { content: '', is_correct: false },
            { content: '', is_correct: false },
            { content: '', is_correct: false },
        ];
    const initialMatrixColumns = question?.metadata?.matrix_columns?.length ? question.metadata.matrix_columns : [
        { label: 'Ya' },
        { label: 'Tidak' },
    ];
    const initialMatrixRows = question?.metadata?.matrix_rows?.length ? question.metadata.matrix_rows.map((row) => ({
        id: row.id,
        statement: row.statement,
        correct_column_index: Math.max(0, initialMatrixColumns.findIndex((column) => column.id === row.correct_column_id)),
    })) : [
        { statement: '', correct_column_index: 0 },
        { statement: '', correct_column_index: 1 },
    ];
    const { data, setData, post, put, processing, errors } = useForm<QuestionForm>({
        competency_id: question ? String(question.competency_id) : '',
        type: question?.type || 'single_choice',
        title: question?.title || '',
        stimulus: question?.stimulus || '',
        prompt: question?.prompt || '',
        explanation: question?.explanation || '',
        difficulty: question?.difficulty || 1,
        grade_level: question?.grade_level || 5,
        cognitive_level: question?.cognitive_level || '',
        options: question?.options.length ? question.options.map((option) => ({ content: option.content, is_correct: option.is_correct })) : defaultOptions,
        accepted_answers: question?.metadata?.accepted_answers?.length ? question.metadata.accepted_answers : [''],
        matching_pairs: question?.metadata?.matching_pairs?.length ? question.metadata.matching_pairs : [
            { left: '', right: '' },
            { left: '', right: '' },
            { left: '', right: '' },
        ],
        matching_distractors: question?.metadata?.matching_distractors || [],
        matrix_columns: initialMatrixColumns,
        matrix_rows: initialMatrixRows,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (question) {
            put(route('questions.update', question.id));
        } else {
            post(route('questions.store'));
        }
    };

    const updateOption = (index: number, field: 'content' | 'is_correct', value: string | boolean) => {
        const options = [...data.options];
        options[index] = { ...options[index], [field]: value };
        if (field === 'is_correct' && value && data.type === 'single_choice') {
            options.forEach((option, optionIndex) => {
                option.is_correct = optionIndex === index;
            });
        }
        setData('options', options);
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-sm font-medium text-emerald-600">Bank Soal</p>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900">{question ? 'Edit Soal' : 'Buat Soal'}</h1>
                </div>
            }
        >
            <Head title={question ? 'Edit Soal' : 'Buat Soal'} />
            <form onSubmit={submit} className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6">
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Klasifikasi</h2>
                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
                        <label className="text-sm font-medium text-slate-700">
                            Kompetensi
                            <select
                                value={data.competency_id}
                                onChange={(event) => {
                                    const competency = competencies.find((item) => item.id === Number(event.target.value));
                                    setData((current) => ({ ...current, competency_id: event.target.value, grade_level: competency?.grade_level || current.grade_level }));
                                }}
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option value="">Pilih kompetensi</option>
                                {competencies.map((competency) => (
                                    <option key={competency.id} value={competency.id}>
                                        Kelas {competency.grade_level} · {competency.code} · {competency.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.competency_id} className="mt-1" />
                        </label>
                        <label className="text-sm font-medium text-slate-700">
                            Bentuk soal
                            <select
                                value={data.type}
                                onChange={(event) => setData('type', event.target.value as QuestionForm['type'])}
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option value="single_choice">Pilihan tunggal</option>
                                <option value="multiple_choice">Pilihan kompleks</option>
                                <option value="short_answer">Isian singkat</option>
                                <option value="matching">Menjodohkan</option>
                                <option value="category_matrix">Pilihan kategori (tabel)</option>
                            </select>
                        </label>
                        <label className="text-sm font-medium text-slate-700">
                            Tingkat kesulitan
                            <select
                                value={data.difficulty}
                                onChange={(event) => setData('difficulty', Number(event.target.value))}
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option value={1}>Mudah</option>
                                <option value={2}>Sedang</option>
                                <option value={3}>Sulit</option>
                            </select>
                        </label>
                        <label className="text-sm font-medium text-slate-700">
                            Level kognitif
                            <input
                                value={data.cognitive_level}
                                onChange={(event) => setData('cognitive_level', event.target.value)}
                                placeholder="Contoh: interpretasi dan integrasi"
                                className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                            />
                        </label>
                    </div>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Isi soal</h2>
                    <div className="mt-4 space-y-4">
                        <label className="block text-sm font-medium text-slate-700">
                            Judul internal
                            <input value={data.title} onChange={(event) => setData('title', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Stimulus atau cerita <span className="font-normal text-slate-500">(opsional)</span>
                            <textarea value={data.stimulus} onChange={(event) => setData('stimulus', event.target.value)} rows={5} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                            <span className="mt-1 block text-xs font-normal text-slate-500">Kosongkan jika soal dapat dijawab tanpa teks, gambar, tabel, atau cerita pendamping.</span>
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Pertanyaan
                            <textarea value={data.prompt} onChange={(event) => setData('prompt', event.target.value)} rows={3} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                            <InputError message={errors.prompt} className="mt-1" />
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Pembahasan
                            <textarea value={data.explanation} onChange={(event) => setData('explanation', event.target.value)} rows={3} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                        </label>
                    </div>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Kunci jawaban</h2>
                    {data.type === 'short_answer' ? (
                        <div className="mt-4 space-y-3">
                            {data.accepted_answers.map((answer, index) => (
                                <input
                                    key={index}
                                    value={answer}
                                    onChange={(event) => {
                                        const answers = [...data.accepted_answers];
                                        answers[index] = event.target.value;
                                        setData('accepted_answers', answers);
                                    }}
                                    placeholder={`Jawaban diterima ${index + 1}`}
                                    className="block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                                />
                            ))}
                            <button type="button" onClick={() => setData('accepted_answers', [...data.accepted_answers, ''])} className="text-sm font-semibold text-emerald-700">+ Tambah alternatif jawaban</button>
                            <InputError message={errors.accepted_answers} />
                        </div>
                    ) : data.type === 'matching' ? (
                        <div className="mt-4 space-y-5">
                            <div className="rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm leading-6 text-indigo-800">
                                Isi pasangan yang benar pada setiap baris. Murid akan melihat lajur kanan terpisah dan menjodohkannya dengan lajur kiri.
                            </div>
                            <div className="space-y-3">
                                <div className="hidden grid-cols-[1fr_32px_1fr_40px] gap-3 px-1 text-xs font-semibold uppercase tracking-wide text-slate-500 sm:grid">
                                    <span>Lajur kiri</span><span /><span>Pasangan benar di lajur kanan</span><span />
                                </div>
                                {data.matching_pairs.map((pair, index) => (
                                    <div key={pair.left_id || index} className="grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[1fr_32px_1fr_40px] sm:items-center">
                                        <textarea
                                            value={pair.left}
                                            onChange={(event) => {
                                                const pairs = [...data.matching_pairs];
                                                pairs[index] = { ...pairs[index], left: event.target.value };
                                                setData('matching_pairs', pairs);
                                            }}
                                            rows={2}
                                            placeholder={`Pernyataan ${index + 1}`}
                                            className="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                                        />
                                        <span className="hidden text-center text-slate-400 sm:block">→</span>
                                        <textarea
                                            value={pair.right}
                                            onChange={(event) => {
                                                const pairs = [...data.matching_pairs];
                                                pairs[index] = { ...pairs[index], right: event.target.value };
                                                setData('matching_pairs', pairs);
                                            }}
                                            rows={2}
                                            placeholder={`Jawaban ${index + 1}`}
                                            className="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                                        />
                                        <button
                                            type="button"
                                            disabled={data.matching_pairs.length <= 2}
                                            onClick={() => setData('matching_pairs', data.matching_pairs.filter((_, pairIndex) => pairIndex !== index))}
                                            className="rounded-lg border border-rose-200 px-2 py-2 text-sm font-semibold text-rose-600 disabled:opacity-30"
                                        >
                                            ×
                                        </button>
                                    </div>
                                ))}
                                <button
                                    type="button"
                                    disabled={data.matching_pairs.length >= 8}
                                    onClick={() => setData('matching_pairs', [...data.matching_pairs, { left: '', right: '' }])}
                                    className="text-sm font-semibold text-emerald-700 disabled:opacity-40"
                                >
                                    + Tambah pasangan
                                </button>
                                <InputError message={errors.matching_pairs} />
                            </div>

                            <div className="border-t border-slate-100 pt-5">
                                <h3 className="text-sm font-semibold text-slate-800">Pilihan kanan tambahan <span className="font-normal text-slate-500">(opsional/distraktor)</span></h3>
                                <div className="mt-3 space-y-3">
                                    {data.matching_distractors.map((distractor, index) => (
                                        <div key={distractor.id || index} className="flex gap-3">
                                            <input
                                                value={distractor.content}
                                                onChange={(event) => {
                                                    const distractors = [...data.matching_distractors];
                                                    distractors[index] = { ...distractors[index], content: event.target.value };
                                                    setData('matching_distractors', distractors);
                                                }}
                                                placeholder={`Distraktor ${index + 1}`}
                                                className="min-w-0 flex-1 rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                                            />
                                            <button type="button" onClick={() => setData('matching_distractors', data.matching_distractors.filter((_, distractorIndex) => distractorIndex !== index))} className="rounded-lg border border-rose-200 px-3 text-sm font-semibold text-rose-600">×</button>
                                        </div>
                                    ))}
                                    <button
                                        type="button"
                                        disabled={data.matching_distractors.length >= 4}
                                        onClick={() => setData('matching_distractors', [...data.matching_distractors, { content: '' }])}
                                        className="text-sm font-semibold text-indigo-700 disabled:opacity-40"
                                    >
                                        + Tambah distraktor
                                    </button>
                                    <InputError message={errors.matching_distractors} />
                                </div>
                            </div>
                        </div>
                    ) : data.type === 'category_matrix' ? (
                        <div className="mt-4 space-y-5">
                            <div className="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-800">
                                Buat 2–4 kategori sebagai kolom, lalu tentukan satu kategori benar untuk setiap pernyataan.
                            </div>

                            <div>
                                <h3 className="text-sm font-semibold text-slate-800">Kategori jawaban</h3>
                                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                    {data.matrix_columns.map((column, index) => (
                                        <div key={column.id || index} className="flex gap-2">
                                            <input
                                                value={column.label}
                                                onChange={(event) => {
                                                    const columns = [...data.matrix_columns];
                                                    columns[index] = { ...columns[index], label: event.target.value };
                                                    setData('matrix_columns', columns);
                                                }}
                                                placeholder={`Kategori ${index + 1}`}
                                                className="min-w-0 flex-1 rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"
                                            />
                                            <button
                                                type="button"
                                                disabled={data.matrix_columns.length <= 2}
                                                onClick={() => setData((current) => ({
                                                    ...current,
                                                    matrix_columns: current.matrix_columns.filter((_, columnIndex) => columnIndex !== index),
                                                    matrix_rows: current.matrix_rows.map((row) => ({
                                                        ...row,
                                                        correct_column_index: row.correct_column_index === index
                                                            ? 0
                                                            : row.correct_column_index > index ? row.correct_column_index - 1 : row.correct_column_index,
                                                    })),
                                                }))}
                                                className="rounded-lg border border-rose-200 px-3 text-sm font-semibold text-rose-600 disabled:opacity-30"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    ))}
                                </div>
                                <button type="button" disabled={data.matrix_columns.length >= 4} onClick={() => setData('matrix_columns', [...data.matrix_columns, { label: '' }])} className="mt-3 text-sm font-semibold text-emerald-700 disabled:opacity-40">+ Tambah kategori</button>
                                <InputError message={errors.matrix_columns} />
                            </div>

                            <div className="border-t border-slate-100 pt-5">
                                <h3 className="text-sm font-semibold text-slate-800">Pernyataan dan kunci</h3>
                                <div className="mt-3 space-y-3">
                                    {data.matrix_rows.map((row, index) => (
                                        <div key={row.id || index} className="grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[1fr_190px_40px] sm:items-center">
                                            <textarea
                                                value={row.statement}
                                                onChange={(event) => {
                                                    const rows = [...data.matrix_rows];
                                                    rows[index] = { ...rows[index], statement: event.target.value };
                                                    setData('matrix_rows', rows);
                                                }}
                                                rows={2}
                                                placeholder={`Pernyataan ${index + 1}`}
                                                className="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                                            />
                                            <select
                                                value={row.correct_column_index}
                                                onChange={(event) => {
                                                    const rows = [...data.matrix_rows];
                                                    rows[index] = { ...rows[index], correct_column_index: Number(event.target.value) };
                                                    setData('matrix_rows', rows);
                                                }}
                                                className="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                                            >
                                                {data.matrix_columns.map((column, columnIndex) => <option key={column.id || columnIndex} value={columnIndex}>{column.label || `Kategori ${columnIndex + 1}`}</option>)}
                                            </select>
                                            <button type="button" disabled={data.matrix_rows.length <= 2} onClick={() => setData('matrix_rows', data.matrix_rows.filter((_, rowIndex) => rowIndex !== index))} className="rounded-lg border border-rose-200 px-2 py-2 text-sm font-semibold text-rose-600 disabled:opacity-30">×</button>
                                        </div>
                                    ))}
                                </div>
                                <button type="button" disabled={data.matrix_rows.length >= 10} onClick={() => setData('matrix_rows', [...data.matrix_rows, { statement: '', correct_column_index: 0 }])} className="mt-3 text-sm font-semibold text-emerald-700 disabled:opacity-40">+ Tambah pernyataan</button>
                                <InputError message={errors.matrix_rows} />
                            </div>
                        </div>
                    ) : (
                        <div className="mt-4 space-y-3">
                            {data.options.map((option, index) => (
                                <div key={index} className="flex items-center gap-3">
                                    <input
                                        type={data.type === 'single_choice' ? 'radio' : 'checkbox'}
                                        name="correct-option"
                                        checked={option.is_correct}
                                        onChange={(event) => updateOption(index, 'is_correct', event.target.checked)}
                                        className="border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                    />
                                    <span className="w-6 text-sm font-semibold text-slate-500">{String.fromCharCode(65 + index)}</span>
                                    <input value={option.content} onChange={(event) => updateOption(index, 'content', event.target.value)} className="flex-1 rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                                </div>
                            ))}
                            <InputError message={errors.options} />
                        </div>
                    )}
                </section>

                <div className="flex justify-end gap-3">
                    <Link href={question ? route('questions.show', question.id) : route('questions.index')} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Batal</Link>
                    <button disabled={processing} className="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">{question ? 'Simpan perubahan' : 'Simpan draft'}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
