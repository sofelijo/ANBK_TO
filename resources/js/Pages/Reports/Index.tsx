import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

type Assessment = { id: number; title: string; grade_level: number };
type Summary = { completed: number; average: number; highest: number; lowest: number };
type Student = { id: number; name: string; student_identifier?: string; grade_level: number; percentage: number; duration_seconds: number; events_count: number };
type Competency = { competency: { code: string; domain: string; name: string }; average_percentage: number; student_count: number };
type Item = { question: { id: number; title?: string; prompt: string; difficulty: number }; answer_count: number; correct_percentage: number; average_duration: number };

type Props = {
    assessments: Assessment[];
    selectedAssessmentId?: number;
    summary?: Summary;
    students: Student[];
    competencies: Competency[];
    items: Item[];
};

export default function Index({ assessments, selectedAssessmentId, summary, students, competencies, items }: Props) {
    const selectAssessment = (id: number) => router.get(route('reports.index'), { assessment_id: id }, { preserveState: true });

    return (
        <AuthenticatedLayout header={<div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"><div><p className="text-sm font-medium text-emerald-600">Analitik</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Laporan Sekolah</h1></div><div className="flex gap-2">{selectedAssessmentId && <><a href={route('reports.export', { assessment_id: selectedAssessmentId })} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Ekspor CSV</a><button onClick={() => window.print()} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Cetak / PDF</button></>}</div></div>}>
            <Head title="Laporan" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <select value={selectedAssessmentId || ''} onChange={(event) => selectAssessment(Number(event.target.value))} className="w-full rounded-xl border-slate-300 bg-white sm:max-w-xl">
                    {assessments.length === 0 && <option value="">Belum ada paket</option>}
                    {assessments.map((assessment) => <option key={assessment.id} value={assessment.id}>{assessment.title} · Kelas {assessment.grade_level}</option>)}
                </select>

                {!summary ? <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-slate-500">Belum ada data laporan.</div> : <>
                    <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">{[['Selesai', summary.completed], ['Rata-rata', `${summary.average}%`], ['Tertinggi', `${summary.highest}%`], ['Terendah', `${summary.lowest}%`]].map(([label, value]) => <div key={label} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">{label}</p><p className="mt-2 text-3xl font-bold text-slate-900">{value}</p></div>)}</section>

                    <section className="grid gap-6 lg:grid-cols-2">
                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 className="font-semibold text-slate-900">Capaian kompetensi</h2><div className="mt-5 space-y-5">{competencies.length === 0 ? <p className="text-sm text-slate-500">Belum ada hasil kompetensi.</p> : competencies.map((item) => <div key={item.competency.code}><div className="flex justify-between gap-3 text-sm"><span className="font-medium text-slate-800">{item.competency.name}</span><span className="text-slate-500">{item.average_percentage}%</span></div><div className="mt-2 h-2 rounded-full bg-slate-100"><div className={`h-2 rounded-full ${item.average_percentage < 50 ? 'bg-amber-500' : 'bg-emerald-500'}`} style={{ width: `${item.average_percentage}%` }} /></div></div>)}</div></div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 className="font-semibold text-slate-900">Kualitas butir</h2><div className="mt-4 divide-y divide-slate-100">{items.length === 0 ? <p className="py-4 text-sm text-slate-500">Belum ada jawaban.</p> : items.map((item) => <div key={item.question.id} className="py-3"><div className="flex justify-between gap-4"><p className="line-clamp-1 text-sm font-medium text-slate-800">{item.question.title || item.question.prompt}</p><span className={`shrink-0 text-sm font-semibold ${item.correct_percentage < 30 || item.correct_percentage > 90 ? 'text-amber-600' : 'text-emerald-600'}`}>{item.correct_percentage}% benar</span></div><p className="mt-1 text-xs text-slate-500">{item.answer_count} jawaban · rata-rata {item.average_duration} detik</p></div>)}</div></div>
                    </section>

                    <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div className="border-b border-slate-100 p-5"><h2 className="font-semibold text-slate-900">Hasil murid</h2></div><div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-200 text-sm"><thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Murid</th><th className="px-5 py-3">Nilai</th><th className="px-5 py-3">Durasi</th><th className="px-5 py-3">Kejadian</th></tr></thead><tbody className="divide-y divide-slate-100">{students.map((student) => <tr key={student.id}><td className="px-5 py-4"><p className="font-medium text-slate-900">{student.name}</p><p className="text-xs text-slate-500">{student.student_identifier || '-'}</p></td><td className="px-5 py-4 font-semibold text-slate-900">{student.percentage}%</td><td className="px-5 py-4 text-slate-600">{Math.floor(student.duration_seconds / 60)} menit</td><td className="px-5 py-4 text-slate-600">{student.events_count}</td></tr>)}</tbody></table></div></section>
                </>}
            </div>
        </AuthenticatedLayout>
    );
}
