import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Question = {
    id: number;
    version: number;
    title?: string;
    prompt: string;
    type: string;
    status: string;
    grade_level: number;
    difficulty: number;
    variants_count: number;
    story_generation_id?: number;
    story_generation?: {
        id: number;
        request_payload: { theme: string; format?: 'direct' | 'story' };
        result_payload?: { title?: string };
    };
    bundle_question_count: number;
    bundle_draft_count: number;
    bundle_published_count: number;
    bundle_archived_count: number;
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

type CreateMethod = 'ai' | 'manual';

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
            <Modal show={showCreateOptions} maxWidth="lg" onClose={closeCreateOptions}>
                <div className="p-6 sm:p-7">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-sm font-semibold text-emerald-600">{createMethod ? 'Langkah 2 dari 2' : 'Langkah 1 dari 2'}</p>
                            <h2 className="mt-1 text-xl font-bold text-slate-900">{createMethod ? 'Pilih mata pelajaran' : 'Mau membuat soal dengan cara apa?'}</h2>
                            <p className="mt-2 text-sm leading-6 text-slate-500">
                                {createMethod
                                    ? `Mapel menentukan kompetensi dan format soal yang tersedia pada alur ${createMethod === 'ai' ? 'AI' : 'manual'}.`
                                    : 'Pilih bantuan AI untuk membuat paket soal cerita, atau tulis satu soal secara manual.'}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={closeCreateOptions}
                            aria-label="Tutup pilihan pembuatan soal"
                            className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        >
                            ×
                        </button>
                    </div>

                    {!createMethod ? (
                        <div className="mt-6 grid gap-4 sm:grid-cols-2">
                            <button
                                type="button"
                                onClick={() => setCreateMethod('ai')}
                                className="group rounded-xl border-2 border-indigo-200 bg-indigo-50/70 p-5 text-left transition hover:border-indigo-400 hover:bg-indigo-50"
                            >
                                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600 text-lg font-bold text-white">AI</div>
                                <h3 className="mt-4 font-bold text-slate-900 group-hover:text-indigo-700">Buat dengan AI</h3>
                                <p className="mt-2 text-sm leading-6 text-slate-600">AI membuat cerita beserta 2–4 soal berdasarkan mapel dan kompetensi yang dipilih.</p>
                                <span className="mt-4 inline-block text-sm font-semibold text-indigo-700">Pilih mapel →</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setCreateMethod('manual')}
                                className="group rounded-xl border-2 border-emerald-200 bg-emerald-50/70 p-5 text-left transition hover:border-emerald-400 hover:bg-emerald-50"
                            >
                                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-600 text-xl font-bold text-white">✎</div>
                                <h3 className="mt-4 font-bold text-slate-900 group-hover:text-emerald-700">Buat manual</h3>
                                <p className="mt-2 text-sm leading-6 text-slate-600">Tulis sendiri stimulus, tipe jawaban, kompetensi, kunci, serta pembahasan.</p>
                                <span className="mt-4 inline-block text-sm font-semibold text-emerald-700">Pilih mapel →</span>
                            </button>
                        </div>
                    ) : (
                        <div className="mt-6">
                            <button type="button" onClick={() => setCreateMethod(null)} className="text-sm font-semibold text-slate-600 hover:text-slate-900">← Kembali pilih metode</button>
                            {subjects.length === 0 ? (
                                <p className="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Belum ada mata pelajaran. Tambahkan mapel terlebih dahulu.</p>
                            ) : (
                                <div className="mt-4 grid max-h-80 gap-3 overflow-y-auto sm:grid-cols-2">
                                    {subjects.map((subject) => (
                                        <Link
                                            key={subject.id}
                                            href={route(createMethod === 'ai'
                                                ? subject.ai_question_format === 'story' ? 'story-questions.create' : 'ai-questions.create'
                                                : 'questions.create', { subject_id: subject.id })}
                                            className="rounded-xl border border-slate-200 p-4 transition hover:border-emerald-400 hover:bg-emerald-50"
                                        >
                                            <span className="text-xs font-bold uppercase tracking-wide text-emerald-600">{subject.code}</span>
                                            <h3 className="mt-1 font-semibold text-slate-900">{subject.name}</h3>
                                            <span className="mt-3 inline-block text-sm font-semibold text-slate-500">Pilih mapel →</span>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </Modal>
            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={filter} className="mb-5 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row">
                    <input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Cari judul atau pertanyaan"
                        className="flex-1 rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
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
                                        className={`block p-5 transition hover:bg-slate-50 ${bundled ? 'bg-indigo-50/30' : ''}`}
                                    >
                                        <div className="flex flex-col justify-between gap-3 sm:flex-row">
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {bundled ? (
                                                        <>
                                                            <span className="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-semibold text-indigo-700">Bundel cerita</span>
                                                            {question.bundle_draft_count > 0 && <span className="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">{question.bundle_draft_count} draft</span>}
                                                            {question.bundle_published_count > 0 && <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{question.bundle_published_count} terbit</span>}
                                                            {question.bundle_archived_count > 0 && <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{question.bundle_archived_count} arsip</span>}
                                                        </>
                                                    ) : (
                                                        <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${question.status === 'published' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                                                            {question.status}
                                                        </span>
                                                    )}
                                                    <span className="text-xs text-slate-500">Kelas {question.grade_level}</span>
                                                    {!bundled && <span className="text-xs text-slate-500">Kesulitan {question.difficulty}</span>}
                                                    {!bundled && question.version > 1 && <span className="text-xs font-semibold text-indigo-600">Versi {question.version}</span>}
                                                </div>
                                                <h2 className="mt-2 font-semibold text-slate-900">
                                                    {bundled
                                                        ? question.story_generation?.result_payload?.title || question.title || question.prompt
                                                        : question.title || question.prompt}
                                                </h2>
                                                <p className="mt-1 text-sm text-slate-500">
                                                    {bundled
                                                        ? `${question.bundle_question_count} soal dalam satu bundel · Tema: ${question.story_generation?.request_payload.theme}`
                                                        : `${question.competency.subject ? `${question.competency.subject.name} · ` : ''}${question.competency.code} · ${question.competency.name}`}
                                                </p>
                                            </div>
                                            <div className={`shrink-0 text-sm ${bundled ? 'font-semibold text-indigo-700' : 'text-slate-500'}`}>
                                                {bundled ? 'Buka bundel →' : `${question.variants_count} variasi`}
                                            </div>
                                        </div>
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
