import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

type Assessment = {
    id: number;
    title: string;
    description?: string;
    grade_level: number;
    duration_minutes: number;
    status: string;
    questions_count: number;
    attempts_count: number;
    starts_at?: string;
    ends_at?: string;
    schedules_count?: number;
    schedules?: { id: number; school_npsn: string; starts_at: string; ends_at: string; session_number: number }[];
    settings?: { type_label?: string; selection_mode?: string };
    attempts?: { public_id: string; status: string }[];
};

export default function Index({ assessments, canManage, bookingRequired }: { assessments: Assessment[]; canManage: boolean; bookingRequired: boolean }) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <p className="text-sm font-medium text-emerald-600">Pelaksanaan</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">{canManage ? 'Paket Ujian' : 'Try Out Tersedia'}</h1>
                    </div>
                    {canManage && <Link href={route('assessments.create')} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Buat paket</Link>}
                </div>
            }
        >
            <Head title={canManage ? 'Paket Ujian' : 'Try Out'} />
            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                {!canManage && (
                    <div className={`mb-5 rounded-xl border p-4 text-sm ${bookingRequired ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-900'}`}>
                        {bookingRequired
                            ? 'Saat ini jam operasional sekolah. Try out hanya dapat dikerjakan jika guru sudah mengambil jadwal untuk NPSN sekolahmu.'
                            : 'Saat ini di luar jam operasional. Semua try out terbit dapat digunakan untuk belajar mandiri di rumah tanpa booking jadwal.'}
                    </div>
                )}
                {assessments.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-slate-500">{!canManage && bookingRequired ? 'Belum ada try out terjadwal untuk sekolahmu. Hubungi guru.' : 'Belum ada paket yang tersedia.'}</div>
                ) : (
                    <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                        {assessments.map((assessment) => {
                            const attempt = assessment.attempts?.[0];
                            const schoolSchedule = assessment.schedules?.[0];
                            const effectiveStart = schoolSchedule?.starts_at || assessment.starts_at;
                            const effectiveEnd = schoolSchedule?.ends_at || assessment.ends_at;
                            const startsInFuture = effectiveStart && new Date(effectiveStart).getTime() > Date.now();
                            const hasEnded = effectiveEnd && new Date(effectiveEnd).getTime() < Date.now();
                            return (
                                <article key={assessment.id} className="flex flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                                    <div className="flex items-center justify-between">
                                        <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${assessment.status === 'published' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>{assessment.status}</span>
                                        <span className="text-xs text-slate-500">Kelas {assessment.grade_level}</span>
                                    </div>
                                    <h2 className="mt-4 text-lg font-semibold text-slate-900">{assessment.title}</h2>
                                    <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-indigo-600">{assessment.settings?.type_label || 'Try Out Reguler'}</p>
                                    <p className="mt-2 flex-1 text-sm leading-6 text-slate-500">{assessment.description || 'Paket try out TKA.'}</p>
                                    <div className="mt-5 flex gap-4 border-t border-slate-100 pt-4 text-xs text-slate-500">
                                        <span>{assessment.questions_count} soal</span>
                                        <span>{assessment.duration_minutes} menit</span>
                                        {canManage && (assessment.schedules_count || 0) > 0 && <span>{assessment.schedules_count} sekolah terjadwal</span>}
                                    </div>
                                    {(effectiveStart || effectiveEnd) && <p className="mt-3 text-xs text-slate-500">{schoolSchedule && `Sesi ${schoolSchedule.session_number} · `}{effectiveStart ? `Mulai ${new Date(effectiveStart).toLocaleString('id-ID')}` : 'Tersedia sekarang'}{effectiveEnd ? ` · Tutup ${new Date(effectiveEnd).toLocaleString('id-ID')}` : ''}</p>}
                                    {canManage ? (
                                        <div className="mt-4 flex gap-2">
                                            {assessment.attempts_count === 0 && <Link href={route('assessments.edit', assessment.id)} className="flex-1 rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700">Edit</Link>}
                                            {assessment.status !== 'published' && <button onClick={() => router.post(route('assessments.publish', assessment.id))} className="flex-1 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Terbitkan</button>}
                                        </div>
                                    ) : startsInFuture ? (
                                        <button disabled className="mt-4 rounded-lg bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-800">Belum dimulai</button>
                                    ) : hasEnded ? (
                                        <button disabled className="mt-4 rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-500">Sudah ditutup</button>
                                    ) : attempt?.status === 'submitted' ? (
                                        <Link href={route('attempts.result', attempt.public_id)} className="mt-4 rounded-lg border border-emerald-600 px-4 py-2 text-center text-sm font-semibold text-emerald-700">Lihat hasil</Link>
                                    ) : attempt ? (
                                        <Link href={route('attempts.show', attempt.public_id)} className="mt-4 rounded-lg bg-emerald-600 px-4 py-2 text-center text-sm font-semibold text-white">Lanjutkan</Link>
                                    ) : (
                                        <button onClick={() => router.post(route('attempts.start', assessment.id))} className="mt-4 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Mulai try out</button>
                                    )}
                                </article>
                            );
                        })}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
