import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Assessment = { id: number; title: string; grade_level: number };
type Slot = { number: number; label: string };
type School = { name: string; npsn: string };
type ScheduleSummary = { assessment_id: number; assessment_title: string; school_npsn: string; session_count: number; student_count: number };
type Schedule = {
    id: number;
    school_npsn: string;
    scheduled_date: string;
    session_number: number;
    starts_at: string;
    ends_at: string;
    assessment: Assessment;
    creator: { name: string };
    student_count: number;
    session_student_count: number;
    session_student_remaining: number;
    participants: Array<{
        id: number;
        name: string;
        student_identifier: string | null;
        pivot: { status: string; started_at: string };
    }>;
};

function nextWeekday(): string {
    const date = new Date();
    date.setDate(date.getDate() + 1);
    while ([0, 6].includes(date.getDay())) date.setDate(date.getDate() + 1);

    return date.toLocaleDateString('en-CA');
}

function dateKey(date: Date): string {
    return date.toLocaleDateString('en-CA');
}

function addDays(dateValue: string, days: number): string {
    const date = new Date(`${dateValue}T00:00:00`);
    date.setDate(date.getDate() + days);

    return dateKey(date);
}

function addMonths(dateValue: string, months: number): string {
    const date = new Date(`${dateValue.slice(0, 7)}-01T00:00:00`);
    date.setMonth(date.getMonth() + months);

    return `${dateKey(date).slice(0, 7)}-01`;
}

export default function Index({ assessments, schedules, scheduleSummaries, sessionUsage, slots, capacity, schoolNpsn, canChooseNpsn, schools, defaultStudentCount }: { assessments: Assessment[]; schedules: Schedule[]; scheduleSummaries: ScheduleSummary[]; sessionUsage: Record<string, Record<string, number>>; slots: Slot[]; capacity: { studentsPerSession: number; sessionDurationMinutes: number; daily: number; weekly: number; greenThreshold: number; yellowThreshold: number }; schoolNpsn: string; canChooseNpsn: boolean; schools: School[]; defaultStudentCount: number }) {
    const { data, setData, post, processing, errors, clearErrors } = useForm({
        assessment_id: assessments[0]?.id || 0,
        school_npsn: schoolNpsn,
        scheduled_date: nextWeekday(),
        session_number: slots[0]?.number || 1,
        student_count: defaultStudentCount,
    });
    const capacityForm = useForm({ student_capacity: capacity.studentsPerSession });
    const durationForm = useForm({ session_duration_minutes: capacity.sessionDurationMinutes });
    const thresholdForm = useForm({ green_threshold: capacity.greenThreshold, yellow_threshold: capacity.yellowThreshold });
    const [expandedScheduleId, setExpandedScheduleId] = useState<number | null>(null);
    const [expandedSessionNumber, setExpandedSessionNumber] = useState<number | null>(null);
    const [addScheduleModalOpen, setAddScheduleModalOpen] = useState(false);
    const [scheduleListDate, setScheduleListDate] = useState(nextWeekday());
    const [calendarMonth, setCalendarMonth] = useState(`${nextWeekday().slice(0, 7)}-01`);
    const selectedScheduleListUsage = sessionUsage[scheduleListDate] ?? {};
    const selectedDateSchedules = schedules.filter((schedule) => schedule.scheduled_date.slice(0, 10) === scheduleListDate);
    const scheduleDateOptions = Array.from({ length: 5 }, (_, index) => addDays(scheduleListDate, index - 2));
    const calendarMonthDate = new Date(`${calendarMonth}T00:00:00`);
    const calendarGridOffset = (calendarMonthDate.getDay() + 6) % 7;
    const calendarDays = Array.from({ length: 42 }, (_, index) => addDays(calendarMonth, index - calendarGridOffset));

    const dateCapacity = (dateValue: string) => {
        const usage = sessionUsage[dateValue] ?? {};
        const used = slots.reduce((total, slot) => total + (usage[String(slot.number)] ?? 0), 0);
        const total = capacity.studentsPerSession * slots.length;
        const percentage = total > 0 ? Math.min(100, (used / total) * 100) : 0;

        return {
            remainingSessions: slots.filter((slot) => (usage[String(slot.number)] ?? 0) < capacity.studentsPerSession).length,
            remainingQuota: Math.max(0, total - used),
            percentage,
            full: total > 0 && used >= total,
        };
    };

    const availabilityClasses = (percentage: number, full: boolean) => {
        if (full) return 'border-slate-300 bg-slate-100 text-slate-400';
        if (percentage > capacity.yellowThreshold) return 'border-rose-300 bg-rose-50 text-rose-700';
        if (percentage > capacity.greenThreshold) return 'border-amber-300 bg-amber-50 text-amber-700';

        return 'border-emerald-300 bg-emerald-50 text-emerald-700';
    };

    const moveToAvailableDate = (direction: -1 | 1) => {
        let candidate = scheduleListDate;

        for (let attempt = 0; attempt < 366; attempt += 1) {
            candidate = addDays(candidate, direction);
            const date = new Date(`${candidate}T00:00:00`);
            if (![0, 6].includes(date.getDay()) && !dateCapacity(candidate).full) {
                setScheduleListDate(candidate);
                setExpandedScheduleId(null);
                setExpandedSessionNumber(null);
                return;
            }
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('schedules.store'), {
            preserveScroll: true,
            onSuccess: () => setAddScheduleModalOpen(false),
        });
    };

    const openAddScheduleModal = (sessionNumber: number) => {
        clearErrors();
        setData((current) => ({
            ...current,
            scheduled_date: scheduleListDate,
            session_number: sessionNumber,
        }));
        setAddScheduleModalOpen(true);
    };

    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Pelaksanaan</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Jadwal Try Out Sekolah</h1></div>}>
            <Head title="Jadwal Try Out" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <section className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Jam operasional</p><p className="mt-2 text-xl font-bold text-slate-900">06:30–16:30</p><p className="mt-1 text-xs text-slate-500">{slots.length} sesi · {capacity.sessionDurationMinutes} menit/sesi</p></div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Kapasitas per sesi</p><p className="mt-2 text-xl font-bold text-emerald-700">{capacity.studentsPerSession.toLocaleString('id-ID')} siswa</p><p className="mt-1 text-xs text-slate-500">Akumulasi kuota yang dipesan sekolah</p></div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Kapasitas mingguan</p><p className="mt-2 text-xl font-bold text-indigo-700">{capacity.weekly.toLocaleString('id-ID')} siswa</p><p className="mt-1 text-xs text-slate-500">{slots.length} sesi/hari · Senin–Jumat</p></div>
                </section>

                {canChooseNpsn && <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 className="font-semibold text-slate-900">Konfigurasi sesi</h2><p className="mt-1 text-sm text-slate-500">Atur kapasitas, durasi, dan indikator warna ketersediaan tanggal.</p><div className="mt-5 grid gap-6 lg:grid-cols-2 xl:grid-cols-3"><form onSubmit={(event) => { event.preventDefault(); capacityForm.patch(route('schedules.capacity.update'), { preserveScroll: true }); }} className="rounded-xl border border-slate-200 p-4"><label className="block text-sm font-medium text-slate-700">Maksimal siswa per sesi<input type="number" min={1} max={100000} value={capacityForm.data.student_capacity} onChange={(event) => capacityForm.setData('student_capacity', Number(event.target.value))} className="mt-2 block w-full rounded-lg border-slate-300" /><InputError message={capacityForm.errors.student_capacity} className="mt-1" /></label><button disabled={capacityForm.processing} className="mt-3 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{capacityForm.processing ? 'Menyimpan...' : 'Simpan kapasitas'}</button></form><form onSubmit={(event) => { event.preventDefault(); durationForm.patch(route('schedules.duration.update'), { preserveScroll: true }); }} className="rounded-xl border border-slate-200 p-4"><label className="block text-sm font-medium text-slate-700">Durasi satu sesi (menit)<input type="number" min={30} max={600} value={durationForm.data.session_duration_minutes} onChange={(event) => durationForm.setData('session_duration_minutes', Number(event.target.value))} className="mt-2 block w-full rounded-lg border-slate-300" /><InputError message={durationForm.errors.session_duration_minutes} className="mt-1" /></label><p className="mt-1 text-xs text-slate-500">30–600 menit. Saat ini menghasilkan {slots.length} sesi per hari.</p><button disabled={durationForm.processing} className="mt-3 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{durationForm.processing ? 'Menyimpan...' : 'Simpan durasi'}</button></form><form onSubmit={(event) => { event.preventDefault(); thresholdForm.patch(route('schedules.thresholds.update'), { preserveScroll: true }); }} className="rounded-xl border border-slate-200 p-4"><p className="text-sm font-medium text-slate-700">Ambang warna tanggal</p><div className="mt-2 grid grid-cols-2 gap-3"><label className="text-xs text-slate-600">Hijau sampai (%)<input type="number" min={1} max={98} value={thresholdForm.data.green_threshold} onChange={(event) => thresholdForm.setData('green_threshold', Number(event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300" /></label><label className="text-xs text-slate-600">Kuning sampai (%)<input type="number" min={2} max={99} value={thresholdForm.data.yellow_threshold} onChange={(event) => thresholdForm.setData('yellow_threshold', Number(event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300" /></label></div><InputError message={thresholdForm.errors.green_threshold || thresholdForm.errors.yellow_threshold} className="mt-1" /><p className="mt-1 text-xs text-slate-500">Di atas batas kuning menjadi merah; 100% tetap abu-abu.</p><button disabled={thresholdForm.processing} className="mt-3 rounded-lg bg-orange-500 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{thresholdForm.processing ? 'Menyimpan...' : 'Simpan warna'}</button></form></div></section>}

                {scheduleSummaries.length > 0 && <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div><h2 className="font-semibold text-slate-900">Ringkasan paket yang diambil</h2><p className="mt-1 text-sm text-slate-500">Akumulasi sesi dan jumlah siswa yang sudah dipesan.</p></div><div className="mt-4 grid gap-3 lg:grid-cols-2 xl:grid-cols-3">{scheduleSummaries.map((summary) => <div key={`${summary.assessment_id}-${summary.school_npsn}`} className="rounded-xl border border-slate-200 bg-slate-50 p-4"><p className="font-semibold text-slate-900">{summary.assessment_title}</p>{canChooseNpsn && <p className="mt-1 font-mono text-xs text-indigo-700">NPSN {summary.school_npsn}</p>}<p className="mt-3 text-sm text-slate-600"><strong className="text-slate-900">{summary.session_count} sesi</strong> · total <strong className="text-emerald-700">{summary.student_count.toLocaleString('id-ID')} siswa</strong></p></div>)}</div></section>}

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><h2 className="font-semibold text-slate-900">Daftar jadwal</h2><p className="mt-1 text-sm text-slate-500">Pilih tanggal, lalu lihat sesi yang tersedia seperti daftar perjalanan.</p></div>
                        <Popover className="relative">
                            <PopoverButton onClick={() => setCalendarMonth(`${scheduleListDate.slice(0, 7)}-01`)} className="inline-flex min-w-44 items-center justify-between gap-4 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50">
                                <span>{new Date(`${scheduleListDate}T00:00:00`).toLocaleDateString('id-ID')}</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-5 w-5" aria-hidden="true"><path d="M6 2v4M18 2v4M3 9h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" /></svg>
                            </PopoverButton>
                            <PopoverPanel className="absolute left-0 z-30 mt-2 w-80 max-w-full sm:left-auto sm:right-0 sm:max-w-[calc(100vw-3rem)] rounded-xl border border-slate-200 bg-white p-4 shadow-xl">
                                {({ close }) => <>
                                    <div className="flex items-center justify-between gap-3">
                                        <button type="button" onClick={() => setCalendarMonth(addMonths(calendarMonth, -1))} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Bulan sebelumnya">‹</button>
                                        <p className="font-bold text-slate-900">{calendarMonthDate.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' })}</p>
                                        <button type="button" onClick={() => setCalendarMonth(addMonths(calendarMonth, 1))} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Bulan berikutnya">›</button>
                                    </div>
                                    <div className="mt-3 grid grid-cols-7 gap-1 text-center text-[11px] font-semibold text-slate-500">{['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'].map((day) => <span key={day} className="py-1">{day}</span>)}</div>
                                    <div className="grid grid-cols-7 gap-1">{calendarDays.map((dateValue) => {
                                        const date = new Date(`${dateValue}T00:00:00`);
                                        const dateStatus = dateCapacity(dateValue);
                                        const outsideMonth = dateValue.slice(0, 7) !== calendarMonth.slice(0, 7);
                                        const weekend = [0, 6].includes(date.getDay());
                                        const disabled = weekend || dateStatus.full;
                                        const selected = dateValue === scheduleListDate;

                                        return <button key={dateValue} type="button" disabled={disabled} title={dateStatus.full ? 'Semua sesi penuh' : weekend ? 'Jadwal tidak tersedia pada akhir pekan' : `${Math.round(dateStatus.percentage)}% terisi`} onClick={() => { setScheduleListDate(dateValue); setExpandedScheduleId(null); setExpandedSessionNumber(null); close(); }} className={`relative aspect-square rounded-lg border text-sm font-semibold transition ${disabled ? 'cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400' : availabilityClasses(dateStatus.percentage, false)} ${outsideMonth ? 'opacity-40' : ''} ${selected ? 'ring-2 ring-indigo-500 ring-offset-1' : ''}`}>{date.getDate()}</button>;
                                    })}</div>
                                </>}
                            </PopoverPanel>
                        </Popover>
                    </div>
                    <div className="mt-4 flex items-stretch gap-2">
                        <button type="button" aria-label="Tanggal tersedia sebelumnya" onClick={() => moveToAvailableDate(-1)} className="rounded-lg border border-slate-200 min-h-11 min-w-9 px-2 text-slate-500 hover:bg-slate-50">‹</button>
                        <div className="flex min-w-0 flex-1 gap-2 overflow-x-auto snap-x snap-mandatory sm:grid sm:grid-cols-5">{scheduleDateOptions.map((dateOption) => {
                            const date = new Date(`${dateOption}T00:00:00`);
                            const selected = dateOption === scheduleListDate;
                            const dateStatus = dateCapacity(dateOption);
                            const weekend = [0, 6].includes(date.getDay());
                            const disabled = weekend || dateStatus.full;

                            return <button key={dateOption} type="button" disabled={disabled} title={dateStatus.full ? 'Semua sesi penuh' : weekend ? 'Jadwal tidak tersedia pada akhir pekan' : `${Math.round(dateStatus.percentage)}% terisi`} onClick={() => { setScheduleListDate(dateOption); setExpandedScheduleId(null); setExpandedSessionNumber(null); }} className={`min-w-28 shrink-0 snap-center rounded-lg border px-2 py-2 text-center sm:min-w-0 transition ${disabled ? 'cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400' : availabilityClasses(dateStatus.percentage, false)} ${selected ? 'ring-2 ring-indigo-500 ring-offset-1' : ''}`}>
                                <span className="flex items-baseline justify-center gap-1 truncate">
                                    <span className="text-[10px] font-semibold uppercase sm:text-xs">{date.toLocaleDateString('id-ID', { weekday: 'short' })}</span>
                                    <span className="text-base font-bold">{date.getDate()}</span>
                                    <span className="text-[10px] sm:text-xs">{date.toLocaleDateString('id-ID', { month: 'short' })}</span>
                                </span>
                                <span className="mt-1 block truncate border-t border-current/20 pt-1 text-[9px] font-semibold sm:text-[11px]">
                                    {dateStatus.full ? 'Semua sesi penuh' : weekend ? 'Tidak tersedia' : `${dateStatus.remainingSessions} sesi · ${dateStatus.remainingQuota.toLocaleString('id-ID')} kuota`}
                                </span>
                            </button>;
                        })}</div>
                        <button type="button" aria-label="Tanggal tersedia berikutnya" onClick={() => moveToAvailableDate(1)} className="rounded-lg border border-slate-200 min-h-11 min-w-9 px-2 text-slate-500 hover:bg-slate-50">›</button>
                    </div>
                    <div className="mt-6 space-y-3">
                        {slots.map((slot) => {
                            const bookings = selectedDateSchedules.filter((schedule) => schedule.session_number === slot.number);
                            const used = selectedScheduleListUsage[String(slot.number)] ?? 0;
                            const remaining = Math.max(0, capacity.studentsPerSession - used);
                            const percentage = Math.min(100, Math.round((used / capacity.studentsPerSession) * 100));
                            const [startTime, endTime] = slot.label.split('–');

                            return <article key={slot.number} className="overflow-hidden rounded-xl border border-slate-200 bg-white transition hover:border-orange-200 hover:shadow-sm">
                                <div className="grid items-center gap-4 p-4 sm:grid-cols-[100px_minmax(0,1fr)_auto] sm:px-5">
                                    <div><p className="text-xs font-bold uppercase tracking-wide text-orange-600">Sesi {slot.number}</p><p className="mt-1 text-xs text-slate-500">{capacity.sessionDurationMinutes} menit</p></div>
                                    <div className="flex items-center gap-3"><div><p className="text-xl font-bold text-slate-900">{startTime}</p><p className="text-xs text-slate-500">Mulai</p></div><div className="min-w-12 flex-1"><div className="flex items-center"><span className="h-2 w-2 rounded-full border-2 border-orange-500 bg-white" /><span className="h-px flex-1 bg-slate-300" /><span className="text-sm text-orange-500">›</span></div></div><div className="text-right"><p className="text-xl font-bold text-slate-900">{endTime}</p><p className="text-xs text-slate-500">Selesai</p></div></div>
                                    <div className="flex flex-wrap items-center justify-between gap-2 sm:justify-end sm:text-right"><span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${remaining > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>{remaining > 0 ? `Sisa ${remaining.toLocaleString('id-ID')} dari ${capacity.studentsPerSession.toLocaleString('id-ID')}` : 'Kuota penuh'}</span>{bookings.length > 0 && <button type="button" onClick={() => { setExpandedSessionNumber(expandedSessionNumber === slot.number ? null : slot.number); setExpandedScheduleId(null); }} className="shrink-0 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100">{expandedSessionNumber === slot.number ? 'Sembunyikan' : `Lihat booking (${bookings.length})`}</button>}{remaining > 0 && assessments.length > 0 && <button type="button" onClick={() => openAddScheduleModal(slot.number)} className="shrink-0 rounded-lg bg-orange-500 px-3 py-2 text-xs font-semibold text-white transition hover:bg-orange-600">+ Tambah</button>}</div>
                                </div>
                                <div className="h-1 bg-slate-100"><div className={remaining > 0 ? 'h-full bg-orange-400' : 'h-full bg-rose-500'} style={{ width: `${percentage}%` }} /></div>
                                {expandedSessionNumber === slot.number && bookings.length > 0 && <div className="border-t border-slate-100 bg-slate-50/60">
                                    <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-2.5 text-xs text-slate-600"><span><strong className="text-slate-900">{bookings.length} booking sekolah</strong></span><span>Total {bookings.reduce((total, schedule) => total + schedule.student_count, 0).toLocaleString('id-ID')} siswa</span></div>
                                    <div className="max-h-[28rem] overflow-auto">
                                        <div className="sticky top-0 z-10 hidden grid-cols-[minmax(240px,2fr)_80px_90px_minmax(130px,1fr)_auto] gap-3 border-b border-slate-200 bg-slate-100 px-4 py-2 text-[10px] font-bold uppercase tracking-wide text-slate-500 lg:grid"><span>Sekolah & paket</span><span>Kuota</span><span>Masuk</span><span>Dibuat oleh</span><span>Aksi</span></div>
                                        <div className="divide-y divide-slate-200">{bookings.map((schedule) => <div key={schedule.id} className="bg-white">
                                            <div className="grid gap-2 px-4 py-3 text-sm lg:grid-cols-[minmax(240px,2fr)_80px_90px_minmax(130px,1fr)_auto] lg:items-center lg:gap-3">
                                                <div className="min-w-0"><p className="truncate font-semibold text-slate-900">{schedule.assessment.title}</p><p className="mt-0.5 truncate text-xs text-slate-500">NPSN <span className="font-mono text-indigo-700">{schedule.school_npsn}</span> · Kelas {schedule.assessment.grade_level}</p></div>
                                                <p className="text-slate-700"><span className="mr-1 text-xs text-slate-400 lg:hidden">Kuota:</span><strong>{schedule.student_count}</strong></p>
                                                <p className="text-slate-700"><span className="mr-1 text-xs text-slate-400 lg:hidden">Masuk:</span><strong>{schedule.participants.length}</strong>/{schedule.student_count}</p>
                                                <p className="truncate text-xs text-slate-600"><span className="mr-1 text-slate-400 lg:hidden">Oleh:</span>{schedule.creator.name}</p>
                                                <div className="flex items-center gap-3 lg:justify-end"><button type="button" onClick={() => setExpandedScheduleId(expandedScheduleId === schedule.id ? null : schedule.id)} className="whitespace-nowrap text-xs font-semibold text-indigo-700 hover:text-indigo-500">{expandedScheduleId === schedule.id ? 'Tutup peserta' : 'Peserta'}</button><button type="button" onClick={() => window.confirm('Hapus jadwal ini?') && router.delete(route('schedules.destroy', schedule.id), { preserveScroll: true })} className="text-xs font-semibold text-rose-700">Hapus</button></div>
                                            </div>
                                            {expandedScheduleId === schedule.id && <div className="border-t border-indigo-100 bg-indigo-50/70 px-4 py-3">{schedule.participants.length === 0 ? <p className="text-xs text-slate-500">Belum ada siswa yang mulai mengerjakan.</p> : <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{schedule.participants.map((participant, index) => <div key={participant.id} className="rounded-md bg-white px-3 py-2 shadow-sm"><p className="truncate text-xs font-semibold text-slate-900">{index + 1}. {participant.name}</p><p className="mt-0.5 truncate text-[11px] text-slate-500">{participant.student_identifier || '-'} · {new Date(participant.pivot.started_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</p></div>)}</div>}</div>}
                                        </div>)}</div>
                                    </div>
                                </div>}
                            </article>;
                        })}
                    </div>
                </section>
            </div>

            <Modal show={addScheduleModalOpen} maxWidth="lg" onClose={() => setAddScheduleModalOpen(false)}>
                <form onSubmit={submit}>
                    <div className="border-b border-slate-200 px-6 py-5">
                        <div className="flex items-start justify-between gap-4">
                            <div><h2 className="text-lg font-bold text-slate-900">Tambah jadwal sesi</h2><p className="mt-1 text-sm text-slate-500">Pilih paket dan tentukan jumlah siswa untuk sesi ini.</p></div>
                            <button type="button" onClick={() => setAddScheduleModalOpen(false)} className="rounded-lg p-1 text-xl leading-none text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Tutup popup">×</button>
                        </div>
                    </div>
                    <div className="space-y-4 px-6 py-5">
                        <div className="grid grid-cols-2 gap-3 rounded-xl bg-orange-50 p-4 text-sm">
                            <div><p className="text-xs text-slate-500">Tanggal</p><p className="mt-1 font-semibold text-slate-900">{new Date(`${data.scheduled_date}T00:00:00`).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</p></div>
                            <div><p className="text-xs text-slate-500">Sesi</p><p className="mt-1 font-semibold text-slate-900">Sesi {data.session_number} · {slots.find((slot) => slot.number === data.session_number)?.label}</p></div>
                        </div>
                        <InputError message={errors.scheduled_date || errors.session_number} />
                        <label className="block text-sm font-medium text-slate-700">Paket ujian<select value={data.assessment_id} onChange={(event) => setData('assessment_id', Number(event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300">{assessments.map((assessment) => <option key={assessment.id} value={assessment.id}>{assessment.title} · Kelas {assessment.grade_level}</option>)}</select><InputError message={errors.assessment_id} className="mt-1" /></label>
                        {canChooseNpsn && <label className="block text-sm font-medium text-slate-700">NPSN tujuan<input type="text" inputMode="numeric" maxLength={8} list="registered-school-npsn-modal" value={data.school_npsn} onChange={(event) => setData('school_npsn', event.target.value.replace(/\D/g, '').slice(0, 8))} placeholder="8 digit NPSN" className="mt-1 block w-full rounded-lg border-slate-300 font-mono" /><datalist id="registered-school-npsn-modal">{schools.map((school) => <option key={school.npsn} value={school.npsn}>{school.name}</option>)}</datalist><InputError message={errors.school_npsn} className="mt-1" /></label>}
                        {!canChooseNpsn && <div className="rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-800">NPSN sekolah: <strong className="font-mono">{schoolNpsn}</strong></div>}
                        <label className="block text-sm font-medium text-slate-700">Siswa / sesi<input type="text" inputMode="numeric" pattern="[0-9]*" maxLength={6} value={data.student_count || ''} onChange={(event) => setData('student_count', Number(event.target.value.replace(/\D/g, '').slice(0, 6)))} placeholder="Contoh: 20" className="mt-1 block w-full rounded-lg border-slate-300" /><InputError message={errors.student_count} className="mt-1" /></label>
                    </div>
                    <div className="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
                        <button type="button" onClick={() => setAddScheduleModalOpen(false)} className="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Batal</button>
                        <button disabled={processing} className="rounded-lg bg-orange-500 px-5 py-2.5 text-sm font-semibold text-white hover:bg-orange-600 disabled:opacity-50">{processing ? 'Menyimpan...' : 'Tambah sesi'}</button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
