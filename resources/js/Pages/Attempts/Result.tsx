import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

type Result = { id: number; percentage: number; correct_count: number; question_count: number; competency: { code: string; domain: string; name: string } };
type Recommendation = { id: number; reason: string; competency: { code: string; name: string }; question: { id: number; prompt: string; stimulus?: string } };
type Attempt = { score: number; max_score: number; summary: string; assessment: { title: string }; competency_results: Result[]; recommendations: Recommendation[] };

export default function ResultPage({ attempt }: { attempt: Attempt }) {
    const percentage = attempt.max_score > 0 ? Math.round((attempt.score / attempt.max_score) * 100) : 0;
    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Hasil Try Out</p><h1 className="mt-1 text-2xl font-bold text-slate-900">{attempt.assessment.title}</h1></div>}>
            <Head title="Hasil Try Out" />
            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6">
                <section className="grid gap-5 rounded-2xl bg-slate-900 p-8 text-white md:grid-cols-[180px_1fr] md:items-center">
                    <div><p className="text-sm text-slate-300">Capaian</p><p className="mt-1 text-5xl font-bold text-emerald-400">{percentage}%</p><p className="mt-2 text-sm text-slate-400">{attempt.score} dari {attempt.max_score} poin</p></div>
                    <div><h2 className="font-semibold">Ringkasan belajar</h2><p className="mt-2 leading-7 text-slate-300">{attempt.summary}</p></div>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-900">Peta kompetensi</h2>
                    <div className="mt-5 space-y-5">
                        {attempt.competency_results.map((result) => (
                            <div key={result.id}>
                                <div className="flex justify-between gap-4 text-sm"><span className="font-medium text-slate-800">{result.competency.name}</span><span className="text-slate-500">{result.percentage}%</span></div>
                                <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div className={`h-full rounded-full ${result.percentage < 50 ? 'bg-amber-500' : 'bg-emerald-500'}`} style={{ width: `${result.percentage}%` }} /></div>
                            </div>
                        ))}
                    </div>
                </section>

                <section>
                    <h2 className="text-lg font-semibold text-slate-900">Latihan yang disarankan</h2>
                    <p className="mt-1 text-sm text-slate-500">Dipilih dari kompetensi dengan capaian terendah.</p>
                    <div className="mt-4 grid gap-4">
                        {attempt.recommendations.length === 0 ? <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Belum ada soal latihan tambahan pada bank soal.</div> : attempt.recommendations.map((recommendation, index) => (
                            <article key={recommendation.id} className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                                <p className="text-xs font-semibold uppercase tracking-wide text-emerald-600">Latihan {index + 1} · {recommendation.competency.name}</p>
                                <p className="mt-3 font-medium leading-7 text-slate-900">{recommendation.question.prompt}</p>
                                <p className="mt-2 text-sm text-slate-500">{recommendation.reason}</p>
                            </article>
                        ))}
                    </div>
                </section>

                <Link href={route('assessments.index')} className="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Kembali ke daftar try out</Link>
            </div>
        </AuthenticatedLayout>
    );
}
