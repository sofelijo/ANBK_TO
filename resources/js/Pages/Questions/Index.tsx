import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Question = {
    id: number;
    version: number;
    title?: string;
    stimulus?: string;
    prompt: string;
    type: string;
    status: string;
    grade_level: number;
    difficulty: number;
    variants_count: number;
    verifications_count: number;
    story_generation_id?: number;
    story_generation?: {
        id: number;
        request_payload: { theme: string; format?: 'direct' | 'story' };
        result_payload?: { title?: string; story?: string };
    };
    bundle_question_count: number;
    bundle_draft_count: number;
    bundle_review_count: number;
    bundle_published_count: number;
    bundle_archived_count: number;
    bundle_verifications_count: number;
    bundle_questions: { id: number }[];
    competency: { code: string; name: string; subject?: { code: string; name: string } };
    author: { name: string };
};

type Props = {
    questions: {
        data: Question[];
        links: { url?: string; label: string; active: boolean }[];
    };
    subjects: { id: number; code: string; name: string; ai_question_format: 'direct' | 'story' }[];
    filters: { search?: string; status?: string; subject_id?: string };
};

type CreateMethod = 'ai' | 'manual' | 'json';

export default function Index({ questions, subjects, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');
    const [subjectId, setSubjectId] = useState(filters.subject_id || '');
    const [showCreateOptions, setShowCreateOptions] = useState(false);
    const [createMethod, setCreateMethod] = useState<CreateMethod | null>(null);

    const closeCreateOptions = () => {
        setShowCreateOptions(false);
        setCreateMethod(null);
    };

    const filter = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('questions.index'), { search, status, subject_id: subjectId }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <p className="text-sm font-medium text-emerald-600">Konten</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">Bank Soal</h1>
                    </div>
                    <div className="flex gap-2">
                        <Link href={route('questions.import.create')} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Impor Excel</Link>
                        <button
                            type="button"
                            onClick={() => setShowCreateOptions(true)}
                            className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500"
                        >
                            Buat soal
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="Bank Soal" />
            <Modal show={showCreateOptions} maxWidth="4xl" onClose={closeCreateOptions}>
                <div className="p-5 sm:p-8">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <div className="mb-3 flex items-center gap-2">
                                {[1, 2].map((step) => <span key={step} className={`h-1.5 rounded-full transition-all ${step <= (createMethod ? 2 : 1) ? 'w-10 bg-emerald-500' : 'w-6 bg-slate-200'}`} />)}
                                <span className="ml-1 text-xs font-bold uppercase tracking-wider text-emerald-600">Langkah {createMethod ? 2 : 1} dari 2</span>
                            </div>
                            <h2 className="text-2xl font-bold tracking-tight text-slate-900">{createMethod ? 'Pilih mata pelajaran' : 'Pilih cara membuat soal'}</h2>
                            <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
                                {createMethod
                                    ? `Mapel menentukan kompetensi dan format soal yang tersedia pada alur ${createMethod === 'ai' ? 'AI' : createMethod === 'json' ? 'prompt JSON' : 'manual'}.`
                                    : 'Gunakan AI TOA, susun secara manual, atau buat prompt JSON untuk diproses melalui ChatGPT.'}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={closeCreateOptions}
                            aria-label="Tutup pilihan pembuatan soal"
                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xl text-slate-400 transition hover:bg-slate-200 hover:text-slate-700"
                        >
                            ×
                        </button>
                    </div>

                    {!createMethod ? (
                        <div className="mt-7 grid gap-4 md:grid-cols-3">
                            <button
                                type="button"
                                onClick={() => setCreateMethod('ai')}
                                className="group flex min-h-64 flex-col rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-lg"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold text-white shadow-sm shadow-indigo-200">AI</div>
                                    <span className="rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-indigo-700">Otomatis</span>
                                </div>
                                <h3 className="mt-5 text-lg font-bold text-slate-900 group-hover:text-indigo-700">Buat dengan AI</h3>
                                <p className="mt-2 text-sm leading-6 text-slate-500">AI TOA langsung menyusun soal berdasarkan kompetensi dan pengaturan Anda.</p>
                                <p className="mt-3 text-xs font-medium text-slate-400">Bahasa Indonesia: 1 bacaan + 3 soal</p>
                                <span className="mt-auto flex items-center gap-2 border-t border-slate-100 pt-4 text-sm font-bold text-indigo-700">Lanjut pilih mapel <span aria-hidden="true">→</span></span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setCreateMethod('manual')}
                                className="group flex min-h-64 flex-col rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition duration-200 hover:-translate-y-1 hover:border-emerald-300 hover:shadow-lg"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-600 text-2xl font-bold text-white shadow-sm shadow-emerald-200">✎</div>
                                    <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-emerald-700">Mandiri</span>
                                </div>
                                <h3 className="mt-5 text-lg font-bold text-slate-900 group-hover:text-emerald-700">Tulis manual</h3>
                                <p className="mt-2 text-sm leading-6 text-slate-500">Tulis pertanyaan, pilihan jawaban, dan pembahasan sepenuhnya sendiri.</p>
                                <p className="mt-3 text-xs font-medium text-slate-400">Kontrol penuh atas setiap isi soal</p>
                                <span className="mt-auto flex items-center gap-2 border-t border-slate-100 pt-4 text-sm font-bold text-emerald-700">Lanjut pilih mapel <span aria-hidden="true">→</span></span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setCreateMethod('json')}
                                className="group flex min-h-64 flex-col rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition duration-200 hover:-translate-y-1 hover:border-amber-300 hover:shadow-lg"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-500 font-mono text-sm font-bold text-white shadow-sm shadow-amber-200">{'{ }'}</div>
                                    <span className="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-amber-700">Eksternal</span>
                                </div>
                                <h3 className="mt-5 text-lg font-bold text-slate-900 group-hover:text-amber-700">Prompt JSON</h3>
                                <p className="mt-2 text-sm leading-6 text-slate-500">TOA menyiapkan prompt terstruktur untuk disalin dan diproses di ChatGPT.</p>
                                <p className="mt-3 text-xs font-medium text-slate-400">Tidak menggunakan kuota AI TOA</p>
                                <span className="mt-auto flex items-center gap-2 border-t border-slate-100 pt-4 text-sm font-bold text-amber-700">Lanjut pilih mapel <span aria-hidden="true">→</span></span>
                            </button>
                        </div>
                    ) : (
                        <div className="mt-6">
                            <button type="button" onClick={() => setCreateMethod(null)} className="inline-flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">← Kembali pilih metode</button>
                            {subjects.length === 0 ? (
                                <p className="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Belum ada mata pelajaran. Tambahkan mapel terlebih dahulu.</p>
                            ) : (
                                <div className="mt-4 grid max-h-96 gap-3 overflow-y-auto pr-1 sm:grid-cols-2 lg:grid-cols-3">
                                    {subjects.map((subject) => (
                                        <Link
                                            key={subject.id}
                                            href={route(createMethod === 'ai'
                                                ? subject.ai_question_format === 'story' ? 'story-questions.create' : 'ai-questions.create'
                                                : createMethod === 'json'
                                                    ? 'json-questions.create'
                                                    : subject.code === 'BIND' ? 'manual-story-bundles.create' : 'questions.create', { subject_id: subject.id })}
                                            className="group rounded-xl border border-slate-200 bg-white p-4 transition hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-md"
                                        >
                                            <span className="text-xs font-bold uppercase tracking-wide text-emerald-600">{subject.code}</span>
                                            <h3 className="mt-1 font-semibold text-slate-900">{subject.name}</h3>
                                            {subject.code === 'BIND' && <span className="mt-2 block text-xs font-medium text-indigo-600">1 bacaan · 3 soal · Level 1–3</span>}
                                            <span className="mt-3 inline-block text-sm font-semibold text-slate-500 group-hover:text-emerald-700">Pilih mapel →</span>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </Modal>
            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={filter} className="mb-5 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:flex-wrap">
                    <input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Cari ID, judul, atau pertanyaan"
                        className="min-w-0 flex-1 rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                    />
                    <select value={subjectId} onChange={(event) => setSubjectId(event.target.value)} className="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">Semua mata pelajaran</option>
                        {subjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.code} · {subject.name}</option>)}
                    </select>
                    <select
                        value={status}
                        onChange={(event) => setStatus(event.target.value)}
                        className="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                    >
                        <option value="">Semua status</option>
                        <option value="draft">Draft</option>
                        <option value="review">Menunggu verifikasi</option>
                        <option value="published">Terbit</option>
                        <option value="archived">Arsip</option>
                    </select>
                    <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Terapkan</button>
                </form>

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                    {questions.data.length === 0 ? (
                        <p className="p-8 text-center text-sm text-slate-500">Belum ada soal.</p>
                    ) : (
                        <div className="divide-y divide-slate-100">
                            {questions.data.map((question) => {
                                const bundled = Boolean(
                                    question.story_generation_id
                                    && question.story_generation
                                    && question.story_generation.request_payload.format !== 'direct',
                                );

                                return (
                                    <Link
                                        key={bundled ? `bundle-${question.story_generation_id}` : question.id}
                                        href={bundled
                                            ? route('story-questions.show', question.story_generation_id)
                                            : route('questions.show', question.id)}
                                        className={`group block p-4 transition hover:bg-slate-50 sm:px-5 ${bundled ? 'bg-indigo-50/30' : ''}`}
                                    >
                                        {bundled ? (
                                            <div>
                                                <div className="flex items-center justify-between gap-3">
                                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                        <span className="text-xs font-bold uppercase tracking-wide text-indigo-600">Bundel cerita · {question.bundle_question_count} soal</span>
                                                        <span className="text-xs font-medium text-slate-500">
                                                            ID {question.bundle_questions.map((bundleQuestion) => `#${bundleQuestion.id}`).join(', ')}
                                                        </span>
                                                    </div>
                                                    <span className="shrink-0 text-xs font-semibold text-blue-600">{question.bundle_verifications_count}/{question.bundle_question_count * 3} verifikasi</span>
                                                </div>
                                                {question.story_generation?.result_payload?.title && (
                                                    <h2 className="mt-2 text-sm font-semibold text-slate-700 group-hover:text-indigo-700">
                                                        {question.story_generation.result_payload.title}
                                                    </h2>
                                                )}
                                                <p className="mt-2 line-clamp-3 whitespace-pre-line rounded-lg border-l-4 border-indigo-500 bg-indigo-50/80 px-4 py-3 text-base font-medium leading-relaxed text-slate-900 transition group-hover:bg-indigo-50">
                                                    {question.story_generation?.result_payload?.story || question.stimulus || 'Stimulus belum tersedia.'}
                                                </p>
                                                {question.story_generation?.request_payload.theme && (
                                                    <p className="mt-1 line-clamp-1 text-sm text-slate-600">Tema: {question.story_generation.request_payload.theme}</p>
                                                )}
                                                <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-indigo-100 pt-2 text-xs text-slate-500">
                                                    <span>{question.competency.subject?.name || question.competency.code}</span>
                                                    <span>Kelas {question.grade_level}</span>
                                                    {question.bundle_draft_count > 0 && <span className="text-amber-700">{question.bundle_draft_count} draft</span>}
                                                    {question.bundle_review_count > 0 && <span className="text-blue-700">{question.bundle_review_count} menunggu</span>}
                                                    {question.bundle_published_count > 0 && <span className="text-emerald-700">{question.bundle_published_count} terbit</span>}
                                                    <span className="ml-auto font-semibold text-indigo-700">Buka bundel →</span>
                                                </div>
                                            </div>
                                        ) : (
                                            <div>
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-xs font-bold uppercase tracking-wide text-emerald-700">
                                                            {question.competency.subject?.name || question.competency.code}
                                                        </span>
                                                        <span className="text-xs font-semibold text-slate-400">ID #{question.id}</span>
                                                    </div>
                                                    <div className="flex items-center gap-2 text-xs">
                                                        <span className={`rounded-full px-2 py-0.5 font-semibold ${question.status === 'published' ? 'bg-emerald-50 text-emerald-700' : question.status === 'review' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700'}`}>
                                                            {question.status === 'review' ? 'Menunggu verifikasi' : question.status}
                                                        </span>
                                                        <span className="font-semibold text-blue-600">{question.verifications_count}{question.status === 'published' ? '' : '/3'}</span>
                                                    </div>
                                                </div>

                                                {question.title && question.title !== question.prompt && (
                                                    <p className="mt-2 line-clamp-1 text-xs font-medium text-slate-500">{question.title}</p>
                                                )}
                                                <h2 className="mt-1 rounded-lg border-l-4 border-emerald-500 bg-emerald-50/70 px-4 py-3 text-base font-bold leading-relaxed text-slate-900 transition group-hover:bg-emerald-50 sm:text-lg">
                                                    {question.prompt}
                                                </h2>

                                                <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                                    <span className="max-w-xl truncate">{question.competency.name}</span>
                                                    <span>· Kelas {question.grade_level}</span>
                                                    <span>· Level {question.difficulty}</span>
                                                    {question.version > 1 && <span>· Versi {question.version}</span>}
                                                    <span className="hidden sm:inline">· {question.author.name}</span>
                                                    <span className="ml-auto font-semibold text-emerald-700">Lihat detail →</span>
                                                </div>
                                            </div>
                                        )}
                                    </Link>
                                );
                            })}
                        </div>
                    )}
                </div>

                <div className="mt-5 flex flex-wrap gap-2">
                    {questions.links.map((link, index) => (
                        <button
                            key={index}
                            disabled={!link.url}
                            onClick={() => link.url && router.get(link.url)}
                            className={`rounded-lg border px-3 py-2 text-sm ${link.active ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-600'} disabled:opacity-40`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
