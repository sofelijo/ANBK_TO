import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

type DashboardProps = {
    mode: 'teacher' | 'student';
    stats: Record<string, number>;
};

export default function Dashboard({ mode, stats }: DashboardProps) {
    const cards =
        mode === 'student'
            ? [
                  ['Try out tersedia', stats.availableAssessments],
                  ['Try out selesai', stats.completedAttempts],
              ]
            : [
                  ['Total soal', stats.questions],
                  ['Soal terbit', stats.publishedQuestions],
                  ['Paket ujian', stats.assessments],
                  ['Pengerjaan selesai', stats.completedAttempts],
              ];

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-sm font-medium text-emerald-600">
                        Ringkasan platform
                    </p>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900">
                        Dashboard ANBK Cerdas
                    </h1>
                </div>
            }
        >
            <Head title="Dashboard" />
            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {cards.map(([label, value]) => (
                        <div
                            key={label}
                            className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
                        >
                            <p className="text-sm text-slate-500">{label}</p>
                            <p className="mt-2 text-3xl font-bold text-slate-900">
                                {value}
                            </p>
                        </div>
                    ))}
                </div>

                <div className="mt-8 rounded-2xl bg-slate-900 p-8 text-white">
                    <h2 className="text-xl font-semibold">
                        {mode === 'student'
                            ? 'Siap mengukur kemampuanmu?'
                            : 'Bangun bank soal berkualitas secara bertahap'}
                    </h2>
                    <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                        {mode === 'student'
                            ? 'Kerjakan try out, lihat peta kompetensi, lalu lanjutkan dengan soal latihan yang paling relevan.'
                            : 'AI membantu membuat draft. Guru tetap menjadi peninjau dan penerbit akhir setiap soal.'}
                    </p>
                    <Link
                        href={
                            mode === 'student'
                                ? route('assessments.index')
                                : route('questions.create')
                        }
                        className="mt-5 inline-flex rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-400"
                    >
                        {mode === 'student' ? 'Lihat try out' : 'Buat soal pertama'}
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
