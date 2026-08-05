import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

type Assessment = { id: number; title: string; grade_level: number };
type Summary = { completed: number; average: number; highest: number; lowest: number };
type Student = { id: number; name: string; student_identifier?: string; grade_level: number; percentage: number; duration_seconds: number; events_count: number };
type Competency = { competency: { code: string; domain: string; name: string }; average_percentage: number; student_count: number };
type ItemFlag = { code: string; label: string; message: string };
type Distractor = { label: string; content: string; selection_count: number; selection_percentage: number; functioning: boolean };
type Item = {
    question: { id: number; version: number; title?: string; prompt: string; type: string; difficulty: number };
    response_count: number;
    answered_count: number;
    omitted_count: number;
    correct_count: number;
    correct_percentage: number;
    difficulty_classification: string;
    discrimination_percentage?: number;
    discrimination_classification: string;
    average_duration: number;
    distractors: { applicable: boolean; total: number; functioning: number; ineffective: Distractor[]; options?: Distractor[] };
    flags: ItemFlag[];
    status: 'healthy' | 'review' | 'insufficient_data';
};
type Reliability = {
    coefficient?: number;
    classification: string;
    status: 'good' | 'review' | 'insufficient_data' | 'no_variance';
    item_count: number;
    sample_size: number;
};
type ItemAnalysis = { minimum_responses: number; sample_size: number; flagged_count: number; reliability: Reliability };

type Props = {
    assessments: Assessment[];
    selectedAssessmentId?: number;
    summary?: Summary;
    students: Student[];
    competencies: Competency[];
    items: Item[];
    itemAnalysis?: ItemAnalysis;
};

const statusStyle = {
    healthy: 'bg-emerald-50 text-emerald-700',
    review: 'bg-amber-50 text-amber-700',
    insufficient_data: 'bg-slate-100 text-slate-600',
};

export default function Index({ assessments, selectedAssessmentId, summary, students, competencies, items, itemAnalysis }: Props) {
    const selectAssessment = (id: number) => router.get(route('reports.index'), { assessment_id: id }, { preserveState: true });
    const hasEnoughResponses = Boolean(itemAnalysis && itemAnalysis.sample_size >= itemAnalysis.minimum_responses);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div><p className="text-sm font-medium text-emerald-600">Analitik</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Laporan Sekolah</h1></div>
                    <div className="flex gap-2">
                        {selectedAssessmentId && <>
                            <a href={route('reports.export', { assessment_id: selectedAssessmentId })} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Ekspor CSV</a>
                            <button onClick={() => window.print()} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Cetak / PDF</button>
                        </>}
                    </div>
                </div>
            }
        >
            <Head title="Laporan" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <select value={selectedAssessmentId || ''} onChange={(event) => selectAssessment(Number(event.target.value))} className="w-full rounded-xl border-slate-300 bg-white sm:max-w-xl">
                    {assessments.length === 0 && <option value="">Belum ada paket</option>}
                    {assessments.map((assessment) => <option key={assessment.id} value={assessment.id}>{assessment.title} · Kelas {assessment.grade_level}</option>)}
                </select>

                {!summary ? (
                    <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-slate-500">Belum ada data laporan.</div>
                ) : <>
                    <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {[
                            ['Selesai', summary.completed],
                            ['Rata-rata', `${summary.average}%`],
                            ['Tertinggi', `${summary.highest}%`],
                            ['Terendah', `${summary.lowest}%`],
                        ].map(([label, value]) => <div key={label} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">{label}</p><p className="mt-2 text-3xl font-bold text-slate-900">{value}</p></div>)}
                    </section>

                    {itemAnalysis && (
                        <section className="grid gap-4 lg:grid-cols-3">
                            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p className="text-sm font-medium text-slate-500">Reliabilitas paket (KR-20)</p>
                                        <div className="mt-2 flex items-end gap-3">
                                            <p className="text-4xl font-bold text-slate-900">{itemAnalysis.reliability.coefficient ?? '—'}</p>
                                            <span className={`mb-1 rounded-full px-3 py-1 text-xs font-semibold ${itemAnalysis.reliability.status === 'good' ? 'bg-emerald-50 text-emerald-700' : itemAnalysis.reliability.status === 'review' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'}`}>{itemAnalysis.reliability.classification}</span>
                                        </div>
                                    </div>
                                    <div className="text-right text-sm text-slate-500"><p>{itemAnalysis.reliability.item_count} soal</p><p>{itemAnalysis.sample_size} peserta</p></div>
                                </div>
                                <p className="mt-4 text-sm leading-6 text-slate-600">Koefisien minimal 0,70 umumnya dianggap memadai. Nilai ini mengukur konsistensi paket secara keseluruhan, bukan kualitas satu soal saja.</p>
                            </div>
                            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                                <p className="text-sm font-medium text-slate-500">Butir perlu ditinjau</p>
                                <p className="mt-2 text-4xl font-bold text-slate-900">{hasEnoughResponses ? itemAnalysis.flagged_count : '—'}</p>
                                <p className="mt-3 text-sm leading-6 text-slate-600">Analisis otomatis aktif mulai {itemAnalysis.minimum_responses} respons per paket.</p>
                            </div>
                        </section>
                    )}

                    {itemAnalysis && !hasEnoughResponses && (
                        <div className="rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm leading-6 text-indigo-900">
                            Data baru {itemAnalysis.sample_size} dari minimal {itemAnalysis.minimum_responses} respons. Tingkat kesukaran sementara tetap ditampilkan, tetapi daya pembeda, reliabilitas, dan penandaan masalah belum diaktifkan agar kesimpulannya tidak menyesatkan.
                        </div>
                    )}

                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 className="font-semibold text-slate-900">Capaian kompetensi</h2>
                        <div className="mt-5 grid gap-x-8 gap-y-5 lg:grid-cols-2">
                            {competencies.length === 0 ? <p className="text-sm text-slate-500">Belum ada hasil kompetensi.</p> : competencies.map((item) => (
                                <div key={item.competency.code}>
                                    <div className="flex justify-between gap-3 text-sm"><span className="font-medium text-slate-800">{item.competency.name}</span><span className="text-slate-500">{item.average_percentage}%</span></div>
                                    <div className="mt-2 h-2 rounded-full bg-slate-100"><div className={`h-2 rounded-full ${item.average_percentage < 50 ? 'bg-amber-500' : 'bg-emerald-500'}`} style={{ width: `${item.average_percentage}%` }} /></div>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div className="border-b border-slate-100 p-5">
                            <h2 className="font-semibold text-slate-900">Analisis kualitas butir</h2>
                            <p className="mt-1 text-sm text-slate-500">Tingkat kesukaran dihitung dari persentase benar; daya pembeda membandingkan 27% peserta teratas dan terbawah.</p>
                        </div>
                        {items.length === 0 ? <p className="p-6 text-sm text-slate-500">Paket belum memiliki soal atau respons.</p> : (
                            <div className="overflow-x-auto">
                                <table className="min-w-[1000px] divide-y divide-slate-200 text-sm">
                                    <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Soal</th><th className="px-5 py-3">Kesukaran</th><th className="px-5 py-3">Daya pembeda</th><th className="px-5 py-3">Pengecoh</th><th className="px-5 py-3">Status</th></tr></thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {items.map((item) => (
                                            <tr key={item.question.id} className="align-top">
                                                <td className="max-w-md px-5 py-4">
                                                    <Link href={route('questions.show', item.question.id)} className="font-medium text-slate-900 hover:text-emerald-700">{item.question.title || item.question.prompt}</Link>
                                                    <p className="mt-1 text-xs text-slate-500">Versi {item.question.version} · {item.response_count} respons · {item.average_duration} detik · {item.omitted_count} kosong</p>
                                                </td>
                                                <td className="px-5 py-4"><p className="font-semibold text-slate-900">{item.correct_percentage}% benar</p><p className="mt-1 text-xs text-slate-500">{item.difficulty_classification}</p></td>
                                                <td className="px-5 py-4"><p className="font-semibold text-slate-900">{item.discrimination_percentage === undefined || item.discrimination_percentage === null ? '—' : `${item.discrimination_percentage}%`}</p><p className="mt-1 text-xs text-slate-500">{item.discrimination_classification}</p></td>
                                                <td className="px-5 py-4">
                                                    {item.distractors.applicable ? <><p className="font-semibold text-slate-900">{item.distractors.functioning}/{item.distractors.total} efektif</p>{item.distractors.ineffective.length > 0 && <p className="mt-1 text-xs text-amber-700">Lemah: {item.distractors.ineffective.map((option) => option.label).join(', ')}</p>}</> : <span className="text-slate-400">Tidak berlaku</span>}
                                                </td>
                                                <td className="w-72 px-5 py-4">
                                                    <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${statusStyle[item.status]}`}>{item.status === 'healthy' ? 'Sehat' : item.status === 'review' ? 'Perlu ditinjau' : 'Data belum cukup'}</span>
                                                    {item.flags.map((flag) => <div key={flag.code} className="mt-2"><p className="text-xs font-semibold text-amber-700">{flag.label}</p><p className="mt-0.5 text-xs leading-5 text-slate-500">{flag.message}</p></div>)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div className="border-b border-slate-100 p-5"><h2 className="font-semibold text-slate-900">Hasil murid</h2></div>
                        <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-200 text-sm"><thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Murid</th><th className="px-5 py-3">Nilai</th><th className="px-5 py-3">Durasi</th><th className="px-5 py-3">Kejadian</th></tr></thead><tbody className="divide-y divide-slate-100">{students.map((student) => <tr key={student.id}><td className="px-5 py-4"><p className="font-medium text-slate-900">{student.name}</p><p className="text-xs text-slate-500">{student.student_identifier || '-'}</p></td><td className="px-5 py-4 font-semibold text-slate-900">{student.percentage}%</td><td className="px-5 py-4 text-slate-600">{Math.floor(student.duration_seconds / 60)} menit</td><td className="px-5 py-4 text-slate-600">{student.events_count}</td></tr>)}</tbody></table></div>
                    </section>
                </>}
            </div>
        </AuthenticatedLayout>
    );
}
