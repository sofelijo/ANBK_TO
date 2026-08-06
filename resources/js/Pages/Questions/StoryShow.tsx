import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Generation = {
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    model: string;
    request_payload: { theme: string; paragraph_count?: number; question_count?: number };
    result_payload?: { title: string; story: string; paragraph_count?: number; question_count: number };
    input_tokens: number;
    output_tokens: number;
    cost_microusd: number;
    error?: string;
};

type Question = {
    id: number;
    title?: string;
    prompt: string;
    type: string;
    status: string;
    difficulty: number;
    grade_level: number;
    explanation?: string;
    illustration_url?: string;
    metadata?: {
        accepted_answers?: string[];
        illustration?: { alt?: string };
        matching_pairs?: { left_id: string; left: string; right_id: string; right: string }[];
        matching_distractors?: { id: string; content: string }[];
        matrix_columns?: { id: string; label: string }[];
        matrix_rows?: { id: string; statement: string; correct_column_id: string }[];
    };
    competency: { code: string; name: string; grade_level: number };
    author: { name: string };
    approver?: { name: string };
    approved_at?: string;
    options: { id: number; label: string; content: string; is_correct: boolean }[];
};

type Illustration = {
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    model: string;
    result_payload?: { batch_state?: string };
    cost_microusd: number;
    error?: string;
};

export default function StoryShow({ generation, questions, illustration }: { generation: Generation; questions: Question[]; illustration?: Illustration }) {
    const waiting = generation.status === 'pending' || generation.status === 'processing';
    const illustrationWaiting = illustration?.status === 'pending' || illustration?.status === 'processing';
    const [publishing, setPublishing] = useState(false);
    const publishedCount = questions.filter((question) => question.status === 'published').length;
    const unpublishedCount = questions.length - publishedCount;
    const allPublished = questions.length > 0 && unpublishedCount === 0;

    const publishBundle = () => {
        if (allPublished || publishing) return;
        if (!window.confirm(`Terbitkan seluruh ${questions.length} soal dalam bundle ini? Pastikan cerita, kunci jawaban, dan pembahasannya sudah diperiksa.`)) return;

        router.post(route('story-questions.publish', generation.id), {}, {
            preserveScroll: true,
            onStart: () => setPublishing(true),
            onFinish: () => setPublishing(false),
        });
    };

    useEffect(() => {
        if (!waiting && !illustrationWaiting) return;

        const timer = window.setInterval(() => {
            router.reload({ only: ['generation', 'questions', 'illustration'] });
        }, waiting ? 2000 : 15000);

        return () => window.clearInterval(timer);
    }, [waiting, illustrationWaiting]);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <p className="text-sm font-medium text-indigo-600">Paket Soal Cerita AI</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">{generation.result_payload?.title || generation.request_payload.theme}</h1>
                    </div>
                    <div className="flex gap-2">
                        <Link href={route('story-questions.create')} className="rounded-lg border border-indigo-300 bg-white px-4 py-2 text-sm font-semibold text-indigo-700">Buat Tema Baru</Link>
                        <Link href={route('questions.index')} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Bank Soal</Link>
                    </div>
                </div>
            }
        >
            <Head title="Hasil Soal Cerita AI" />
            <div className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
                {waiting && (
                    <div className="rounded-2xl border border-amber-200 bg-amber-50 p-8 text-center">
                        <div className="mx-auto h-8 w-8 animate-spin rounded-full border-4 border-amber-200 border-t-amber-600" />
                        <h2 className="mt-4 font-semibold text-amber-950">AI sedang menulis cerita dan soal</h2>
                        <p className="mt-1 text-sm text-amber-800">Halaman diperbarui otomatis. Biasanya selesai dalam beberapa detik.</p>
                    </div>
                )}

                {generation.status === 'failed' && (
                    <div className="rounded-2xl border border-rose-200 bg-rose-50 p-6">
                        <h2 className="font-semibold text-rose-900">Pembuatan gagal</h2>
                        <p className="mt-2 text-sm text-rose-700">{generation.error || 'AI tidak dapat menghasilkan paket soal yang valid.'}</p>
                        <button onClick={() => router.post(route('story-questions.retry', generation.id))} className="mt-4 rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-600">
                            Coba Proses Lagi
                        </button>
                    </div>
                )}

                {generation.status === 'completed' && generation.result_payload && (
                    <>
                        <article className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-indigo-600">Tema: {generation.request_payload.theme}</p>
                                    <h2 className="mt-1 text-xl font-bold text-slate-900">{generation.result_payload.title}</h2>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <span className="rounded-full bg-indigo-50 px-3 py-1 text-sm font-semibold text-indigo-700">{generation.result_payload.paragraph_count || generation.request_payload.paragraph_count || '-'} paragraf</span>
                                    <span className={`rounded-full px-3 py-1 text-sm font-semibold ${allPublished ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                                        {allPublished ? `${publishedCount} soal terbit` : `${unpublishedCount} belum terbit · ${publishedCount} terbit`}
                                    </span>
                                </div>
                            </div>
                            {questions[0]?.illustration_url && (
                                <img
                                    src={questions[0].illustration_url}
                                    alt={questions[0].metadata?.illustration?.alt || 'Ilustrasi soal cerita'}
                                    className="mt-5 aspect-video w-full rounded-xl border border-slate-200 object-cover"
                                />
                            )}
                            <div className="mt-5 whitespace-pre-wrap rounded-xl bg-slate-50 p-5 leading-7 text-slate-700">{generation.result_payload.story}</div>
                            <p className="mt-4 text-xs text-slate-500">Model {generation.model} · {generation.input_tokens + generation.output_tokens} token · estimasi US${(generation.cost_microusd / 1_000_000).toFixed(6)}</p>
                        </article>

                        <div className="flex flex-col justify-between gap-4 rounded-xl border border-indigo-200 bg-indigo-50 p-4 sm:flex-row sm:items-center">
                            <p className="text-sm text-indigo-900">
                                AI sudah memilih kompetensi dan jenjang dari data sekolah. Periksa cerita, kunci, dan pembahasan setiap soal sebelum menerbitkannya.
                            </p>
                            <button
                                type="button"
                                onClick={publishBundle}
                                disabled={allPublished || publishing || questions.length === 0}
                                className="shrink-0 rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-600 disabled:cursor-not-allowed disabled:bg-emerald-600"
                            >
                                {publishing ? 'Menerbitkan...' : allPublished ? 'Bundle Sudah Terbit' : 'Verifikasi & Terbitkan 1 Bundle'}
                            </button>
                        </div>

                        <section className="space-y-4">
                            {questions.map((question, index) => (
                                <article key={question.id} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                                    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                        <div>
                                            <div className="flex flex-wrap gap-2 text-xs font-semibold">
                                                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">Soal {index + 1}</span>
                                                <span className="rounded-full bg-indigo-50 px-2.5 py-1 text-indigo-700">{question.competency.code}</span>
                                                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">Kelas {question.grade_level}</span>
                                                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">Kesulitan {question.difficulty}</span>
                                                <span className={`rounded-full px-2.5 py-1 ${question.status === 'published' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                                                    {question.status === 'published' ? 'Terbit' : 'Belum terbit'}
                                                </span>
                                            </div>
                                            <h3 className="mt-3 text-lg font-semibold text-slate-900">{question.prompt}</h3>
                                        </div>
                                        <Link href={route('questions.edit', question.id)} className="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-indigo-500">
                                            Tinjau & Edit
                                        </Link>
                                    </div>

                                    {question.type === 'category_matrix' && question.metadata?.matrix_columns && question.metadata?.matrix_rows ? (
                                        <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200">
                                            <table className="w-full min-w-[520px] text-sm"><thead className="bg-slate-100"><tr><th className="p-3 text-left">Pernyataan</th>{question.metadata.matrix_columns.map((column) => <th key={column.id} className="p-3 text-center">{column.label}</th>)}</tr></thead><tbody>{question.metadata.matrix_rows.map((row) => <tr key={row.id} className="border-t border-slate-200"><td className="p-3">{row.statement}</td>{question.metadata?.matrix_columns?.map((column) => <td key={column.id} className="p-3 text-center">{row.correct_column_id === column.id ? '✓' : '○'}</td>)}</tr>)}</tbody></table>
                                        </div>
                                    ) : question.type === 'matching' && question.metadata?.matching_pairs ? (
                                        <div className="mt-4 overflow-hidden rounded-xl border border-slate-200">
                                            {question.metadata.matching_pairs.map((pair) => (
                                                <div key={pair.left_id} className="grid grid-cols-[1fr_32px_1fr] items-center border-b border-slate-100 p-3 text-sm last:border-b-0">
                                                    <span>{pair.left}</span><span className="text-center text-emerald-600">→</span><span className="font-medium text-emerald-800">{pair.right}</span>
                                                </div>
                                            ))}
                                            {(question.metadata.matching_distractors?.length || 0) > 0 && <p className="border-t border-amber-100 bg-amber-50 p-3 text-sm text-amber-800">Distraktor: {question.metadata.matching_distractors?.map((item) => item.content).join(', ')}</p>}
                                        </div>
                                    ) : question.options.length > 0 ? (
                                        <div className="mt-4 grid gap-2 sm:grid-cols-2">
                                            {question.options.map((option) => (
                                                <div key={option.id} className={`rounded-lg border p-3 text-sm ${option.is_correct ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 text-slate-700'}`}>
                                                    <span className="mr-2 font-semibold">{option.label}.</span>{option.content}
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">Jawaban diterima: {question.metadata?.accepted_answers?.join(', ')}</p>
                                    )}

                                    {question.explanation && <p className="mt-4 border-t border-slate-100 pt-4 text-sm leading-6 text-slate-600"><span className="font-semibold text-slate-800">Pembahasan:</span> {question.explanation}</p>}
                                    <dl className="mt-4 grid gap-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-2">
                                        <div><dt className="text-slate-500">Pembuat</dt><dd className="mt-1 font-medium text-slate-800">{question.author.name}</dd></div>
                                        <div><dt className="text-slate-500">Verifikator</dt><dd className="mt-1 font-medium text-slate-800">{question.approver?.name || 'Belum diverifikasi'}</dd></div>
                                    </dl>
                                </article>
                            ))}
                        </section>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
