import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';

type School = {
    name: string;
    npsn: string;
    subdistrict: string | null;
    timezone: string | null;
    address: string | null;
    province: string | null;
    city: string | null;
    principal_name: string | null;
    phone: string | null;
    admin_whatsapp: string | null;
};

type Timezone = { value: string; label: string };

export default function Edit({ school, timezones, subdistricts }: { school: School; timezones: Timezone[]; subdistricts: string[] }) {
    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        ...school,
        subdistrict: school.subdistrict ?? '',
        timezone: school.timezone ?? '',
        address: school.address ?? '',
        province: school.province ?? '',
        city: school.city ?? '',
        principal_name: school.principal_name ?? '',
        phone: school.phone ?? '',
        admin_whatsapp: school.admin_whatsapp ?? '',
    });

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
                        <Field label="Kecamatan" error={errors.subdistrict}>
                            <select
                                value={data.subdistrict ?? ''}
                                onChange={(event) => setData('subdistrict', event.target.value)}
                                className="mt-1 block w-full rounded-lg border-slate-300"
                                required
                            >
                                <option value="">Pilih kecamatan</option>
                                {subdistricts.map((subdistrict) => <option key={subdistrict} value={subdistrict}>{subdistrict}</option>)}
                            </select>
                            <p className="mt-1 text-xs text-slate-500">Digunakan pada analisis wilayah Sudin Pendidikan Jakarta Utara Wilayah II.</p>
                        </Field>
                        <Field label="Nama kepala sekolah" error={errors.principal_name}><input value={data.principal_name} onChange={(event) => setData('principal_name', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /></Field>
                        <Field label="Nomor telepon" error={errors.phone}><input value={data.phone} onChange={(event) => setData('phone', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /></Field>
                        <Field label="Provinsi" error={errors.province}><input value={data.province} onChange={(event) => setData('province', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /></Field>
                        <Field label="Kabupaten / kota" error={errors.city}><input value={data.city} onChange={(event) => setData('city', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300" /></Field>
                        <Field label="Zona waktu" error={errors.timezone}><select value={data.timezone} onChange={(event) => setData('timezone', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300">{timezones.map((timezone) => <option key={timezone.value} value={timezone.value}>{timezone.label}</option>)}</select></Field>
                        <div className="sm:col-span-2">
                            <Field label="Nomor WhatsApp Admin" error={errors.admin_whatsapp}>
                                <input
                                    inputMode="numeric"
                                    maxLength={20}
                                    value={data.admin_whatsapp}
                                    onChange={(event) => setData('admin_whatsapp', event.target.value.replace(/\D/g, '').slice(0, 20))}
                                    placeholder="Contoh: 628123456789"
                                    className="mt-1 block w-full rounded-lg border-slate-300"
                                />
                                <p className="mt-1 text-xs text-slate-500">
                                    Nomor internasional tanpa tanda + atau strip. Digunakan untuk tombol konfirmasi verifikasi guru via WhatsApp.
                                </p>
                            </Field>
                        </div>
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
