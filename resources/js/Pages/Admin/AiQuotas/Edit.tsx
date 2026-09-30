import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Quotas = {
    question_variants: number;
    story_questions: number;
    story_illustrations: number;
};

type School = { id: number; name: string; npsn: string };

export default function Edit({ quotas, school, schools }: { quotas: Quotas; school: School; schools: School[] }) {
    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({ ...quotas, school_id: school.id });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(route('admin.ai-quotas.update'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Administrasi</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Kuota AI Guru</h1><p className="mt-1 text-sm text-slate-500">Atur batas harian untuk setiap guru di sekolah ini. Admin tidak terkena batas kuota.</p></div>}>
            <Head title="Kuota AI Guru" />
            <div className="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
                <label className="mb-5 block text-sm font-medium text-slate-700">Sekolah<select value={school.id} onChange={(event) => router.get(route('admin.ai-quotas.edit'), { school_id: Number(event.target.value) })} className="mt-1 block w-full rounded-lg border-slate-300 bg-white">{schools.map((option) => <option key={option.id} value={option.id}>{option.name} ({option.npsn})</option>)}</select></label>
                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div className="space-y-5">
                        <QuotaField label="Paket soal AI" description="Pembuatan soal langsung maupun paket soal cerita." value={data.story_questions} error={errors.story_questions} onChange={(value) => setData('story_questions', value)} />
                        <QuotaField label="Variasi soal AI" description="Pembuatan variasi dari soal yang sudah ada." value={data.question_variants} error={errors.question_variants} onChange={(value) => setData('question_variants', value)} />
                        <QuotaField label="Ilustrasi soal AI" description="Pembuatan ilustrasi untuk paket soal." value={data.story_illustrations} error={errors.story_illustrations} onChange={(value) => setData('story_illustrations', value)} />
                    </div>
                    <p className="mt-5 text-xs leading-5 text-slate-500">Nilai 0 menonaktifkan fitur tersebut untuk guru. Rentang yang diizinkan 0–1.000 penggunaan per guru per hari.</p>
                    <div className="mt-6 flex items-center gap-4">
                        <button disabled={processing} className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Simpan kuota</button>
                        {recentlySuccessful && <span className="text-sm font-medium text-emerald-700">Tersimpan</span>}
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}

function QuotaField({ label, description, value, error, onChange }: { label: string; description: string; value: number; error?: string; onChange: (value: number) => void }) {
    return <label className="block rounded-xl border border-slate-200 p-4"><span className="font-semibold text-slate-900">{label}</span><span className="mt-1 block text-sm text-slate-500">{description}</span><div className="mt-3 flex items-center gap-3"><input type="number" min={0} max={1000} value={value} onChange={(event) => onChange(Number(event.target.value))} className="w-32 rounded-lg border-slate-300" /><span className="text-sm text-slate-600">kali / guru / hari</span></div><InputError message={error} className="mt-1" /></label>;
}
