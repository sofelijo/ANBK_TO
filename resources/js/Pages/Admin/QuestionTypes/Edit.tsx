import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type QuestionTypeOption = {
    value: string;
    label: string;
    description: string;
};

export default function Edit({ questionTypes, allQuestionTypes }: { questionTypes: QuestionTypeOption[]; allQuestionTypes: QuestionTypeOption[] }) {
    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        enabled_question_types: questionTypes.map((type) => type.value),
    });

    const toggle = (value: string) => {
        setData('enabled_question_types', data.enabled_question_types.includes(value)
            ? data.enabled_question_types.filter((item) => item !== value)
            : [...data.enabled_question_types, value]);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(route('admin.question-types.update'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Administrasi</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Bentuk Soal Aktif</h1><p className="mt-1 text-sm text-slate-500">Tentukan bentuk soal yang dapat dipilih saat membuat soal baru di sekolah ini.</p></div>}>
            <Head title="Bentuk Soal Aktif" />
            <div className="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div className="space-y-3">
                        {allQuestionTypes.map((type) => {
                            const active = data.enabled_question_types.includes(type.value);
                            return (
                                <label key={type.value} className={`flex cursor-pointer items-start gap-4 rounded-xl border p-4 transition ${active ? 'border-emerald-300 bg-emerald-50/60' : 'border-slate-200 bg-white hover:border-slate-300'}`}>
                                    <input type="checkbox" checked={active} onChange={() => toggle(type.value)} className="mt-1 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                                    <span className="min-w-0 flex-1"><span className="flex items-center gap-2 font-semibold text-slate-900">{type.label}<span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{active ? 'Aktif' : 'Nonaktif'}</span></span><span className="mt-1 block text-sm leading-6 text-slate-500">{type.description}</span></span>
                                </label>
                            );
                        })}
                    </div>
                    <InputError message={errors.enabled_question_types} className="mt-3" />
                    <p className="mt-5 rounded-lg bg-amber-50 p-3 text-xs leading-5 text-amber-800">Menonaktifkan bentuk soal hanya mencegah pemakaian pada soal baru. Soal lama tetap tersimpan, dapat ditampilkan, dan dapat diedit.</p>
                    <div className="mt-6 flex items-center gap-4">
                        <button disabled={processing || data.enabled_question_types.length === 0} className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Simpan pengaturan</button>
                        {recentlySuccessful && <span className="text-sm font-medium text-emerald-700">Tersimpan</span>}
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
