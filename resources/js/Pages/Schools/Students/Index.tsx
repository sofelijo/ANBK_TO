import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Student = {
    id: number;
    name: string;
    nisn?: string;
    parent_email?: string;
    grade_level?: number;
    is_active: boolean;
    approved_at?: string;
    attempts_count: number;
    completed_attempts_count: number;
    last_login_at?: string;
    registered_at: string;
};

type Props = {
    school: { name: string; npsn: string };
    students: {
        data: Student[];
        links: { url?: string; label: string; active: boolean }[];
        from?: number;
        to?: number;
        total: number;
    };
    filters: { search?: string; grade?: number; status?: string };
    summary: { total: number; active: number; pending: number; inactive: number };
};

export default function Index({ school, students, filters, summary }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [grade, setGrade] = useState(filters.grade?.toString() || '');
    const [status, setStatus] = useState(filters.status || '');

    const filter = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('school.students.index'), { search, grade: grade || undefined, status }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Administrasi</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Data Siswa</h1><p className="mt-1 text-sm text-slate-500">{school.name} · NPSN <span className="font-semibold text-slate-700">{school.npsn}</span></p></div>}>
            <Head title="Data Siswa" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryCard label="Total siswa" value={summary.total} />
                    <SummaryCard label="Akun aktif" value={summary.active} tone="emerald" />
                    <SummaryCard label="Menunggu persetujuan" value={summary.pending} tone="amber" />
                    <SummaryCard label="Akun nonaktif" value={summary.inactive} tone="slate" />
                </section>

                <form onSubmit={filter} className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row">
                    <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari nama atau NISN" className="min-w-0 flex-1 rounded-lg border-slate-300 text-sm" />
                    <select value={grade} onChange={(event) => setGrade(event.target.value)} className="rounded-lg border-slate-300 text-sm"><option value="">Semua kelas</option><option value="6">Kelas 6</option><option value="9">Kelas 9</option><option value="12">Kelas 12</option></select>
                    <select value={status} onChange={(event) => setStatus(event.target.value)} className="rounded-lg border-slate-300 text-sm"><option value="">Semua status</option><option value="pending">Menunggu persetujuan</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select>
                    <button className="rounded-lg bg-slate-900 px-5 py-2 text-sm font-semibold text-white">Terapkan</button>
                </form>

                <section className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                    {students.data.length === 0 ? <p className="p-10 text-center text-sm text-slate-500">Belum ada siswa yang sesuai filter.</p> : <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-200 text-sm"><thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Siswa</th><th className="px-5 py-3">Kelas</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Try Out</th><th className="px-5 py-3">Login terakhir</th><th className="px-5 py-3">Terdaftar</th><th className="px-5 py-3">Aksi</th></tr></thead><tbody className="divide-y divide-slate-100">{students.data.map((student) => {
                        const pending = !student.is_active && !student.approved_at;
                        return <tr key={student.id} className={pending ? 'bg-amber-50/50' : ''}><td className="px-5 py-4"><p className="font-semibold text-slate-900">{student.name}</p><p className="mt-1 font-mono text-xs text-slate-500">NISN {student.nisn || '-'}</p><p className="mt-1 text-xs text-slate-500">Email orang tua: {student.parent_email || '-'}</p></td><td className="px-5 py-4 text-slate-700">{student.grade_level ? `Kelas ${student.grade_level}` : '-'}</td><td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${pending ? 'bg-amber-100 text-amber-800' : student.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>{pending ? 'Menunggu persetujuan' : student.is_active ? 'Aktif' : 'Nonaktif'}</span></td><td className="px-5 py-4 text-slate-700">{student.completed_attempts_count} selesai<br /><span className="text-xs text-slate-500">{student.attempts_count} total</span></td><td className="px-5 py-4 text-slate-600">{formatDate(student.last_login_at)}</td><td className="px-5 py-4 text-slate-600">{formatDate(student.registered_at)}</td><td className="px-5 py-4">{pending ? <button type="button" onClick={() => router.patch(route('school.students.approve', student.id), {}, { preserveScroll: true })} className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-500">Setujui Murid</button> : <span className="text-xs text-slate-400">—</span>}</td></tr>;
                    })}</tbody></table></div>}
                </section>

                <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center"><p className="text-sm text-slate-500">Menampilkan {students.from || 0}–{students.to || 0} dari {students.total} siswa.</p><div className="flex flex-wrap gap-2">{students.links.map((link, index) => <button key={index} disabled={!link.url} onClick={() => link.url && router.get(link.url, {}, { preserveState: true })} className={`rounded-lg border px-3 py-2 text-sm ${link.active ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-600'} disabled:opacity-40`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div></div>
            </div>
        </AuthenticatedLayout>
    );
}

function SummaryCard({ label, value, tone = 'indigo' }: { label: string; value: number; tone?: 'indigo' | 'emerald' | 'amber' | 'slate' }) {
    const colors = { indigo: 'text-indigo-700', emerald: 'text-emerald-700', amber: 'text-amber-700', slate: 'text-slate-700' };
    return <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">{label}</p><p className={`mt-2 text-3xl font-bold ${colors[tone]}`}>{value}</p></div>;
}

function formatDate(value?: string): string {
    return value ? new Date(value).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : 'Belum pernah';
}
