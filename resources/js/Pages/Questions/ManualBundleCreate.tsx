import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type AnswerFormat = 'single_choice' | 'true_false' | 'multiple_choice';
type CognitiveLevel = 'textual' | 'inferential' | 'evaluation';
type Blueprint = { id: number; code: string; name: string; description?: string };
type Competency = { id: number; code: string; name: string; grade_level: number; question_blueprints: Blueprint[] };
type Slot = { question_blueprint_id: number; answer_format: AnswerFormat; cognitive_level: CognitiveLevel };
type QuestionDraft = {
    prompt: string;
    explanation: string;
    options: { content: string; is_correct: boolean }[];
    statements: { content: string; is_true: boolean }[];
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
const blankQuestion = (): QuestionDraft => ({
    prompt: '',
    explanation: '',
    options: Array.from({ length: 4 }, () => ({ content: '', is_correct: false })),
    statements: Array.from({ length: 3 }, () => ({ content: '', is_true: true })),
});

export default function ManualBundleCreate({ subject, competencies, bundleDefaults }: {
    subject: { id: number; code: string; name: string };
    competencies: Competency[];
    bundleDefaults: Omit<Slot, 'question_blueprint_id'>[];
}) {
    const { data, setData, post, processing, errors } = useForm({
        subject_id: subject.id,
        competency_id: 0,
        title: '',
        story: '',
        max_words: 200,
        bundle_slots: bundleDefaults.map((slot) => ({ question_blueprint_id: 0, ...slot })) as Slot[],
        questions: Array.from({ length: 3 }, blankQuestion),
    });
    const selectedCompetency = competencies.find((item) => item.id === data.competency_id);
    const storyWordCount = data.story.match(/[\p{L}\p{N}]+(?:[-’'][\p{L}\p{N}]+)*/gu)?.length || 0;

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
        return {
            ...current,
            bundle_slots: current.bundle_slots.map((slot, slotIndex) => {
                if (slotIndex === index) return { ...slot, [key]: value };
                if (slotIndex === occupied) return { ...slot, [key]: oldValue };
                return slot;
            }),
        };
    });

    const updateQuestion = (index: number, patch: Partial<QuestionDraft>) => setData((current) => ({
        ...current,
        questions: current.questions.map((question, questionIndex) => questionIndex === index ? { ...question, ...patch } : question),
    }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
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
                        <label className="text-sm font-semibold text-slate-800">Kompetensi
                            <select value={data.competency_id} onChange={(event) => chooseCompetency(Number(event.target.value))} className="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500">
                                <option value={0}>Pilih kompetensi</option>
                                {competencies.map((competency) => <option key={competency.id} value={competency.id}>Kelas {competency.grade_level} · {competency.name}</option>)}
                            </select>
                            <InputError message={errors.competency_id} className="mt-1" />
                        </label>
                    </div>

                    {selectedCompetency && <fieldset className="mt-6 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
                        <legend className="px-2 text-sm font-bold text-indigo-950">Konfigurasi tiga soal</legend>
                        {selectedCompetency.question_blueprints.length === 3 ? <div className="overflow-hidden rounded-xl border border-indigo-100 bg-white">
                            <div className="hidden grid-cols-[60px_1fr_170px_230px] gap-3 bg-indigo-100/60 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-indigo-800 lg:grid"><span>Soal</span><span>Tipe soal</span><span>Jawaban</span><span>Level soal</span></div>
                            <div className="divide-y divide-indigo-100">{data.bundle_slots.map((slot, index) => <div key={index} className="grid gap-2 px-3 py-3 lg:grid-cols-[60px_1fr_170px_230px] lg:items-center lg:gap-3">
                                <strong className="text-xs text-indigo-800">Soal {index + 1}</strong>
                                <select value={slot.question_blueprint_id} onChange={(event) => updateSlot(index, 'question_blueprint_id', Number(event.target.value))} className="w-full rounded-lg border-slate-300 text-sm">{selectedCompetency.question_blueprints.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
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
                            <InputError message={errors[`questions.${index}.prompt` as keyof typeof errors]} className="mt-1" />
                        </label>

                        {slot.answer_format === 'true_false' ? <fieldset className="mt-5"><legend className="text-sm font-semibold text-slate-800">Pernyataan dan kunci</legend>
                            <div className="mt-2 space-y-2">{question.statements.map((statement, statementIndex) => <div key={statementIndex} className="grid gap-2 sm:grid-cols-[1fr_130px]">
                                <input value={statement.content} onChange={(event) => updateQuestion(index, { statements: question.statements.map((item, itemIndex) => itemIndex === statementIndex ? { ...item, content: event.target.value } : item) })} placeholder={`Pernyataan ${statementIndex + 1}`} className="rounded-lg border-slate-300 text-sm" />
                                <select value={statement.is_true ? 'true' : 'false'} onChange={(event) => updateQuestion(index, { statements: question.statements.map((item, itemIndex) => itemIndex === statementIndex ? { ...item, is_true: event.target.value === 'true' } : item) })} className="rounded-lg border-slate-300 text-sm"><option value="true">Benar</option><option value="false">Salah</option></select>
                            </div>)}</div>
                            <InputError message={errors[`questions.${index}.statements` as keyof typeof errors]} className="mt-1" />
                        </fieldset> : <fieldset className="mt-5"><legend className="text-sm font-semibold text-slate-800">Pilihan jawaban dan kunci</legend>
                            <p className="mt-1 text-xs text-slate-500">{slot.answer_format === 'single_choice' ? 'Pilih tepat satu jawaban benar.' : 'Centang minimal dua jawaban benar.'}</p>
                            <div className="mt-2 space-y-2">{question.options.map((option, optionIndex) => <label key={optionIndex} className="grid grid-cols-[32px_1fr] items-center gap-2">
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
                    <button disabled={processing || !selectedCompetency || selectedCompetency.question_blueprints.length !== 3} className="rounded-xl bg-emerald-600 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50">{processing ? 'Menyimpan...' : 'Simpan 1 Bundle · 3 Soal'}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
