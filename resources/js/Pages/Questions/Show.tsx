import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

type Option = { id: number; label: string; content: string; is_correct: boolean };
type MatchingPair = { left_id: string; left: string; right_id: string; right: string };
type MatchingDistractor = { id: string; content: string };
type Question = {
    id: number;
    version: number;
    revision_of_id?: number;
    superseded_by_id?: number;
    title?: string;
    stimulus?: string;
    prompt: string;
    explanation?: string;
    type: string;
    status: string;
    difficulty: number;
    grade_level: number;
    illustration_url?: string;
    metadata?: {
        accepted_answers?: string[];
        illustration?: { alt?: string };
        matching_pairs?: MatchingPair[];
        matching_distractors?: MatchingDistractor[];
        matrix_columns?: { id: string; label: string }[];
        matrix_rows?: { id: string; statement: string; correct_column_id: string }[];
    };
    options: Option[];
    competency: { code: string; domain: string; name: string };
    author: { name: string };
    variants: Question[];
    reviews: { id: number; source: string; status: string; score?: number; issues?: { severity: string; field: string; message: string }[]; suggestions?: string[]; reviewed_at: string }[];
    revision_of?: { id: number; title?: string; version: number; status: string };
    superseded_by?: { id: number; title?: string; version: number; status: string };
};

export default function Show({ question, latestGeneration }: { question: Question; latestGeneration?: { status: string; error?: string } }) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <p className="text-sm font-medium text-emerald-600">Detail soal #{question.id}</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">{question.title || 'Soal tanpa judul'}</h1>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('questions.edit', question.id)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Edit</Link>
                        {question.status !== 'published' && (
                            <button onClick={() => router.post(route('questions.approve', question.id))} className="rounded-lg border border-emerald-600 px-4 py-2 text-sm font-semibold text-emerald-700">Terbitkan</button>
                        )}
                        <button onClick={() => router.post(route('questions.duplicate', question.id))} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Duplikasi</button>
                        {question.status !== 'archived' && <button onClick={() => window.confirm('Arsipkan soal ini?') && router.post(route('questions.archive', question.id))} className="rounded-lg border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-700">Arsipkan</button>}
                        <button onClick={() => router.post(route('questions.ai-review.store', question.id))} className="rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700">Validasi AI</button>
                        {!['matching', 'category_matrix'].includes(question.type) && <button onClick={() => router.post(route('questions.ai-variants.store', question.id))} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Buat 3 variasi AI</button>}
                    </div>
                </div>
            }
        >
            <Head title={question.title || 'Detail Soal'} />
            <div className="mx-auto grid max-w-7xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[1fr_320px] lg:px-8">
                <div className="space-y-6">
                    <article className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="flex flex-wrap gap-2 text-xs font-semibold">
                            <span className="rounded-full bg-emerald-50 px-3 py-1 text-emerald-700">{question.status}</span>
                            <span className="rounded-full bg-indigo-50 px-3 py-1 text-indigo-700">Versi {question.version}</span>
                            <span className="rounded-full bg-slate-100 px-3 py-1 text-slate-600">Kelas {question.grade_level}</span>
                            <span className="rounded-full bg-slate-100 px-3 py-1 text-slate-600">Kesulitan {question.difficulty}</span>
                        </div>
                        {question.revision_of && (
                            <p className="mt-4 rounded-xl border border-indigo-100 bg-indigo-50 p-3 text-sm text-indigo-800">
                                Revisi dari <Link href={route('questions.show', question.revision_of.id)} className="font-semibold underline">versi {question.revision_of.version}</Link>. Versi lama tetap digunakan oleh paket yang sudah diterbitkan.
                            </p>
                        )}
                        {question.superseded_by && (
                            <p className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                Versi ini sudah digantikan oleh <Link href={route('questions.show', question.superseded_by.id)} className="font-semibold underline">versi {question.superseded_by.version}</Link>.
                            </p>
                        )}
                        {question.illustration_url && (
                            <img
                                src={question.illustration_url}
                                alt={question.metadata?.illustration?.alt || 'Ilustrasi soal'}
                                className="mt-6 aspect-video w-full rounded-xl border border-slate-200 object-cover"
                            />
                        )}
                        {question.stimulus && <div className="mt-6 whitespace-pre-wrap rounded-xl bg-slate-50 p-5 leading-7 text-slate-700">{question.stimulus}</div>}
                        <h2 className="mt-6 text-lg font-semibold leading-7 text-slate-900">{question.prompt}</h2>
                        {question.options.length > 0 && (
                            <div className="mt-5 space-y-3">
                                {question.options.map((option) => (
                                    <div key={option.id} className={`flex gap-3 rounded-xl border p-4 ${option.is_correct ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200'}`}>
                                        <span className="font-semibold">{option.label}.</span>
                                        <span>{option.content}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                        {question.type === 'matching' && question.metadata?.matching_pairs && (
                            <div className="mt-5 overflow-hidden rounded-xl border border-slate-200">
                                <div className="grid grid-cols-[1fr_40px_1fr] bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-700">
                                    <span>Lajur kiri</span><span /><span>Lajur kanan (kunci)</span>
                                </div>
                                {question.metadata.matching_pairs.map((pair, index) => (
                                    <div key={pair.left_id} className="grid grid-cols-[1fr_40px_1fr] items-center border-t border-slate-200 px-4 py-4 text-sm">
                                        <span className="text-slate-800">{pair.left}</span>
                                        <span className="text-center font-semibold text-emerald-600">→</span>
                                        <span className="font-medium text-emerald-800">{pair.right}</span>
                                    </div>
                                ))}
                                {(question.metadata.matching_distractors?.length || 0) > 0 && (
                                    <div className="border-t border-slate-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                        Distraktor: {question.metadata.matching_distractors?.map((item) => item.content).join(', ')}
                                    </div>
                                )}
                            </div>
                        )}
                        {question.type === 'category_matrix' && question.metadata?.matrix_columns && question.metadata?.matrix_rows && (
                            <div className="mt-5 overflow-x-auto rounded-xl border border-slate-200">
                                <table className="w-full min-w-[560px] text-sm">
                                    <thead className="bg-slate-100 text-slate-700"><tr><th className="p-4 text-left">Pernyataan</th>{question.metadata.matrix_columns.map((column) => <th key={column.id} className="p-4 text-center">{column.label}</th>)}</tr></thead>
                                    <tbody>{question.metadata.matrix_rows.map((row) => <tr key={row.id} className="border-t border-slate-200"><td className="p-4 text-slate-800">{row.statement}</td>{question.metadata?.matrix_columns?.map((column) => <td key={column.id} className="p-4 text-center"><span className={`inline-flex h-7 w-7 items-center justify-center rounded-full ${row.correct_column_id === column.id ? 'bg-emerald-600 text-white' : 'border border-slate-300 text-transparent'}`}>✓</span></td>)}</tr>)}</tbody>
                                </table>
                            </div>
                        )}
                        {question.metadata?.accepted_answers && <p className="mt-5 text-sm text-emerald-700">Jawaban diterima: {question.metadata.accepted_answers.join(', ')}</p>}
                        {question.explanation && <div className="mt-6 border-t border-slate-100 pt-5"><h3 className="text-sm font-semibold text-slate-900">Pembahasan</h3><p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-600">{question.explanation}</p></div>}
                    </article>

                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="flex items-center justify-between"><h2 className="text-lg font-semibold text-slate-900">Review kualitas</h2><span className="text-xs text-slate-500">Guru tetap peninjau akhir</span></div>
                        {question.reviews.length === 0 ? <p className="mt-4 text-sm text-slate-500">Belum ada hasil validasi.</p> : <div className="mt-4 space-y-4">{question.reviews.map((review) => <div key={review.id} className={`rounded-xl border p-4 ${review.status === 'passed' ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'}`}><div className="flex items-center justify-between"><p className="font-semibold text-slate-900">{review.source.toUpperCase()} · {review.status}</p><span className="text-sm font-bold text-slate-700">{review.score ?? '-'} / 100</span></div>{review.issues && review.issues.length > 0 && <ul className="mt-3 space-y-2 text-sm text-slate-700">{review.issues.map((issue, index) => <li key={index}><span className={`font-semibold ${issue.severity === 'error' ? 'text-rose-700' : 'text-amber-700'}`}>{issue.severity}</span> · {issue.field}: {issue.message}</li>)}</ul>}</div>)}</div>}
                    </section>

                    <section>
                        <div className="flex items-center justify-between">
                            <h2 className="text-lg font-semibold text-slate-900">Variasi soal</h2>
                            {latestGeneration && <span className="text-sm text-slate-500">AI: {latestGeneration.status}</span>}
                        </div>
                        {latestGeneration?.error && <p className="mt-2 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{latestGeneration.error}</p>}
                        <div className="mt-3 grid gap-3">
                            {question.variants.length === 0 ? (
                                <div className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">Belum ada variasi.</div>
                            ) : question.variants.map((variant) => (
                                <Link key={variant.id} href={route('questions.show', variant.id)} className="rounded-xl border border-slate-200 bg-white p-4 hover:border-emerald-300">
                                    <p className="font-medium text-slate-900">{variant.title || variant.prompt}</p>
                                    <p className="mt-1 text-xs text-slate-500">{variant.status} · kesulitan {variant.difficulty}</p>
                                </Link>
                            ))}
                        </div>
                    </section>
                </div>

                <aside className="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Metadata</h2>
                    <dl className="mt-4 space-y-4 text-sm">
                        <div><dt className="text-slate-500">Kompetensi</dt><dd className="mt-1 font-medium text-slate-900">{question.competency.code} · {question.competency.name}</dd></div>
                        <div><dt className="text-slate-500">Domain</dt><dd className="mt-1 text-slate-900">{question.competency.domain}</dd></div>
                        <div><dt className="text-slate-500">Bentuk</dt><dd className="mt-1 text-slate-900">{question.type}</dd></div>
                        <div><dt className="text-slate-500">Pembuat</dt><dd className="mt-1 text-slate-900">{question.author.name}</dd></div>
                    </dl>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
