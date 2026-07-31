import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useMemo } from 'react';

type Question = {
    id: number;
    title?: string;
    prompt: string;
    grade_level: number;
    difficulty: number;
    competency: { code: string; name: string };
};

type AssessmentForm = {
    title: string;
    description: string;
    grade_level: number;
    duration_minutes: number;
    assessment_type: string;
    custom_type_name: string;
    selection_mode: 'manual' | 'automatic';
    question_count: number;
    question_ids: number[];
    starts_at: string;
    ends_at: string;
    shuffle_questions: boolean;
    shuffle_options: boolean;
    show_navigation: boolean;
    require_all_answers: boolean;
};

type Props = {
    questions: Question[];
    assessmentTypes: Record<string, string>;
    assessment?: AssessmentForm & { id: number };
};

export default function Create({ questions, assessmentTypes, assessment }: Props) {
    const emptyForm: AssessmentForm = {
        title: '',
        description: '',
        grade_level: 5,
        duration_minutes: 60,
        assessment_type: 'tryout',
        custom_type_name: '',
        selection_mode: 'manual',
        question_count: 5,
        question_ids: [],
        starts_at: '',
        ends_at: '',
        shuffle_questions: false,
        shuffle_options: false,
        show_navigation: true,
        require_all_answers: false,
    };
    const { data, setData, post, put, processing, errors } = useForm<AssessmentForm>(assessment || emptyForm);
    const availableQuestions = useMemo(
        () => questions.filter((question) => question.grade_level === data.grade_level),
        [questions, data.grade_level],
    );

    const toggleQuestion = (id: number) => {
        if (data.question_ids.includes(id)) {
            setData('question_ids', data.question_ids.filter((questionId) => questionId !== id));
            return;
        }

        if (data.question_ids.length < data.question_count) {
            setData('question_ids', [...data.question_ids, id]);
        }
    };

    const updateQuestionCount = (count: number) => {
        setData((current) => ({
            ...current,
            question_count: count,
            question_ids: current.question_ids.slice(0, count),
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (assessment) {
            put(route('assessments.update', assessment.id));
        } else {
            post(route('assessments.store'));
        }
    };

    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Paket Ujian</p><h1 className="mt-1 text-2xl font-bold text-slate-900">{assessment ? 'Edit Paket Try Out' : 'Buat Jenis Try Out Baru'}</h1></div>}>
            <Head title={assessment ? 'Edit Paket' : 'Buat Paket'} />
            <form onSubmit={submit} className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div>
                        <h2 className="font-semibold text-slate-900">Identitas paket</h2>
                        <p className="mt-1 text-sm text-slate-500">Jenis custom memungkinkan sekolah membuat format ujian baru tanpa perubahan aplikasi.</p>
                    </div>
                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        <label className="text-sm font-medium text-slate-700">Judul<input value={data.title} onChange={(event) => setData('title', event.target.value)} placeholder="Contoh: Simulasi ANBK Semester Ganjil" className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" /><InputError message={errors.title} /></label>
                        <label className="text-sm font-medium text-slate-700">Jenis try out<select value={data.assessment_type} onChange={(event) => setData('assessment_type', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500">{Object.entries(assessmentTypes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
                        {data.assessment_type === 'custom' && <label className="text-sm font-medium text-slate-700 sm:col-span-2">Nama jenis custom<input value={data.custom_type_name} onChange={(event) => setData('custom_type_name', event.target.value)} placeholder="Contoh: Seleksi Olimpiade Sekolah" className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" /><InputError message={errors.custom_type_name} /></label>}
                        <label className="text-sm font-medium text-slate-700">Jenjang<select value={data.grade_level} onChange={(event) => setData((current) => ({ ...current, grade_level: Number(event.target.value), question_ids: [] }))} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"><option value={5}>Kelas 5</option><option value={8}>Kelas 8</option><option value={11}>Kelas 11</option></select></label>
                        <label className="text-sm font-medium text-slate-700">Durasi pengerjaan<input type="number" min={5} max={480} value={data.duration_minutes} onChange={(event) => setData('duration_minutes', Number(event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" /><span className="mt-1 block text-xs text-slate-400">5–480 menit</span><InputError message={errors.duration_minutes} /></label>
                        <label className="text-sm font-medium text-slate-700 sm:col-span-2">Deskripsi / petunjuk<textarea value={data.description} onChange={(event) => setData('description', event.target.value)} rows={3} placeholder="Petunjuk yang akan dibaca peserta sebelum mengerjakan." className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" /></label>
                    </div>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Jumlah dan sumber soal</h2>
                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        <label className="text-sm font-medium text-slate-700">Target jumlah soal<input type="number" min={1} max={100} value={data.question_count} onChange={(event) => updateQuestionCount(Number(event.target.value))} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" /><InputError message={errors.question_count} /></label>
                        <label className="text-sm font-medium text-slate-700">Cara memilih soal<select value={data.selection_mode} onChange={(event) => setData((current) => ({ ...current, selection_mode: event.target.value as AssessmentForm['selection_mode'], question_ids: [] }))} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"><option value="manual">Dipilih guru</option><option value="automatic">Dipilih otomatis dari bank soal</option></select></label>
                    </div>

                    {data.selection_mode === 'automatic' ? (
                        <div className="mt-5 rounded-xl border border-indigo-200 bg-indigo-50 p-5 text-sm text-indigo-900">
                            Sistem akan mengambil acak <strong>{data.question_count} soal</strong> dari {availableQuestions.length} soal terbit untuk kelas {data.grade_level} saat paket disimpan.
                        </div>
                    ) : (
                        <div className="mt-6">
                            <div className="flex items-center justify-between"><h3 className="text-sm font-semibold text-slate-900">Pilih soal</h3><span className={`text-sm font-semibold ${data.question_ids.length === data.question_count ? 'text-emerald-600' : 'text-amber-600'}`}>{data.question_ids.length}/{data.question_count} dipilih</span></div>
                            <InputError message={errors.question_ids} className="mt-2" />
                            <div className="mt-3 max-h-[480px] divide-y divide-slate-100 overflow-y-auto border-y border-slate-100">
                                {availableQuestions.length === 0 ? <p className="py-8 text-center text-sm text-slate-500">Belum ada soal terbit untuk jenjang ini.</p> : availableQuestions.map((question) => {
                                    const selected = data.question_ids.includes(question.id);
                                    const limitReached = !selected && data.question_ids.length >= data.question_count;
                                    return (
                                        <label key={question.id} className={`flex gap-4 py-4 ${limitReached ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'}`}>
                                            <input type="checkbox" checked={selected} disabled={limitReached} onChange={() => toggleQuestion(question.id)} className="mt-1 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                                            <div><p className="font-medium text-slate-900">{question.title || question.prompt}</p><p className="mt-1 text-xs text-slate-500">{question.competency.code} · {question.competency.name} · kesulitan {question.difficulty}</p></div>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Jadwal akses</h2>
                    <p className="mt-1 text-sm text-slate-500">Kosongkan keduanya jika paket boleh dikerjakan kapan saja setelah diterbitkan.</p>
                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        <label className="text-sm font-medium text-slate-700">Mulai tersedia<input type="datetime-local" value={data.starts_at} onChange={(event) => setData('starts_at', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" /><InputError message={errors.starts_at} /></label>
                        <label className="text-sm font-medium text-slate-700">Ditutup pada<input type="datetime-local" value={data.ends_at} onChange={(event) => setData('ends_at', event.target.value)} className="mt-1 block w-full rounded-lg border-slate-300 focus:border-emerald-500 focus:ring-emerald-500" /><InputError message={errors.ends_at} /></label>
                    </div>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Perilaku saat ujian</h2>
                    <div className="mt-5 grid gap-3 sm:grid-cols-2">
                        {[
                            ['shuffle_questions', 'Acak urutan soal', 'Urutan stabil untuk setiap peserta tetapi berbeda antar peserta.'],
                            ['shuffle_options', 'Acak pilihan jawaban', 'Label A–D disusun ulang tanpa mengubah kunci jawaban.'],
                            ['show_navigation', 'Tampilkan navigasi nomor', 'Peserta dapat berpindah langsung ke nomor soal tertentu.'],
                            ['require_all_answers', 'Wajib jawab semua soal', 'Pengiriman ditolak sampai semua soal terisi atau waktu habis.'],
                        ].map(([key, title, description]) => (
                            <label key={key} className="flex cursor-pointer gap-3 rounded-xl border border-slate-200 p-4">
                                <input type="checkbox" checked={data[key as keyof AssessmentForm] as boolean} onChange={(event) => setData(key as keyof AssessmentForm, event.target.checked as never)} className="mt-1 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                                <span><span className="block text-sm font-semibold text-slate-900">{title}</span><span className="mt-1 block text-xs leading-5 text-slate-500">{description}</span></span>
                            </label>
                        ))}
                    </div>
                </section>

                <div className="flex justify-end gap-3"><Link href={route('assessments.index')} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Batal</Link><button disabled={processing} className="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">{assessment ? 'Simpan perubahan' : 'Simpan sebagai draft'}</button></div>
            </form>
        </AuthenticatedLayout>
    );
}
