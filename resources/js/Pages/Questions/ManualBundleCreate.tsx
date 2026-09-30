import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormattedText from '@/Components/FormattedText';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';

type AnswerFormat = 'single_choice' | 'true_false' | 'multiple_choice';
type CognitiveLevel = 'textual' | 'inferential' | 'evaluation';
type Blueprint = { id: number; code: string; name: string; description?: string };
type Competency = { id: number; code: string; name: string; grade_level: number; question_blueprints: Blueprint[] };
type Slot = { question_blueprint_id: number; answer_format: AnswerFormat; cognitive_level: CognitiveLevel };
type QuestionDraft = {
    prompt: string;
    explanation: string;
    matrix_labels: { true_label: string; false_label: string };
    options: { content: string; is_correct: boolean }[];
    statements: { content: string; is_true: boolean }[];
};
type SavedDraft = {
    generation_id: number;
    competency_id: number;
    title: string;
    story: string;
    max_words: number;
    stimulus_image_url?: string | null;
    stimulus_image_alt?: string;
    bundle_slots: Slot[];
    questions: QuestionDraft[];
};

const answerFormats: { value: AnswerFormat; label: string }[] = [
    { value: 'single_choice', label: 'Pilihan Ganda' },
    { value: 'true_false', label: 'Benar / Salah' },
    { value: 'multiple_choice', label: 'MCMA' },
];
const cognitiveLevels: { value: CognitiveLevel; label: string }[] = [
    { value: 'textual', label: 'Pemahaman Tekstual (Level 1)' },
    { value: 'inferential', label: 'Pemahaman Inferensial (Level 2)' },
    { value: 'evaluation', label: 'Evaluasi dan Apresiasi (Level 3)' },
];
const blankOptions = (count: number) => Array.from({ length: count }, () => ({ content: '', is_correct: false }));
const blankQuestion = (answerFormat: AnswerFormat = 'single_choice'): QuestionDraft => ({
    prompt: '',
    explanation: '',
    matrix_labels: { true_label: 'Benar', false_label: 'Salah' },
    options: blankOptions(answerFormat === 'multiple_choice' ? 3 : 4),
    statements: Array.from({ length: 3 }, () => ({ content: '', is_true: true })),
});

const normalizeBlankOptions = (question: QuestionDraft, answerFormat: AnswerFormat): QuestionDraft => {
    if (question.options.some((option) => option.content.trim() || option.is_correct)) return question;

    return { ...question, options: blankOptions(answerFormat === 'multiple_choice' ? 3 : 4) };
};

export default function ManualBundleCreate({ subject, competencies, bundleDefaults, draft }: {
    subject: { id: number; code: string; name: string };
    competencies: Competency[];
    bundleDefaults: Omit<Slot, 'question_blueprint_id'>[];
    draft?: SavedDraft | null;
}) {
    const [previewOpen, setPreviewOpen] = useState(false);
    const [previewWindow, setPreviewWindow] = useState<Window | null>(null);
    const { data, setData, post, transform, processing, errors } = useForm({
        subject_id: subject.id,
        draft_generation_id: draft?.generation_id || null as number | null,
        competency_id: draft?.competency_id || 0,
        title: draft?.title || '',
        story: draft?.story || '',
        stimulus_image: null as File | null,
        stimulus_image_alt: draft?.stimulus_image_alt || '',
        max_words: draft?.max_words || 200,
        submission_mode: 'review' as 'draft' | 'review',
        bundle_slots: draft?.bundle_slots?.length === 3
            ? draft.bundle_slots
            : bundleDefaults.map((slot) => ({ question_blueprint_id: 0, ...slot })) as Slot[],
        questions: draft?.questions?.length === 3
            ? draft.questions.map((question, index) => ({ ...blankQuestion(draft.bundle_slots[index].answer_format), ...question }))
            : bundleDefaults.map((slot) => blankQuestion(slot.answer_format)),
    });
    const selectedCompetency = competencies.find((item) => item.id === data.competency_id);
    const storyWordCount = data.story.match(/[\p{L}\p{N}]+(?:[-’'][\p{L}\p{N}]+)*/gu)?.length || 0;
    const hasDraftContent = [data.title, data.story, ...data.questions.flatMap((question) => [
        question.prompt,
        question.explanation,
        ...question.options.map((option) => option.content),
        ...question.statements.map((statement) => statement.content),
    ])].some((value) => value.trim() !== '');
    const [stimulusImagePreview, setStimulusImagePreview] = useState<string | null>(null);

    useEffect(() => {
        if (!data.stimulus_image) {
            setStimulusImagePreview(null);
            return;
        }

        const url = URL.createObjectURL(data.stimulus_image);
        setStimulusImagePreview(url);

        return () => URL.revokeObjectURL(url);
    }, [data.stimulus_image]);

    useEffect(() => () => previewWindow?.close(), [previewWindow]);

    const openPreviewWindow = () => {
        if (previewWindow && !previewWindow.closed) {
            previewWindow.focus();
            return;
        }

        const popup = window.open('', '_blank');
        if (!popup) {
            window.alert('Tab preview diblokir browser. Izinkan pop-up untuk situs ini, lalu coba lagi.');
            return;
        }

        popup.document.title = 'Preview Siswa · TOA';
        popup.document.documentElement.lang = 'id';
        popup.document.body.className = 'm-0 min-h-screen bg-slate-100';
        document.querySelectorAll('link[rel="stylesheet"], style').forEach((node) => popup.document.head.appendChild(node.cloneNode(true)));
        popup.addEventListener('beforeunload', () => setPreviewWindow(null), { once: true });
        setPreviewWindow(popup);
    };

    const chooseCompetency = (competencyId: number) => {
        const competency = competencies.find((item) => item.id === competencyId);
        setData((current) => ({
            ...current,
            competency_id: competencyId,
            bundle_slots: bundleDefaults.map((slot, index) => ({
                question_blueprint_id: competency?.question_blueprints[index]?.id || 0,
                ...slot,
            })),
        }));
    };

    const updateSlot = <Key extends keyof Slot>(index: number, key: Key, value: Slot[Key]) => setData((current) => {
        const oldValue = current.bundle_slots[index][key];
        const occupied = current.bundle_slots.findIndex((slot, otherIndex) => otherIndex !== index && slot[key] === value);
        const bundleSlots = current.bundle_slots.map((slot, slotIndex) => {
            if (slotIndex === index) return { ...slot, [key]: value };
            if (slotIndex === occupied) return { ...slot, [key]: oldValue };
            return slot;
        });

        return {
            ...current,
            bundle_slots: bundleSlots,
            questions: key === 'answer_format'
                ? current.questions.map((question, questionIndex) => normalizeBlankOptions(question, bundleSlots[questionIndex].answer_format))
                : current.questions,
        };
    });

    const updateQuestion = (index: number, patch: Partial<QuestionDraft>) => setData((current) => ({
        ...current,
        questions: current.questions.map((question, questionIndex) => questionIndex === index ? { ...question, ...patch } : question),
    }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        saveBundle('review');
    };

    const saveBundle = (mode: 'draft' | 'review') => {
        setData('submission_mode', mode);
        transform((current) => ({ ...current, submission_mode: mode }));
        post(route('manual-story-bundles.store'));
    };

    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Bank Soal · Manual</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Buat Bundle Bahasa Indonesia</h1></div>}>
            <Head title="Buat Bundle Bahasa Indonesia" />
            <form onSubmit={submit} className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
                <section className="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                    <h2 className="font-semibold text-emerald-950">Satu bacaan untuk tiga soal</h2>
                    <p className="mt-2 text-sm leading-6 text-emerald-800">Setiap bundle memakai tiga tipe soal milik satu kompetensi, satu Pilihan Ganda, satu Benar/Salah, satu MCMA, serta Level 1–3 masing-masing satu kali. Pasangannya boleh ditukar.</p>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="grid gap-5 md:grid-cols-2">
                        <label className="text-sm font-semibold text-slate-800">Mata pelajaran
                            <input value={`${subject.code} · ${subject.name}`} readOnly className="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 text-slate-600" />
                        </label>
                        <div>
                            <div className="flex items-center justify-between gap-3">
                                <label htmlFor="competency_id" className="text-sm font-semibold text-slate-800">Kompetensi</label>
                                <Link href={route('competencies.create', { subject_id: subject.id })} target="_blank" className="text-xs font-bold text-emerald-700 hover:text-emerald-600 hover:underline">+ Tambah kompetensi ↗</Link>
                            </div>
                            <select id="competency_id" value={data.competency_id} onChange={(event) => chooseCompetency(Number(event.target.value))} className="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500">
                                <option value={0}>Pilih kompetensi</option>
                                {competencies.map((competency) => <option key={competency.id} value={competency.id}>Kelas {competency.grade_level} · {competency.name}</option>)}
                            </select>
                            <InputError message={errors.competency_id} className="mt-1" />
                        </div>
                    </div>

                    {selectedCompetency && <fieldset className="mt-6 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
                        <legend className="px-2 text-sm font-bold text-indigo-950">Konfigurasi tiga soal</legend>
                        {selectedCompetency.question_blueprints.length === 3 ? <div className="overflow-hidden rounded-xl border border-indigo-100 bg-white">
                            <div className="hidden grid-cols-[60px_minmax(0,1fr)_170px_230px] gap-3 bg-indigo-100/60 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-indigo-800 lg:grid"><span>Soal</span><span>Tipe soal</span><span>Jawaban</span><span>Level soal</span></div>
                            <div className="divide-y divide-indigo-100">{data.bundle_slots.map((slot, index) => <div key={index} className="grid gap-2 px-3 py-3 lg:grid-cols-[60px_minmax(0,1fr)_170px_230px] lg:items-center lg:gap-3">
                                <strong className="text-xs text-indigo-800">Soal {index + 1}</strong>
                                <div className="flex items-center gap-1.5">
                                    <select value={slot.question_blueprint_id} onChange={(event) => updateSlot(index, 'question_blueprint_id', Number(event.target.value))} className="w-full rounded-lg border-slate-300 text-sm">{selectedCompetency.question_blueprints.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
                                    {slot.question_blueprint_id > 0 && (
                                        <a
                                            href={route('question-types.edit', slot.question_blueprint_id)}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex shrink-0 items-center justify-center rounded-lg border border-indigo-200 bg-white p-2 text-indigo-700 shadow-sm hover:bg-indigo-50 hover:text-indigo-900"
                                            title="Customize tipe soal ini di tab baru"
                                        >
                                            <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                                <path d="m13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
                                            </svg>
                                        </a>
                                    )}
                                </div>
                                <select value={slot.answer_format} onChange={(event) => updateSlot(index, 'answer_format', event.target.value as AnswerFormat)} className="w-full rounded-lg border-slate-300 text-sm">{answerFormats.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select>
                                <select value={slot.cognitive_level} onChange={(event) => updateSlot(index, 'cognitive_level', event.target.value as CognitiveLevel)} className="w-full rounded-lg border-slate-300 text-sm">{cognitiveLevels.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select>
                            </div>)}</div>
                        </div> : <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Kompetensi ini belum memiliki tepat tiga tipe soal. Lengkapi dari halaman Tipe Soal.</p>}
                        <InputError message={errors.bundle_slots} className="mt-2" />
                    </fieldset>}

                    <div className="mt-6 grid gap-5">
                        <label className="text-sm font-semibold text-slate-800">Judul bacaan
                            <input value={data.title} onChange={(event) => setData('title', event.target.value)} maxLength={255} placeholder="Contoh: Menjaga Kebersihan Sungai" className="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                            <InputError message={errors.title} className="mt-1" />
                        </label>
                        <label className="text-sm font-semibold text-slate-800">Isi bacaan / stimulus bersama
                            <textarea value={data.story} onChange={(event) => setData('story', event.target.value)} rows={9} maxLength={20000} placeholder="Tulis bacaan lengkap yang menjadi dasar ketiga soal..." className="mt-2 block w-full rounded-xl border-slate-300 leading-7 focus:border-emerald-500 focus:ring-emerald-500" />
                            <span className={`mt-1 block text-right text-xs font-medium ${storyWordCount > data.max_words ? 'text-rose-600' : 'text-slate-500'}`}>{storyWordCount}/{data.max_words} kata</span>
                            <InputError message={errors.story} className="mt-1" />
                        </label>
                        <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p className="text-sm font-semibold text-slate-800">Gambar stimulus <span className="font-normal text-slate-500">(opsional)</span></p>
                                    <p className="mt-1 text-xs text-slate-500">JPG, PNG, atau WebP. Maksimal 10 MB.</p>
                                </div>
                                {data.stimulus_image && <button type="button" onClick={() => setData('stimulus_image', null)} className="text-xs font-semibold text-rose-700 hover:text-rose-600">Hapus gambar</button>}
                            </div>
                            <input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => setData('stimulus_image', event.target.files?.[0] || null)} className="mt-3 block w-full rounded-lg border border-slate-300 bg-white text-sm file:mr-4 file:border-0 file:bg-emerald-50 file:px-4 file:py-2.5 file:font-semibold file:text-emerald-700" />
                            <InputError message={errors.stimulus_image} className="mt-1" />
                            {(stimulusImagePreview || draft?.stimulus_image_url) && <img src={stimulusImagePreview || draft?.stimulus_image_url || ''} alt="Preview gambar stimulus" className="mt-4 max-h-72 w-full rounded-xl border border-slate-200 bg-white object-contain" />}
                            <label className="mt-4 block text-sm font-semibold text-slate-700">Keterangan gambar <span className="font-normal text-slate-500">(opsional)</span>
                                <input value={data.stimulus_image_alt} onChange={(event) => setData('stimulus_image_alt', event.target.value)} maxLength={255} placeholder="Contoh: Diagram kondisi sungai sebelum dan sesudah dibersihkan" className="mt-2 block w-full rounded-lg border-slate-300 bg-white text-sm" />
                                <InputError message={errors.stimulus_image_alt} className="mt-1" />
                            </label>
                        </div>
                        <label className="max-w-xs text-sm font-semibold text-slate-800">Maksimal kata
                            <input type="number" min={50} max={1000} value={data.max_words} onChange={(event) => setData('max_words', Number(event.target.value))} className="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                            <span className="mt-1 block text-xs font-normal text-slate-500">Default 200 kata untuk satu bacaan.</span>
                            <InputError message={errors.max_words} className="mt-1" />
                        </label>
                    </div>
                </section>

                {data.bundle_slots.map((slot, index) => {
                    const question = data.questions[index];
                    const blueprint = selectedCompetency?.question_blueprints.find((item) => item.id === slot.question_blueprint_id);
                    const formatLabel = answerFormats.find((item) => item.value === slot.answer_format)?.label;
                    const levelLabel = cognitiveLevels.find((item) => item.value === slot.cognitive_level)?.label;
                    return <section key={index} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div><p className="text-xs font-bold uppercase tracking-wide text-emerald-600">Soal {index + 1} · {formatLabel}</p><h2 className="mt-1 text-lg font-bold text-slate-900">{blueprint?.name || 'Pilih kompetensi terlebih dahulu'}</h2></div>
                            <span className="rounded-full bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-700">{levelLabel}</span>
                        </div>
                        <label className="mt-5 block text-sm font-semibold text-slate-800">Pertanyaan
                            <textarea value={question.prompt} onChange={(event) => updateQuestion(index, { prompt: event.target.value })} rows={3} maxLength={10000} className="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                            <span className="mt-1 block text-xs font-normal text-slate-500">Ketik pecahan seperti 1/4; pada tampilan soal akan otomatis menjadi pecahan bertingkat.</span>
                            <InputError message={errors[`questions.${index}.prompt` as keyof typeof errors]} className="mt-1" />
                        </label>

                        {slot.answer_format === 'true_false' ? <fieldset className="mt-5"><legend className="text-sm font-semibold text-slate-800">Pernyataan dan kunci</legend>
                            <div className="mt-2 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2">
                                <label className="text-xs font-semibold text-slate-700">Nama kolom pertama
                                    <input value={question.matrix_labels.true_label} onChange={(event) => updateQuestion(index, { matrix_labels: { ...question.matrix_labels, true_label: event.target.value } })} maxLength={60} className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm" />
                                </label>
                                <label className="text-xs font-semibold text-slate-700">Nama kolom kedua
                                    <input value={question.matrix_labels.false_label} onChange={(event) => updateQuestion(index, { matrix_labels: { ...question.matrix_labels, false_label: event.target.value } })} maxLength={60} className="mt-1 block w-full rounded-lg border-slate-300 bg-white text-sm" />
                                </label>
                                <InputError message={errors[`questions.${index}.matrix_labels` as keyof typeof errors]} className="sm:col-span-2" />
                            </div>
                            <div className="mt-2 space-y-2">{question.statements.map((statement, statementIndex) => <div key={statementIndex} className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_130px]">
                                <input value={statement.content} onChange={(event) => updateQuestion(index, { statements: question.statements.map((item, itemIndex) => itemIndex === statementIndex ? { ...item, content: event.target.value } : item) })} placeholder={`Pernyataan ${statementIndex + 1}`} className="rounded-lg border-slate-300 text-sm" />
                                <select value={statement.is_true ? 'true' : 'false'} onChange={(event) => updateQuestion(index, { statements: question.statements.map((item, itemIndex) => itemIndex === statementIndex ? { ...item, is_true: event.target.value === 'true' } : item) })} className="rounded-lg border-slate-300 text-sm"><option value="true">{question.matrix_labels.true_label || 'Kolom 1'}</option><option value="false">{question.matrix_labels.false_label || 'Kolom 2'}</option></select>
                            </div>)}</div>
                            <InputError message={errors[`questions.${index}.statements` as keyof typeof errors]} className="mt-1" />
                        </fieldset> : <fieldset className="mt-5"><legend className="text-sm font-semibold text-slate-800">Pilihan jawaban dan kunci</legend>
                            <p className="mt-1 text-xs text-slate-500">{slot.answer_format === 'single_choice' ? 'Pilih tepat satu jawaban benar.' : 'Centang minimal dua jawaban benar.'}</p>
                            {slot.answer_format === 'multiple_choice' && <div className="mt-3 flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                <span className="text-xs font-semibold text-slate-600">{question.options.length} pilihan</span>
                                <div className="flex items-center gap-1">
                                    <button type="button" aria-label="Kurangi pilihan MCMA" disabled={question.options.length <= 2} onClick={() => updateQuestion(index, { options: question.options.slice(0, -1) })} className="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 bg-white text-lg font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-35">−</button>
                                    <button type="button" aria-label="Tambah pilihan MCMA" disabled={question.options.length >= 10} onClick={() => updateQuestion(index, { options: [...question.options, { content: '', is_correct: false }] })} className="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 bg-white text-lg font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-35">+</button>
                                </div>
                            </div>}
                            <div className="mt-2 space-y-2">{question.options.map((option, optionIndex) => <label key={optionIndex} className="grid grid-cols-[32px_minmax(0,1fr)] items-center gap-2">
                                <input type={slot.answer_format === 'single_choice' ? 'radio' : 'checkbox'} name={`correct-${index}`} checked={option.is_correct} onChange={(event) => updateQuestion(index, { options: question.options.map((item, itemIndex) => ({ ...item, is_correct: slot.answer_format === 'single_choice' ? itemIndex === optionIndex : itemIndex === optionIndex ? event.target.checked : item.is_correct })) })} className="justify-self-center border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                                <div className="flex items-center gap-2">{slot.answer_format === 'single_choice' && <span className="w-5 text-sm font-bold text-slate-500">{String.fromCharCode(65 + optionIndex)}.</span>}<input value={option.content} onChange={(event) => updateQuestion(index, { options: question.options.map((item, itemIndex) => itemIndex === optionIndex ? { ...item, content: event.target.value } : item) })} placeholder={slot.answer_format === 'multiple_choice' ? `Pernyataan pilihan ${optionIndex + 1}` : undefined} className="w-full rounded-lg border-slate-300 text-sm" /></div>
                            </label>)}</div>
                            <InputError message={errors[`questions.${index}.options` as keyof typeof errors]} className="mt-1" />
                        </fieldset>}

                        <label className="mt-5 block text-sm font-semibold text-slate-800">Pembahasan <span className="font-normal text-slate-500">(opsional)</span>
                            <textarea value={question.explanation} onChange={(event) => updateQuestion(index, { explanation: event.target.value })} rows={3} maxLength={10000} className="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" />
                        </label>
                    </section>;
                })}

                <div className="flex flex-col-reverse justify-end gap-3 sm:flex-row">
                    <Link href={route('questions.index')} className="rounded-xl border border-slate-300 px-5 py-3 text-center text-sm font-semibold text-slate-700">Batal</Link>
                    <button type="button" onClick={() => setPreviewOpen(true)} className="rounded-xl border border-indigo-300 bg-white px-5 py-3 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">Preview siswa</button>
                    <button type="button" onClick={() => saveBundle('draft')} disabled={processing || !hasDraftContent || !selectedCompetency || selectedCompetency.question_blueprints.length !== 3} className="rounded-xl border border-amber-300 bg-amber-50 px-5 py-3 text-sm font-semibold text-amber-800 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50">{processing && data.submission_mode === 'draft' ? 'Menyimpan...' : 'Simpan draft'}</button>
                    <button disabled={processing || !selectedCompetency || selectedCompetency.question_blueprints.length !== 3} className="rounded-xl bg-emerald-600 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50">{processing && data.submission_mode === 'review' ? 'Menyimpan...' : 'Simpan & Ajukan Verifikasi'}</button>
                </div>
            </form>

            <Modal show={previewOpen} maxWidth="5xl" onClose={() => setPreviewOpen(false)}>
                <ManualBundleStudentPreview
                    title={data.title}
                    story={data.story}
                    stimulusImageUrl={stimulusImagePreview || draft?.stimulus_image_url || null}
                    stimulusImageAlt={data.stimulus_image_alt}
                    slots={data.bundle_slots}
                    questions={data.questions}
                    onOpenNewTab={openPreviewWindow}
                    onClose={() => setPreviewOpen(false)}
                />
            </Modal>
            {previewWindow && !previewWindow.closed && createPortal(
                <ManualBundleStudentPreview
                    title={data.title}
                    story={data.story}
                    stimulusImageUrl={stimulusImagePreview || draft?.stimulus_image_url || null}
                    stimulusImageAlt={data.stimulus_image_alt}
                    slots={data.bundle_slots}
                    questions={data.questions}
                    standalone
                    onClose={() => previewWindow.close()}
                />,
                previewWindow.document.body,
            )}
        </AuthenticatedLayout>
    );
}

function ManualBundleStudentPreview({ title, story, stimulusImageUrl, stimulusImageAlt, slots, questions, standalone = false, onOpenNewTab, onClose }: {
    title: string;
    story: string;
    stimulusImageUrl: string | null;
    stimulusImageAlt: string;
    slots: Slot[];
    questions: QuestionDraft[];
    standalone?: boolean;
    onOpenNewTab?: () => void;
    onClose: () => void;
}) {
    const [currentIndex, setCurrentIndex] = useState(0);
    const [optionAnswers, setOptionAnswers] = useState<Record<number, number[]>>({});
    const [statementAnswers, setStatementAnswers] = useState<Record<number, Record<number, boolean>>>({});
    const slot = slots[currentIndex];
    const question = questions[currentIndex];
    const selectedOptions = optionAnswers[currentIndex] || [];
    const selectedStatements = statementAnswers[currentIndex] || {};
    const formatLabel = answerFormats.find((item) => item.value === slot?.answer_format)?.label || 'Soal';

    const chooseOption = (optionIndex: number) => {
        setOptionAnswers((current) => {
            const selected = current[currentIndex] || [];
            const next = slot.answer_format === 'single_choice'
                ? [optionIndex]
                : selected.includes(optionIndex)
                  ? selected.filter((item) => item !== optionIndex)
                  : [...selected, optionIndex];

            return { ...current, [currentIndex]: next };
        });
    };

    return (
        <div className={`overflow-hidden bg-slate-100 ${standalone ? 'min-h-screen' : ''}`}>
            <header className="flex items-center justify-between gap-4 bg-slate-900 px-5 py-4 text-white">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-widest text-emerald-300">Preview siswa</p>
                    <h2 className="mt-1 font-bold">TOA · Simulasi Bundle</h2>
                </div>
                <div className="flex items-center gap-2">
                    {onOpenNewTab && <button type="button" onClick={onOpenNewTab} className="rounded-lg border border-slate-600 px-3 py-2 text-xs font-semibold text-white hover:bg-white/10">↗ Buka di tab baru</button>}
                    <button type="button" onClick={onClose} aria-label="Tutup preview" className="rounded-lg p-2 text-2xl leading-none text-slate-300 hover:bg-white/10 hover:text-white">×</button>
                </div>
            </header>

            <div className={`${standalone ? 'min-h-[calc(100vh-72px)]' : 'max-h-[80vh] overflow-y-auto'} p-4 sm:p-6`}>
                <div className="mx-auto mb-3 flex max-w-5xl items-center justify-between rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                    <span>Jawaban pada preview tidak disimpan.</span>
                    <span className="ml-4 shrink-0 font-semibold">Soal {currentIndex + 1} dari {questions.length}</span>
                </div>

                <main className="mx-auto grid max-w-5xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)]">
                    <aside className="border-b border-slate-200 bg-slate-50/70 p-5 lg:border-b-0 lg:border-r sm:p-6">
                        <p className="text-xs font-bold uppercase tracking-wider text-indigo-600">Bacaan</p>
                        <h3 className="mt-2 text-lg font-bold text-slate-900">{title.trim() || 'Judul bacaan belum diisi'}</h3>
                        <div className="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-700">{story.trim() || <span className="italic text-slate-400">Isi bacaan belum diisi.</span>}</div>
                        {stimulusImageUrl && <img src={stimulusImageUrl} alt={stimulusImageAlt.trim() || 'Gambar stimulus'} className="mt-4 max-h-72 w-full rounded-xl border border-slate-200 bg-white object-contain" />}
                    </aside>

                    <section className="min-w-0 p-5 sm:p-6">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">Soal {currentIndex + 1}</span>
                            <span className="text-xs font-semibold text-slate-500">{formatLabel}</span>
                        </div>
                        <h3 className="mt-4 text-lg font-semibold leading-7 text-slate-900">{question?.prompt.trim() ? <FormattedText text={question.prompt} /> : <span className="italic text-slate-400">Pertanyaan belum diisi.</span>}</h3>

                        {slot?.answer_format === 'true_false' ? (
                            <div className="mt-5">
                                <p className="mb-2 text-xs text-slate-500">Pilih satu jawaban untuk setiap pernyataan.</p>
                                <div className="overflow-x-auto rounded-xl border border-slate-300">
                                    <table className="w-full min-w-[430px] table-fixed text-sm">
                                        <thead className="border-b-2 border-slate-700 bg-slate-50"><tr><th className="w-12 p-2.5 text-center">#</th><th className="p-2.5 text-left">Pernyataan</th>{[question.matrix_labels.true_label || 'Kolom 1', question.matrix_labels.false_label || 'Kolom 2'].map((label) => <th key={label} className="w-20 break-words p-2 text-center">{label}</th>)}</tr></thead>
                                        <tbody>{question.statements.map((statement, statementIndex) => <tr key={statementIndex} className="border-t border-slate-200"><td className="p-3 text-center font-semibold text-slate-600">{String.fromCharCode(65 + statementIndex)}.</td><td className="p-3 leading-6 text-slate-800">{statement.content || <span className="italic text-slate-400">Pernyataan belum diisi</span>}</td>{[true, false].map((value) => <td key={String(value)} className="p-3 text-center"><button type="button" aria-label={`${statement.content || `Pernyataan ${statementIndex + 1}`}: ${value ? question.matrix_labels.true_label : question.matrix_labels.false_label}`} onClick={() => setStatementAnswers((current) => ({ ...current, [currentIndex]: { ...(current[currentIndex] || {}), [statementIndex]: value } }))} className={`inline-flex h-8 w-8 items-center justify-center rounded-full border-2 text-sm font-bold ${selectedStatements[statementIndex] === value ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-400 bg-white text-transparent'}`}>✓</button></td>)}</tr>)}</tbody>
                                    </table>
                                </div>
                            </div>
                        ) : (
                            <div className="mt-5 space-y-3">
                                {slot?.answer_format === 'multiple_choice' && <p className="text-xs text-slate-500">Pilih semua jawaban yang benar.</p>}
                                {question?.options.map((option, optionIndex) => {
                                    const selected = selectedOptions.includes(optionIndex);
                                    return <button key={optionIndex} type="button" onClick={() => chooseOption(optionIndex)} className={`flex min-h-12 w-full items-center gap-3 rounded-xl border p-3 text-left ${selected ? 'border-emerald-500 bg-emerald-50 ring-1 ring-emerald-500' : 'border-slate-200 hover:border-slate-300'}`}>
                                        {slot?.answer_format === 'multiple_choice'
                                            ? <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded border-2 text-sm font-bold ${selected ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 text-transparent'}`}>✓</span>
                                            : <span className="w-6 shrink-0 font-semibold text-slate-600">{String.fromCharCode(65 + optionIndex)}.</span>}
                                        {option.content ? <FormattedText text={option.content} className="text-sm leading-6 text-slate-800" /> : <span className="text-sm italic leading-6 text-slate-400">Pilihan {optionIndex + 1} belum diisi</span>}
                                    </button>;
                                })}
                            </div>
                        )}
                    </section>
                </main>

                <div className="mx-auto mt-4 flex max-w-5xl items-center justify-between gap-3">
                    <button type="button" disabled={currentIndex === 0} onClick={() => setCurrentIndex((value) => Math.max(0, value - 1))} className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 disabled:opacity-40">Sebelumnya</button>
                    {currentIndex < questions.length - 1
                        ? <button type="button" onClick={() => setCurrentIndex((value) => Math.min(questions.length - 1, value + 1))} className="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white">Berikutnya</button>
                        : <button type="button" onClick={onClose} className="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white">Selesai preview</button>}
                </div>
            </div>
        </div>
    );
}
