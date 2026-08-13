import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Generation = {
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    request_payload: { subject_name?: string; theme: string; format?: 'direct' | 'story'; paragraph_count?: number; question_count?: number };
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
type QuestionBlueprint = { id: number; subject_id: number; code: string; name: string; description?: string; competency_ids: number[] };

export default function StoryCreate({ subjects, competencies, questionBlueprints, recentGenerations, selectedSubjectId, generationFormat }: { subjects: { id: number; code: string; name: string; ai_question_format: 'direct' | 'story' }[]; competencies: Competency[]; questionBlueprints: QuestionBlueprint[]; recentGenerations: Generation[]; selectedSubjectId?: number | null; generationFormat: 'direct' | 'story' }) {
    const storyMode = generationFormat === 'story';
    const { data, setData, post, processing, errors } = useForm({
        subject_id: selectedSubjectId || 0,
        root_competency_id: 0,
        competency_id: 0,
        question_blueprint_ids: [] as number[],
        theme: '',
        question_style: 'direct' as 'direct' | 'reasoning',
        answer_format: 'single_choice' as 'single_choice' | 'true_false' | 'multiple_choice' | 'mixed',
        use_illustration: false,
        paragraph_count: 3,
        question_count: 3,
    });
    const availableCompetencies = competencies.filter(
        (competency) => competency.subject_id === data.subject_id && !competency.parent_id,
    );
    const availableSubcompetencies = competencies.filter(
        (competency) => competency.parent_id === data.root_competency_id,
    );
    const selectedSubject = subjects.find((subject) => subject.id === data.subject_id);
    const usesQuestionBlueprints = selectedSubject?.code === 'BIND';
    const availableQuestionBlueprints = questionBlueprints.filter((item) => item.subject_id === data.subject_id);
    const needsSubcompetency = availableSubcompetencies.length > 0;
    const hasSelectedSubcompetency = availableSubcompetencies.some(
        (competency) => competency.id === data.competency_id,
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
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
                        <h2 className="font-semibold text-indigo-950">{storyMode ? 'Tentukan tema dan panjang paket' : 'Tentukan topik latihan'}</h2>
                        <p className="mt-2 text-sm leading-6 text-indigo-800">
                            {storyMode
                                ? 'AI menulis satu cerita, lalu membuat beberapa soal dari stimulus yang sama.'
                                : 'AI membuat soal langsung sesuai kompetensi tanpa mewajibkan cerita atau stimulus panjang.'}
                        </p>
                    </div>

                    <label className="mt-6 block text-sm font-semibold text-slate-800">
                        Mata pelajaran
                        <select value={data.subject_id} onChange={(event) => setData((current) => ({ ...current, subject_id: Number(event.target.value), root_competency_id: 0, competency_id: 0, question_blueprint_ids: [] }))} className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                            <option value={0}>{subjects.length === 0 ? 'Belum ada mapel dengan kompetensi' : 'Pilih mata pelajaran terlebih dahulu'}</option>
                            {subjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.code} · {subject.name}</option>)}
                        </select>
                        <InputError message={errors.subject_id} className="mt-1" />
                    </label>

                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        <label className="block text-sm font-semibold text-slate-800">
                            Kompetensi
                            <select
                                value={data.root_competency_id}
                                onChange={(event) => {
                                    const rootId = Number(event.target.value);
                                    const rootHasChildren = competencies.some((competency) => competency.parent_id === rootId);
                                    const defaults = questionBlueprints.filter((item) => item.competency_ids.includes(rootId)).map((item) => item.id);
                                    setData((current) => ({ ...current, root_competency_id: rootId, competency_id: usesQuestionBlueprints || !rootHasChildren ? rootId : 0, question_blueprint_ids: defaults }));
                                }}
                                className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value={0}>Pilih kompetensi</option>
                                {availableCompetencies.map((competency) => <option key={competency.id} value={competency.id}>Kelas {competency.grade_level} · {competency.code} · {competency.name}</option>)}
                            </select>
                            <InputError message={errors.root_competency_id} className="mt-1" />
                        </label>

                        {!usesQuestionBlueprints && <label className="block text-sm font-semibold text-slate-800">
                            Subkompetensi {needsSubcompetency ? '' : <span className="font-normal text-slate-500">(tidak tersedia)</span>}
                            <select
                                value={hasSelectedSubcompetency ? data.competency_id : 0}
                                onChange={(event) => setData('competency_id', Number(event.target.value))}
                                disabled={!data.root_competency_id || !needsSubcompetency}
                                className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-slate-100"
                            >
                                <option value={0}>{needsSubcompetency ? 'Pilih subkompetensi' : 'Gunakan kompetensi utama'}</option>
                                {availableSubcompetencies.map((competency) => <option key={competency.id} value={competency.id}>{competency.code} · {competency.name}</option>)}
                            </select>
                            <InputError message={errors.competency_id} className="mt-1" />
                        </label>}
                    </div>

                    {usesQuestionBlueprints && data.root_competency_id > 0 && <fieldset className="mt-5 rounded-xl border border-indigo-200 bg-indigo-50 p-5"><legend className="px-2 text-sm font-bold text-indigo-950">Tipe soal <span className="font-normal text-indigo-700">(default kompetensi, dapat disesuaikan)</span></legend><div className="grid gap-2 sm:grid-cols-2">{availableQuestionBlueprints.map((blueprint) => <label key={blueprint.id} className="flex items-start gap-3 rounded-lg bg-white p-3"><input type="checkbox" checked={data.question_blueprint_ids.includes(blueprint.id)} onChange={() => setData('question_blueprint_ids', data.question_blueprint_ids.includes(blueprint.id) ? data.question_blueprint_ids.filter((id) => id !== blueprint.id) : [...data.question_blueprint_ids, blueprint.id])} className="mt-0.5 rounded border-slate-300 text-indigo-600" /><span><strong className="block text-sm text-slate-900">{blueprint.name}</strong>{blueprint.description && <span className="mt-1 block text-xs leading-5 text-slate-500">{blueprint.description}</span>}</span></label>)}</div>{availableQuestionBlueprints.length === 0 && <p className="text-sm text-amber-700">Kompetensi ini belum memiliki katalog tipe soal. Anda tetap dapat membuat soal berdasarkan kompetensi.</p>}<InputError message={errors.question_blueprint_ids} className="mt-2" /></fieldset>}

                    <label className="mt-5 block text-sm font-semibold text-slate-800">
                        {storyMode ? 'Tema cerita' : <>Contoh soal untuk dibuat variasinya <span className="font-normal text-slate-500">(opsional)</span></>}
                        <textarea
                            autoFocus
                            value={data.theme}
                            onChange={(event) => setData('theme', event.target.value)}
                            rows={4}
                            maxLength={255}
                            placeholder={storyMode ? 'Contoh: menjaga kebersihan sungai di lingkungan desa' : 'Contoh: 2/3 + 1/4 = … (boleh dikosongkan agar AI membuat soal dari subkompetensi)'}
                            className="mt-2 block w-full rounded-xl border-slate-300 text-base focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <span className="mt-1 block text-right text-xs font-normal text-slate-400">{data.theme.length}/255</span>
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
                                        <span className="flex items-center gap-2"><input type="radio" checked={data.use_illustration === value} onChange={() => setData((current) => ({ ...current, use_illustration: value, question_count: value ? 1 : current.question_count }))} className="border-slate-300 text-sky-600 focus:ring-sky-500" /><strong className="text-sm text-slate-900">{label}</strong></span>
                                        <span className="mt-1 block pl-6 text-xs leading-5 text-slate-500">{description}</span>
                                    </label>
                                ))}
                            </div>
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

                    <div className={`mt-5 grid gap-4 ${storyMode ? 'sm:grid-cols-2' : ''}`}>
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
                        <label className="block text-sm font-semibold text-slate-800">
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
                        </label>
                    </div>

                    <div className="mt-6 flex flex-wrap justify-end gap-3">
                        <Link href={route('questions.index')} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
                            Kembali
                        </Link>
                        <button disabled={processing || data.subject_id === 0 || data.root_competency_id === 0 || (!usesQuestionBlueprints && needsSubcompetency && !hasSelectedSubcompetency) || (storyMode && data.theme.trim().length === 0)} className="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
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
