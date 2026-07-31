import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Generation = {
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    request_payload: { theme: string; paragraph_count?: number; question_count?: number };
    result_payload?: { title?: string; question_count?: number };
    created_at: string;
};

export default function StoryCreate({ recentGenerations }: { recentGenerations: Generation[] }) {
    const { data, setData, post, processing, errors } = useForm({
        theme: '',
        paragraph_count: 3,
        question_count: 3,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('story-questions.store'));
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-sm font-medium text-indigo-600">Asisten AI</p>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900">Buat Soal Cerita</h1>
                </div>
            }
        >
            <Head title="Buat Soal Cerita AI" />
            <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[1fr_360px]">
                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div className="rounded-xl bg-indigo-50 p-5">
                        <h2 className="font-semibold text-indigo-950">Tentukan tema dan panjang paket</h2>
                        <p className="mt-2 text-sm leading-6 text-indigo-800">
                            AI akan memilih kompetensi yang tersedia, menulis cerita sesuai jumlah paragraf, lalu membuat soal sebanyak yang dipilih. Semua hasil disimpan sebagai draft untuk diperiksa guru.
                        </p>
                    </div>

                    <label className="mt-6 block text-sm font-semibold text-slate-800">
                        Tema cerita
                        <textarea
                            autoFocus
                            value={data.theme}
                            onChange={(event) => setData('theme', event.target.value)}
                            rows={4}
                            maxLength={255}
                            placeholder="Contoh: menjaga kebersihan sungai di lingkungan desa"
                            className="mt-2 block w-full rounded-xl border-slate-300 text-base focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <span className="mt-1 block text-right text-xs font-normal text-slate-400">{data.theme.length}/255</span>
                        <InputError message={errors.theme} className="mt-1" />
                    </label>

                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        <label className="block text-sm font-semibold text-slate-800">
                            Jumlah paragraf
                            <select
                                value={data.paragraph_count}
                                onChange={(event) => setData('paragraph_count', Number(event.target.value))}
                                className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                {[1, 2, 3, 4, 5].map((count) => <option key={count} value={count}>{count} paragraf</option>)}
                            </select>
                            <InputError message={errors.paragraph_count} className="mt-1" />
                        </label>
                        <label className="block text-sm font-semibold text-slate-800">
                            Jumlah soal
                            <select
                                value={data.question_count}
                                onChange={(event) => setData('question_count', Number(event.target.value))}
                                className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                {[2, 3, 4].map((count) => <option key={count} value={count}>{count} soal</option>)}
                            </select>
                            <InputError message={errors.question_count} className="mt-1" />
                        </label>
                    </div>

                    <div className="mt-6 flex flex-wrap justify-end gap-3">
                        <Link href={route('questions.index')} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
                            Kembali
                        </Link>
                        <button disabled={processing || data.theme.trim().length === 0} className="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
                            {processing ? 'Mengirim tema...' : 'Buat Cerita & Soal'}
                        </button>
                    </div>
                </form>

                <aside className="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Permintaan terbaru</h2>
                    {recentGenerations.length === 0 ? (
                        <p className="mt-4 text-sm text-slate-500">Belum ada soal cerita yang dibuat.</p>
                    ) : (
                        <div className="mt-4 space-y-3">
                            {recentGenerations.map((generation) => (
                                <Link key={generation.id} href={route('story-questions.show', generation.id)} className="block rounded-xl border border-slate-200 p-4 hover:border-indigo-300">
                                    <div className="flex items-center justify-between gap-3">
                                        <p className="line-clamp-2 text-sm font-medium text-slate-900">{generation.result_payload?.title || generation.request_payload.theme}</p>
                                        <span className={`shrink-0 rounded-full px-2 py-1 text-xs font-semibold ${generation.status === 'completed' ? 'bg-emerald-50 text-emerald-700' : generation.status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'}`}>
                                            {generation.status}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-xs text-slate-500">
                                        {generation.request_payload.paragraph_count ? `${generation.request_payload.paragraph_count} paragraf · ` : ''}
                                        {generation.result_payload?.question_count || generation.request_payload.question_count || '-'} soal
                                    </p>
                                </Link>
                            ))}
                        </div>
                    )}
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
