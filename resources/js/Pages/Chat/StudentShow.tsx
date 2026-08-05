import ChatThread, { ChatMessage } from '@/Components/ChatThread';
import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function StudentShow({ room, messages, chatEnabled, dailyLimit, dailyUsed }: { room: { id: number }; messages: ChatMessage[]; chatEnabled: boolean; dailyLimit: number; dailyUsed: number }) {
    const { data, setData, post, processing, errors, reset } = useForm({ content: '' });
    const quotaAvailable = dailyUsed < dailyLimit;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('student-chat.messages.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Pendamping Belajar</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Teman Belajar AI</h1></div>}>
            <Head title="Teman Belajar AI" />
            <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6">
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 bg-slate-50 px-5 py-4">
                        <p className="text-sm text-slate-600">Gunakan room privat ini untuk memahami materi, menyusun rencana belajar, dan melakukan refleksi setelah try out.</p>
                        <p className="mt-1 text-xs text-slate-400">Percakapan dapat dibaca guru sekolah untuk menjaga keamanan. Jangan mengirim data pribadi.</p>
                    </div>
                    <div className="h-[58vh] overflow-y-auto p-5"><ChatThread roomId={room.id} initialMessages={messages} /></div>
                    <form onSubmit={submit} className="border-t border-slate-100 p-4">
                        {!chatEnabled && <p className="mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Chat dinonaktifkan selama kamu masih mengerjakan try out.</p>}
                        {!quotaAvailable && <p className="mb-3 rounded-lg bg-slate-100 p-3 text-sm text-slate-700">Kuota chat hari ini sudah habis. Kamu dapat melanjutkan besok.</p>}
                        <div className="flex gap-3">
                            <textarea value={data.content} onChange={(event) => setData('content', event.target.value)} disabled={!chatEnabled || !quotaAvailable || processing} rows={2} maxLength={2000} placeholder="Contoh: Aku masih bingung mencari informasi penting dalam cerita…" className="min-h-20 flex-1 resize-none rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-slate-100" />
                            <button disabled={!chatEnabled || !quotaAvailable || processing || !data.content.trim()} className="self-end rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white disabled:opacity-40">Kirim</button>
                        </div>
                        <div className="mt-2 flex justify-between gap-3"><InputError message={errors.content} /><span className="ml-auto text-xs text-slate-400">{dailyUsed}/{dailyLimit} pesan hari ini</span></div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
