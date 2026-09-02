import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Generation = {
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    request_payload: { subject_name?: string; theme: string; format?: 'direct' | 'story'; paragraph_count?: number; max_words?: number; question_count?: number };
    result_payload?: { title?: string; question_count?: number };
    created_at: string;
};

type Competency = {
    id: number;
    subject_id: number;
    parent_id?: number | null;
    code: string;
    name: string;
    grade_level: number;
};
type AnswerFormat = 'single_choice' | 'true_false' | 'multiple_choice';
type CognitiveLevel = 'textual' | 'inferential' | 'evaluation';
type BundleSlot = { question_blueprint_id: number; answer_format: AnswerFormat; cognitive_level: CognitiveLevel };
type BundleDefault = Omit<BundleSlot, 'question_blueprint_id'>;
type QuestionBlueprint = { id: number; subject_id: number; code: string; name: string; description?: string; competency_ids: number[]; competency_positions: Record<number, number> };

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

export default function StoryCreate({ subjects, competencies, questionBlueprints, recentGenerations, selectedSubjectId, generationFormat, indonesianBundleDefaults }: { subjects: { id: number; code: string; name: string; ai_question_format: 'direct' | 'story' }[]; competencies: Competency[]; questionBlueprints: QuestionBlueprint[]; recentGenerations: Generation[]; selectedSubjectId?: number | null; generationFormat: 'direct' | 'story'; indonesianBundleDefaults: BundleDefault[] }) {
    const storyMode = generationFormat === 'story';
    const { data, setData, post, transform, processing, errors, clearErrors } = useForm({
        subject_id: selectedSubjectId || 0,
        root_competency_id: 0,
        competency_id: 0,
        question_blueprint_ids: [] as number[],
        bundle_slots: indonesianBundleDefaults.map((slot) => ({ question_blueprint_id: 0, ...slot })) as BundleSlot[],
        theme: '',
        question_style: 'direct' as 'direct' | 'reasoning',
        answer_format: 'single_choice' as 'single_choice' | 'true_false' | 'multiple_choice' | 'mixed',
        difficulty: 2,
        use_illustration: false,
        illustration_mode: 'lite' as 'lite' | 'pro',
        paragraph_count: 3,
        max_words: 200,
        question_count: 3,
    });
    const availableCompetencies = competencies.filter(
        (competency) => competency.subject_id === data.subject_id && !competency.parent_id,
    );
    const availableSubcompetencies = competencies.filter(
        (competency) => competency.parent_id === data.root_competency_id,
    );
    const selectedSubject = subjects.find((subject) => subject.id === data.subject_id);
    const selectedTargetCompetency = competencies.find((competency) => competency.id === data.competency_id);
    const usesQuestionBlueprints = selectedSubject?.code === 'BIND';
    const usesIndonesianBundle = storyMode && usesQuestionBlueprints;
    const availableQuestionBlueprints = questionBlueprints.filter((item) => item.subject_id === data.subject_id);
    const needsSubcompetency = availableSubcompetencies.length > 0;
    const hasSelectedSubcompetency = availableSubcompetencies.some(
        (competency) => competency.id === data.competency_id,
    );
    const competencyBlueprints = (competencyId: number) => questionBlueprints
        .filter((item) => item.competency_ids.includes(competencyId))
        .sort((a, b) => (a.competency_positions[competencyId] || 999) - (b.competency_positions[competencyId] || 999));
    const selectedBundleBlueprints = competencyBlueprints(data.root_competency_id);
    const updateBundleSlot = <Key extends keyof BundleSlot>(index: number, key: Key, value: BundleSlot[Key]) => setData((current) => {
        const previousValue = current.bundle_slots[index][key];
        const occupiedIndex = current.bundle_slots.findIndex((slot, slotIndex) => slotIndex !== index && slot[key] === value);
        const bundleSlots = current.bundle_slots.map((slot, slotIndex) => {
            if (slotIndex === index) return { ...slot, [key]: value };
            if (slotIndex === occupiedIndex) return { ...slot, [key]: previousValue };

            return slot;
        });

        return { ...current, bundle_slots: bundleSlots, question_blueprint_ids: bundleSlots.map((slot) => slot.question_blueprint_id).filter(Boolean) };
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        transform((formData) => ({
            ...formData,
            bundle_slots: usesIndonesianBundle ? formData.bundle_slots : null,
        }));
        post(route(storyMode ? 'story-questions.store' : 'ai-questions.store'));
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-sm font-medium text-indigo-600">Asisten AI · {storyMode ? 'Paket Cerita' : 'Soal Langsung'}</p>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900">{storyMode ? 'Buat Soal Cerita' : 'Buat Soal dengan AI'}</h1>
                </div>
            }
        >
            <Head title={storyMode ? 'Buat Soal Cerita AI' : 'Buat Soal AI'} />
            <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[1fr_360px]">
                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="rounded-xl bg-indigo-50 p-5">
                        <h2 className="font-semibold text-indigo-950">{storyMode ? 'Tentukan tema dan panjang paket' : selectedTargetCompetency ? 'Topik latihan sudah dipilih' : 'Tentukan topik latihan'}</h2>
                        <p className="mt-2 text-sm leading-6 text-indigo-800">
                            {storyMode
                                ? 'AI menulis satu cerita, lalu membuat beberapa soal dari stimulus yang sama.'
                                : selectedTargetCompetency
                                    ? <>AI akan membuat soal berdasarkan <strong>{selectedTargetCompetency.name}</strong>. Contoh soal di bawah tetap opsional.</>
                                    : 'Pilih kompetensi dan subkompetensi sebagai topik latihan.'}
                        </p>
                    </div>

                    <label className="mt-6 block text-sm font-semibold text-slate-800">
                        Mata pelajaran
                        <select value={data.subject_id} onChange={(event) => {
                            clearErrors('subject_id', 'root_competency_id', 'competency_id');
                            setData((current) => ({ ...current, subject_id: Number(event.target.value), root_competency_id: 0, competency_id: 0, question_blueprint_ids: [], bundle_slots: indonesianBundleDefaults.map((slot) => ({ question_blueprint_id: 0, ...slot })) }));
                        }} className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                            <option value={0}>{subjects.length === 0 ? 'Belum ada mapel dengan kompetensi' : 'Pilih mata pelajaran terlebih dahulu'}</option>
                            {subjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.name}</option>)}
                        </select>
                        <InputError message={errors.subject_id} className="mt-1" />
                    </label>

                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        <label className="block text-sm font-semibold text-slate-800">
                            Kompetensi
                            <select
                                value={data.root_competency_id}
                                onChange={(event) => {
                                    clearErrors('root_competency_id', 'competency_id');
                                    const rootId = Number(event.target.value);
                                    const rootHasChildren = competencies.some((competency) => competency.parent_id === rootId);
                                    const defaults = competencyBlueprints(rootId);
                                    const bundleSlots = indonesianBundleDefaults.map((slot, index) => ({ question_blueprint_id: defaults[index]?.id || 0, ...slot }));
                                    setData((current) => ({ ...current, root_competency_id: rootId, competency_id: usesQuestionBlueprints || !rootHasChildren ? rootId : 0, question_blueprint_ids: defaults.map((item) => item.id), bundle_slots: bundleSlots, question_count: usesQuestionBlueprints ? 3 : current.question_count }));
                                }}
                                className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value={0}>Pilih kompetensi</option>
                                {availableCompetencies.map((competency) => <option key={competency.id} value={competency.id}>Kelas {competency.grade_level} · {competency.name}</option>)}
                            </select>
                            <InputError message={errors.root_competency_id} className="mt-1" />
                        </label>

                        {!usesQuestionBlueprints && <label className="block text-sm font-semibold text-slate-800">
                            Subkompetensi {needsSubcompetency ? '' : <span className="font-normal text-slate-500">(tidak tersedia)</span>}
                            <select
                                value={hasSelectedSubcompetency ? data.competency_id : 0}
                                onChange={(event) => {
                                    clearErrors('competency_id');
                                    setData('competency_id', Number(event.target.value));
                                }}
                                disabled={!data.root_competency_id || !needsSubcompetency}
                                className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-slate-100"
                            >
                                <option value={0}>{needsSubcompetency ? 'Pilih subkompetensi' : 'Gunakan kompetensi utama'}</option>
                                {availableSubcompetencies.map((competency) => <option key={competency.id} value={competency.id}>{competency.name}</option>)}
                            </select>
                            <InputError message={errors.competency_id} className="mt-1" />
                        </label>}
                    </div>

                    {usesIndonesianBundle && data.root_competency_id > 0 && <fieldset className="mt-5 rounded-xl border border-indigo-200 bg-indigo-50 p-5"><legend className="px-2 text-sm font-bold text-indigo-950">Komposisi bundle <span className="font-normal text-indigo-700">(1 cerita · 3 soal)</span></legend>
                        {selectedBundleBlueprints.length === 3 ? <div className="mt-1 overflow-hidden rounded-xl border border-indigo-100 bg-white">
                            <div className="hidden grid-cols-[60px_1fr_160px_220px] gap-3 bg-indigo-100/60 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-indigo-800 lg:grid"><span>Soal</span><span>Tipe soal</span><span>Jawaban</span><span>Level soal</span></div>
                            <div className="divide-y divide-indigo-100">{data.bundle_slots.map((slot, index) => <div key={index} className="grid gap-2 px-3 py-3 lg:grid-cols-[60px_1fr_160px_220px] lg:items-center lg:gap-3">
                                <strong className="text-xs text-indigo-800">Soal {index + 1}</strong>
                                <select value={slot.question_blueprint_id} onChange={(event) => updateBundleSlot(index, 'question_blueprint_id', Number(event.target.value))} className="w-full rounded-lg border-slate-300 text-sm">{selectedBundleBlueprints.map((blueprint) => <option key={blueprint.id} value={blueprint.id}>{blueprint.name}</option>)}</select>
                                <select value={slot.answer_format} onChange={(event) => updateBundleSlot(index, 'answer_format', event.target.value as AnswerFormat)} className="w-full rounded-lg border-slate-300 text-sm">{answerFormats.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select>
                                <select value={slot.cognitive_level} onChange={(event) => updateBundleSlot(index, 'cognitive_level', event.target.value as CognitiveLevel)} className="w-full rounded-lg border-slate-300 text-sm">{cognitiveLevels.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select>
                            </div>)}</div>
                        </div> : <p className="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-700">Kompetensi ini harus memiliki tepat tiga tipe soal sebelum bundle dapat dibuat.</p>}
                        <p className="mt-3 text-xs leading-5 text-indigo-700">Ketiga tipe, format jawaban, dan tingkat wajib digunakan masing-masing satu kali. Pasangannya boleh ditukar.</p><InputError message={errors.bundle_slots} className="mt-2" />
                    </fieldset>}

                    {!usesIndonesianBundle && <label className="mt-5 block text-sm font-semibold text-slate-800">
                        Level soal
                        <select value={data.difficulty} onChange={(event) => setData('difficulty', Number(event.target.value))} className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                            <option value={1}>Level 1 · Mudah</option>
                            <option value={2}>Level 2 · Sedang</option>
                            <option value={3}>Level 3 · Sulit</option>
                        </select>
                        <span className="mt-1 block text-xs font-normal text-slate-500">Semua soal hasil generasi akan menggunakan level target ini dan masih dapat diubah sebelum diverifikasi.</span>
                        <InputError message={errors.difficulty} className="mt-1" />
                    </label>}

                    <label className="mt-5 block text-sm font-semibold text-slate-800">
                        {storyMode ? <>Tema cerita <span className="font-normal text-slate-500">(opsional)</span></> : <>Contoh soal untuk dibuat variasinya <span className="font-normal text-slate-500">(opsional)</span></>}
                        <textarea
                            autoFocus
                            value={data.theme}
                            onChange={(event) => {
                                clearErrors('theme');
                                setData('theme', event.target.value);
                            }}
                            rows={4}
                            maxLength={255}
                            placeholder={storyMode ? 'Contoh: menjaga kebersihan sungai di lingkungan desa. Kosongkan agar AI memilih tema.' : 'Contoh: 2/3 + 1/4 = … (boleh dikosongkan agar AI membuat soal dari subkompetensi)'}
                            className="mt-2 block w-full rounded-xl border-slate-300 text-base focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <span className="mt-1 block text-right text-xs font-normal text-slate-400">{data.theme.length}/255</span>
                        {storyMode && <span className="mt-1 block text-xs font-normal text-slate-500">Jika kosong, AI akan menentukan tema yang sesuai kompetensi dan usia siswa.</span>}
                        <InputError message={errors.theme} className="mt-1" />
                    </label>

                    {!storyMode && <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        <fieldset>
                            <legend className="text-sm font-semibold text-slate-800">Jenis soal</legend>
                            <div className="mt-2 grid gap-2">
                                {([
                                    ['direct', 'Langsung', 'Ringkas, fokus pada konsep atau prosedur.'],
                                    ['reasoning', 'Penalaran', 'Analisis, strategi, dan langkah penyelesaian.'],
                                ] as const).map(([value, label, description]) => (
                                    <label key={value} className={`cursor-pointer rounded-xl border p-3 ${data.question_style === value ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'}`}>
                                        <span className="flex items-center gap-2"><input type="radio" checked={data.question_style === value} onChange={() => setData('question_style', value)} className="border-slate-300 text-indigo-600 focus:ring-indigo-500" /><strong className="text-sm text-slate-900">{label}</strong></span>
                                        <span className="mt-1 block pl-6 text-xs leading-5 text-slate-500">{description}</span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend className="text-sm font-semibold text-slate-800">Ilustrasi soal</legend>
                            <div className="mt-2 grid gap-2">
                                {([
                                    [false, 'Tanpa ilustrasi', 'Soal dibuat tanpa gambar.'],
                                    [true, 'Pakai ilustrasi', 'Satu gambar bersama dibuat otomatis untuk mendukung soal.'],
                                ] as const).map(([value, label, description]) => (
                                    <label key={String(value)} className={`cursor-pointer rounded-xl border p-3 ${data.use_illustration === value ? 'border-sky-500 bg-sky-50' : 'border-slate-200'}`}>
                                        <span className="flex items-center gap-2"><input type="radio" checked={data.use_illustration === value} onChange={() => setData('use_illustration', value)} className="border-slate-300 text-sky-600 focus:ring-sky-500" /><strong className="text-sm text-slate-900">{label}</strong></span>
                                        <span className="mt-1 block pl-6 text-xs leading-5 text-slate-500">{description}</span>
                                    </label>
                                ))}
                            </div>
                            {data.use_illustration && <label className="mt-3 block text-sm font-semibold text-slate-800">
                                Mode ilustrasi
                                <select value={data.illustration_mode} onChange={(event) => setData('illustration_mode', event.target.value as 'lite' | 'pro')} className="mt-2 block w-full rounded-xl border-slate-300 focus:border-sky-500 focus:ring-sky-500">
                                    <option value="lite">Lite · SVG presisi, tanpa biaya gambar</option>
                                    <option value="pro">Pro · API gambar berbayar</option>
                                </select>
                                <span className="mt-1 block text-xs font-normal leading-5 text-slate-500">Lite cocok untuk diagram Matematika; Pro cocok untuk ilustrasi adegan yang kompleks.</span>
                                <InputError message={errors.illustration_mode} className="mt-1" />
                            </label>}
                        </fieldset>
                    </div>}

                    {!storyMode && <fieldset className="mt-5">
                        <legend className="text-sm font-semibold text-slate-800">Format jawaban</legend>
                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                            {([
                                ['single_choice', 'Pilihan Ganda', 'Satu jawaban benar dari beberapa pilihan.'],
                                ['true_false', 'Benar / Salah', 'Beberapa pernyataan dinilai Benar atau Salah.'],
                                ['multiple_choice', 'MCMA', 'Multiple Choice Multiple Answer; jawaban benar bisa lebih dari satu.'],
                                ['mixed', 'Campuran', 'Gabungkan beberapa format jawaban dalam satu paket.'],
                            ] as const).map(([value, label, description]) => {
                                const disabled = value === 'mixed' && data.question_count < 2;

                                return <label key={value} className={`rounded-xl border p-3 ${disabled ? 'cursor-not-allowed bg-slate-50 opacity-50' : 'cursor-pointer'} ${data.answer_format === value ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200'}`}>
                                    <span className="flex items-center gap-2"><input type="radio" disabled={disabled} checked={data.answer_format === value} onChange={() => setData('answer_format', value)} className="border-slate-300 text-emerald-600 focus:ring-emerald-500" /><strong className="text-sm text-slate-900">{label}</strong></span>
                                    <span className="mt-1 block pl-6 text-xs leading-5 text-slate-500">{description}</span>
                                </label>;
                            })}
                        </div>
                        {data.question_count < 2 && <p className="mt-2 text-xs text-slate-500">Format Campuran tersedia jika membuat minimal 2 soal.</p>}
                        <InputError message={errors.answer_format} className="mt-1" />
                    </fieldset>}

                    <div className={`mt-5 grid gap-4 ${storyMode ? 'sm:grid-cols-3' : ''}`}>
                        {storyMode && <label className="block text-sm font-semibold text-slate-800">
                            Jumlah paragraf
                            <select
                                value={data.paragraph_count}
                                onChange={(event) => setData('paragraph_count', Number(event.target.value))}
                                className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                {[1, 2, 3, 4, 5].map((count) => <option key={count} value={count}>{count} paragraf</option>)}
                            </select>
                            <InputError message={errors.paragraph_count} className="mt-1" />
                        </label>}
                        {storyMode && <label className="block text-sm font-semibold text-slate-800">
                            Maksimal kata
                            <input
                                type="number"
                                min={50}
                                max={1000}
                                value={data.max_words}
                                onChange={(event) => setData('max_words', Number(event.target.value))}
                                className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <span className="mt-1 block text-xs font-normal text-slate-500">Default 200 kata untuk satu bacaan.</span>
                            <InputError message={errors.max_words} className="mt-1" />
                        </label>}
                        {!usesIndonesianBundle ? <label className="block text-sm font-semibold text-slate-800">
                            Jumlah soal
                            <select
                                value={data.question_count}
                                onChange={(event) => {
                                    const count = Number(event.target.value);
                                    setData((current) => ({
                                        ...current,
                                        question_count: count,
                                        answer_format: count === 1 && current.answer_format === 'mixed' ? 'single_choice' : current.answer_format,
                                    }));
                                }}
                                className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                {(storyMode ? [2, 3, 4] : Array.from({ length: 9 }, (_, index) => index + 1)).map((count) => <option key={count} value={count}>{count} soal</option>)}
                            </select>
                            <InputError message={errors.question_count} className="mt-1" />
                        </label> : <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4"><span className="text-xs font-bold uppercase tracking-wide text-emerald-700">Jumlah soal</span><strong className="mt-1 block text-emerald-950">3 soal dalam satu cerita</strong></div>}
                    </div>

                    <div className="mt-6 flex flex-wrap justify-end gap-3">
                        <Link href={route('questions.index')} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
                            Kembali
                        </Link>
                        <button disabled={processing || data.subject_id === 0 || data.root_competency_id === 0 || (!usesQuestionBlueprints && needsSubcompetency && !hasSelectedSubcompetency) || (usesIndonesianBundle && selectedBundleBlueprints.length !== 3)} className="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
                            {processing ? 'Mengirim permintaan...' : storyMode ? 'Buat Cerita & Soal' : 'Buat Soal AI'}
                        </button>
                    </div>
                </form>

                <aside className="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Permintaan terbaru</h2>
                    {recentGenerations.length === 0 ? (
                        <p className="mt-4 text-sm text-slate-500">Belum ada soal cerita yang dibuat.</p>
                    ) : (
                        <div className="mt-4 space-y-3">
                            {recentGenerations.map((generation) => (
                                <Link key={generation.id} href={route(generation.request_payload.format === 'direct' ? 'ai-questions.show' : 'story-questions.show', generation.id)} className="block rounded-xl border border-slate-200 p-4 hover:border-indigo-300">
                                    <div className="flex items-center justify-between gap-3">
                                        <p className="line-clamp-2 text-sm font-medium text-slate-900">{generation.result_payload?.title || generation.request_payload.theme}</p>
                                        <span className={`shrink-0 rounded-full px-2 py-1 text-xs font-semibold ${generation.status === 'completed' ? 'bg-emerald-50 text-emerald-700' : generation.status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'}`}>
                                            {generation.status}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-xs text-slate-500">
                                        {generation.request_payload.subject_name ? `${generation.request_payload.subject_name} · ` : ''}
                                        {generation.request_payload.paragraph_count ? `${generation.request_payload.paragraph_count} paragraf · ` : ''}
                                        {generation.result_payload?.question_count || generation.request_payload.question_count || '-'} soal
                                    </p>
                                </Link>
                            ))}
                        </div>
                    )}
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
