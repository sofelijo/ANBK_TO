import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Assessment = { id: number; title: string; grade_level: number };
type Slot = { number: number; label: string };
type Schedule = {
    id: number;
    school_npsn: string;
    scheduled_date: string;
    session_number: number;
    starts_at: string;
    ends_at: string;
    assessment: Assessment;
    creator: { name: string };
};

function nextWeekday(): string {
    const date = new Date();
    date.setDate(date.getDate() + 1);
    while ([0, 6].includes(date.getDay())) date.setDate(date.getDate() + 1);

    return date.toLocaleDateString('en-CA');
}

export default function Index({ assessments, schedules, slots, capacity, schoolNpsn }: { assessments: Assessment[]; schedules: Schedule[]; slots: Slot[]; capacity: { daily: number; weekly: number }; schoolNpsn: string }) {
    const { data, setData, post, processing, errors } = useForm({
        assessment_id: assessments[0]?.id || 0,
        scheduled_date: nextWeekday(),
        session_number: slots[0]?.number || 1,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('schedules.store'), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Pelaksanaan</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Jadwal Try Out Sekolah</h1></div>}>
            <Head title="Jadwal Try Out" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <section className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Jam operasional</p><p className="mt-2 text-xl font-bold text-slate-900">06:30–16:30</p><p className="mt-1 text-xs text-slate-500">Senin–Jumat · wajib booking</p></div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Kapasitas harian</p><p className="mt-2 text-xl font-bold text-emerald-700">{capacity.daily} sekolah</p><p className="mt-1 text-xs text-slate-500">Satu NPSN per sesi</p></div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Kapasitas mingguan</p><p className="mt-2 text-xl font-bold text-indigo-700">{capacity.weekly} sekolah</p><p className="mt-1 text-xs text-slate-500">Durasi sesi 2 jam 30 menit</p></div>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Tambahkan jadwal sekolah</h2>
                    <p className="mt-1 text-sm text-slate-500">Booking memakai NPSN sekolah akun guru dan hanya berlaku untuk Try Out Bersama. Try Out Reguler dapat dikerjakan tanpa booking jadwal.</p>
                    <div className="mt-4 rounded-xl bg-indigo-50 px-4 py-3 text-sm text-indigo-800">NPSN sekolah Anda: <strong className="font-mono">{schoolNpsn}</strong></div>
                    <InputError message={(errors as Record<string, string>).school_npsn} className="mt-2" />
                    {assessments.length === 0 ? (
                        <p className="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Belum ada paket ujian berstatus terbit.</p>
                    ) : (
                        <form onSubmit={submit} className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-[1.5fr_1fr_1fr_auto] xl:items-end">
                            <label className="text-sm font-medium text-slate-700">Paket ujian<select value={data.assessment_id} onChange={(event) => setData('assessment_id', Number(event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300">{assessments.map((assessment) => <option key={assessment.id} value={assessment.id}>{assessment.title} · Kelas {assessment.grade_level}</option>)}</select><InputError message={errors.assessment_id} /></label>
                            <label className="text-sm font-medium text-slate-700">Tanggal<input type="date" value={data.scheduled_date} onChange={(event) => setData('scheduled_date', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /><InputError message={errors.scheduled_date} /></label>
                            <label className="text-sm font-medium text-slate-700">Sesi<select value={data.session_number} onChange={(event) => setData('session_number', Number(event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300">{slots.map((slot) => <option key={slot.number} value={slot.number}>Sesi {slot.number} · {slot.label}</option>)}</select><InputError message={errors.session_number} /></label>
                            <button disabled={processing} className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{processing ? 'Menyimpan...' : 'Buat jadwal'}</button>
                        </form>
                    )}
                </section>

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 px-6 py-4"><h2 className="font-semibold text-slate-900">Daftar jadwal</h2></div>
                    {schedules.length === 0 ? <p className="p-8 text-center text-sm text-slate-500">Belum ada jadwal sekolah.</p> : (
                        <div className="overflow-x-auto"><table className="w-full min-w-[850px] text-sm"><thead className="bg-slate-50 text-left text-slate-600"><tr><th className="px-5 py-3">Tanggal</th><th className="px-5 py-3">Sesi</th><th className="px-5 py-3">NPSN</th><th className="px-5 py-3">Paket</th><th className="px-5 py-3">Dibuat oleh</th><th className="px-5 py-3" /></tr></thead><tbody className="divide-y divide-slate-100">{schedules.map((schedule) => <tr key={schedule.id}><td className="px-5 py-4 font-medium text-slate-900">{new Date(schedule.starts_at).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</td><td className="px-5 py-4 text-slate-700">Sesi {schedule.session_number}<br /><span className="text-xs text-slate-500">{new Date(schedule.starts_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}–{new Date(schedule.ends_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</span></td><td className="px-5 py-4 font-mono font-semibold text-indigo-700">{schedule.school_npsn}</td><td className="px-5 py-4 text-slate-700">{schedule.assessment.title}<br /><span className="text-xs text-slate-500">Kelas {schedule.assessment.grade_level}</span></td><td className="px-5 py-4 text-slate-600">{schedule.creator.name}</td><td className="px-5 py-4 text-right"><button onClick={() => window.confirm('Hapus jadwal ini?') && router.delete(route('schedules.destroy', schedule.id), { preserveScroll: true })} className="font-semibold text-rose-700">Hapus</button></td></tr>)}</tbody></table></div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
