import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type AnswerFormat = 'single_choice' | 'true_false' | 'multiple_choice';
type CognitiveLevel = 'textual' | 'inferential' | 'evaluation';
type Slot = { answer_format: AnswerFormat; cognitive_level: CognitiveLevel };

const answerFormats: { value: AnswerFormat; label: string }[] = [
    { value: 'single_choice', label: 'Pilihan Ganda' },
    { value: 'true_false', label: 'Benar / Salah' },
    { value: 'multiple_choice', label: 'MCMA' },
];
const cognitiveLevels: { value: CognitiveLevel; label: string }[] = [
    { value: 'textual', label: 'Pemahaman Tekstual (Level 1)' },
    { value: 'inferential', label: 'Pemahaman Inferensial (Level 2)' },
    { value: 'evaluation', label: 'Evaluasi dan Apresiasi (Level 3)' },
];

export default function Edit({ slots }: { slots: Slot[] }) {
    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({ slots });
    const updateSlot = <Key extends keyof Slot>(index: number, key: Key, value: Slot[Key]) => {
        const previousValue = data.slots[index][key];
        const occupiedIndex = data.slots.findIndex((slot, slotIndex) => slotIndex !== index && slot[key] === value);
        setData('slots', data.slots.map((slot, slotIndex) => {
            if (slotIndex === index) return { ...slot, [key]: value };
            if (slotIndex === occupiedIndex) return { ...slot, [key]: previousValue };

            return slot;
        }));
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(route('admin.indonesian-bundles.update'), { preserveScroll: true });
    };

    return <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Administrasi</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Default Bundle Bahasa Indonesia</h1><p className="mt-1 text-sm text-slate-500">Atur pasangan awal format jawaban dan tingkat kognitif untuk satu cerita dengan tiga soal.</p></div>}>
        <Head title="Default Bundle Bahasa Indonesia" />
        <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6">
            <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div className="rounded-xl bg-indigo-50 p-3"><span className="text-xs font-semibold text-indigo-600">STIMULUS</span><strong className="mt-1 block text-slate-900">1 cerita</strong></div>
                    <div className="rounded-xl bg-emerald-50 p-3"><span className="text-xs font-semibold text-emerald-600">KOMPOSISI</span><strong className="mt-1 block text-slate-900">3 soal</strong></div>
                    <div className="col-span-2 rounded-xl bg-amber-50 p-3 sm:col-span-1"><span className="text-xs font-semibold text-amber-700">CAKUPAN</span><strong className="mt-1 block text-slate-900">Level 1–3 lengkap</strong></div>
                </div>
                <div className="overflow-hidden rounded-xl border border-slate-200">
                    <div className="hidden grid-cols-[72px_minmax(0,1fr)_minmax(0,1fr)] gap-3 bg-slate-50 px-4 py-2 text-xs font-bold uppercase tracking-wide text-slate-500 sm:grid"><span>Slot</span><span>Format jawaban</span><span>Tingkat kognitif</span></div>
                    <div className="divide-y divide-slate-100">{data.slots.map((slot, index) => <div key={index} className="grid gap-3 px-4 py-4 sm:grid-cols-[72px_minmax(0,1fr)_minmax(0,1fr)] sm:items-center">
                        <strong className="text-sm text-slate-700">Soal {index + 1}</strong>
                        <label className="text-xs font-semibold text-slate-500 sm:text-transparent">Format jawaban<select value={slot.answer_format} onChange={(event) => updateSlot(index, 'answer_format', event.target.value as AnswerFormat)} className="mt-1 block w-full rounded-lg border-slate-300 text-sm text-slate-800 sm:mt-0">{answerFormats.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
                        <label className="text-xs font-semibold text-slate-500 sm:text-transparent">Tingkat kognitif<select value={slot.cognitive_level} onChange={(event) => updateSlot(index, 'cognitive_level', event.target.value as CognitiveLevel)} className="mt-1 block w-full rounded-lg border-slate-300 text-sm text-slate-800 sm:mt-0">{cognitiveLevels.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
                    </div>)}</div>
                </div>
                <InputError message={errors.slots} className="mt-3" />
                <p className="mt-4 text-xs leading-5 text-slate-500">Ketiga format dan ketiga level wajib digunakan masing-masing tepat satu kali. Guru masih dapat menukar pasangan ini saat membuat bundle tanpa mengubah default sekolah.</p>
                <div className="mt-6 flex items-center gap-4"><button disabled={processing} className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Simpan default</button>{recentlySuccessful && <span className="text-sm font-medium text-emerald-700">Tersimpan</span>}</div>
            </form>
        </div>
    </AuthenticatedLayout>;
}
