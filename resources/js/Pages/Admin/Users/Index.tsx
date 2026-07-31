import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type User = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'teacher' | 'student';
    student_identifier?: string;
    grade_level?: number;
    is_active: boolean;
    approved_at?: string;
    last_login_at?: string;
};

type Props = {
    users: {
        data: User[];
        links: { url?: string; label: string; active: boolean }[];
    };
    filters: { search?: string; role?: string; status?: string };
    schoolNpsn: string;
    pendingCount: number;
};

export default function Index({ users, filters, schoolNpsn, pendingCount }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [roleFilter, setRoleFilter] = useState(filters.role || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || '');
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        role: 'student',
        student_identifier: '',
        grade_level: 5,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.users.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const applyFilter = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('admin.users.index'), { search, role: roleFilter, status: statusFilter }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header={<div><div className="flex flex-wrap items-center gap-3"><div><p className="text-sm font-medium text-emerald-600">Administrasi</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Pengguna Sekolah</h1><p className="mt-1 text-sm text-slate-500">NPSN sekolah: <span className="font-semibold text-slate-700">{schoolNpsn}</span></p></div>{pendingCount > 0 && <span className="rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-800">{pendingCount} guru menunggu persetujuan</span>}</div></div>}>
            <Head title="Pengguna" />
            <div className="mx-auto grid max-w-7xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[360px_1fr] lg:px-8">
                <form onSubmit={submit} className="h-fit rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Tambah pengguna</h2>
                    <div className="mt-4 space-y-4">
                        <label className="block text-sm font-medium text-slate-700">Nama<input value={data.name} onChange={(event) => setData('name', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /><InputError message={errors.name} /></label>
                        <label className="block text-sm font-medium text-slate-700">Email<input type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /><InputError message={errors.email} /></label>
                        <label className="block text-sm font-medium text-slate-700">Kata sandi awal<input type="password" value={data.password} onChange={(event) => setData('password', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /><InputError message={errors.password} /></label>
                        <label className="block text-sm font-medium text-slate-700">Peran<select value={data.role} onChange={(event) => setData('role', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300"><option value="student">Murid</option><option value="teacher">Guru</option></select></label>
                        {data.role === 'student' && <><label className="block text-sm font-medium text-slate-700">Nomor peserta / NIS<input value={data.student_identifier} onChange={(event) => setData('student_identifier', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /><InputError message={errors.student_identifier} /></label><label className="block text-sm font-medium text-slate-700">Kelas<select value={data.grade_level} onChange={(event) => setData('grade_level', Number(event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300"><option value={5}>Kelas 5</option><option value={8}>Kelas 8</option><option value={11}>Kelas 11</option></select></label></>}
                    </div>
                    <button disabled={processing} className="mt-5 w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Tambah pengguna</button>
                </form>

                <div>
                    <form onSubmit={applyFilter} className="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row">
                        <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari nama, email, atau NIS" className="min-w-0 flex-1 rounded-lg border-slate-300 text-sm" />
                        <select value={roleFilter} onChange={(event) => setRoleFilter(event.target.value)} className="rounded-lg border-slate-300 text-sm"><option value="">Semua peran</option><option value="admin">Admin</option><option value="teacher">Guru</option><option value="student">Murid</option></select>
                        <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} className="rounded-lg border-slate-300 text-sm"><option value="">Semua status</option><option value="pending">Menunggu persetujuan</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select>
                        <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Cari</button>
                    </form>
                    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <div className="divide-y divide-slate-100">
                            {users.data.map((user) => {
                                const pending = user.role === 'teacher' && !user.is_active && !user.approved_at;

                                return <div key={user.id} className={`flex flex-col justify-between gap-3 p-5 sm:flex-row sm:items-center ${pending ? 'bg-amber-50/60' : ''}`}><div><div className="flex flex-wrap items-center gap-2"><p className="font-semibold text-slate-900">{user.name}</p><span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${pending ? 'bg-amber-100 text-amber-800' : user.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{pending ? 'menunggu persetujuan' : user.is_active ? 'aktif' : 'nonaktif'}</span></div><p className="mt-1 text-sm text-slate-500">{user.email} · {user.role}{user.grade_level ? ` · kelas ${user.grade_level}` : ''}</p></div>{pending ? <button onClick={() => router.patch(route('admin.users.approve', user.id), {}, { preserveScroll: true })} className="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-500">Setujui Guru</button> : <button onClick={() => router.patch(route('admin.users.toggle-active', user.id), {}, { preserveScroll: true })} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700">{user.is_active ? 'Nonaktifkan' : 'Aktifkan'}</button>}</div>;
                            })}
                        </div>
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2">{users.links.map((link, index) => <button key={index} disabled={!link.url} onClick={() => link.url && router.get(link.url)} className={`rounded-lg border px-3 py-2 text-sm ${link.active ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-600'} disabled:opacity-40`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
