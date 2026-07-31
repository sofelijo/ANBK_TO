import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Question = {
    id: number;
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
        request_payload: { theme: string };
        result_payload?: { title?: string };
    };
    bundle_question_count: number;
    bundle_draft_count: number;
    bundle_published_count: number;
    bundle_archived_count: number;
    competency: { code: string; name: string };
    author: { name: string };
};

type Props = {
    questions: {
        data: Question[];
        links: { url?: string; label: string; active: boolean }[];
    };
    filters: { search?: string; status?: string };
};

export default function Index({ questions, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');

    const filter = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('questions.index'), { search, status }, { preserveState: true });
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
                        <Link href={route('story-questions.create')} className="rounded-lg border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100">Soal Cerita AI</Link>
                        <Link href={route('questions.create')} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Tambah soal</Link>
                    </div>
                </div>
            }
        >
            <Head title="Bank Soal" />
            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={filter} className="mb-5 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row">
                    <input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Cari judul atau pertanyaan"
                        className="flex-1 rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                    />
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
                                const bundled = Boolean(question.story_generation_id && question.story_generation);

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
                                                </div>
                                                <h2 className="mt-2 font-semibold text-slate-900">
                                                    {bundled
                                                        ? question.story_generation?.result_payload?.title || question.title || question.prompt
                                                        : question.title || question.prompt}
                                                </h2>
                                                <p className="mt-1 text-sm text-slate-500">
                                                    {bundled
                                                        ? `${question.bundle_question_count} soal dalam satu cerita · Tema: ${question.story_generation?.request_payload.theme}`
                                                        : `${question.competency.code} · ${question.competency.name}`}
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
