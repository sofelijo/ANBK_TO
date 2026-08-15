import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';

type School = {
    name: string;
    npsn: string;
    timezone: string;
    address: string;
    province: string;
    city: string;
    principal_name: string;
    phone: string;
};

type Timezone = { value: string; label: string };

export default function Edit({ school, timezones }: { school: School; timezones: Timezone[] }) {
    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm(school);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(route('school.update'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Administrasi</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Data Sekolah</h1><p className="mt-1 text-sm text-slate-500">Identitas ini digunakan untuk login siswa dan klaim jadwal try out.</p></div>}>
            <Head title="Data Sekolah" />
            <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field label="Nama sekolah" error={errors.name}><input value={data.name} onChange={(event) => setData('name', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /></Field>
                        <Field label="NPSN" error={errors.npsn}><input inputMode="numeric" maxLength={8} value={data.npsn} onChange={(event) => setData('npsn', event.target.value.replace(/\D/g, '').slice(0, 8))} className="mt-1 block w-full rounded-lg border-slate-300" /><p className="mt-1 text-xs text-amber-700">Perubahan NPSN otomatis diterapkan ke jadwal sekolah.</p></Field>
                        <Field label="Nama kepala sekolah" error={errors.principal_name}><input value={data.principal_name} onChange={(event) => setData('principal_name', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /></Field>
                        <Field label="Nomor telepon" error={errors.phone}><input value={data.phone} onChange={(event) => setData('phone', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /></Field>
                        <Field label="Provinsi" error={errors.province}><input value={data.province} onChange={(event) => setData('province', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /></Field>
                        <Field label="Kabupaten / kota" error={errors.city}><input value={data.city} onChange={(event) => setData('city', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /></Field>
                        <Field label="Zona waktu" error={errors.timezone}><select value={data.timezone} onChange={(event) => setData('timezone', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300">{timezones.map((timezone) => <option key={timezone.value} value={timezone.value}>{timezone.label}</option>)}</select></Field>
                        <div className="sm:col-span-2"><Field label="Alamat sekolah" error={errors.address}><textarea rows={4} value={data.address} onChange={(event) => setData('address', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /></Field></div>
                    </div>
                    <div className="mt-6 flex items-center gap-4">
                        <button disabled={processing} className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Simpan data sekolah</button>
                        {recentlySuccessful && <span className="text-sm font-medium text-emerald-700">Tersimpan</span>}
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return <label className="block text-sm font-medium text-slate-700">{label}{children}<InputError message={error} className="mt-1" /></label>;
}
