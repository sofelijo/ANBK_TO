import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

type NotificationItem = {
    id: string;
    title: string;
    message: string;
    kind: string;
    read_at?: string;
    created_at?: string;
};

type Props = {
    notifications: {
        data: NotificationItem[];
        links: { url?: string; label: string; active: boolean }[];
    };
};

export default function Index({ notifications }: Props) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-sm font-medium text-indigo-600">Aktivitas</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">Notifikasi</h1>
                    </div>
                    <Link href={route('notifications.read-all')} method="post" as="button" className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Tandai semua dibaca
                    </Link>
                </div>
            }
        >
            <Head title="Notifikasi" />
            <main className="mx-auto max-w-4xl px-4 py-8 sm:px-6">
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    {notifications.data.length === 0 ? (
                        <div className="px-6 py-16 text-center">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-6 w-6"><path strokeLinecap="round" strokeLinejoin="round" d="M15 17H9m9-6a6 6 0 0 0-12 0c0 3-1.5 4-2 5h16c-.5-1-2-2-2-5Z" /></svg>
                            </div>
                            <p className="mt-3 text-sm font-semibold text-slate-700">Belum ada notifikasi</p>
                            <p className="mt-1 text-xs text-slate-500">Aktivitas penting akun Anda akan muncul di sini.</p>
                        </div>
                    ) : notifications.data.map((item) => (
                        <Link key={item.id} href={route('notifications.open', item.id)} className={`flex gap-3 border-b border-slate-100 px-5 py-4 last:border-b-0 hover:bg-slate-50 ${item.read_at ? '' : 'bg-indigo-50/50'}`}>
                            <span className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${item.read_at ? 'bg-slate-200' : item.kind === 'warning' ? 'bg-amber-500' : item.kind === 'success' ? 'bg-emerald-500' : 'bg-indigo-500'}`} />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <p className="font-bold text-slate-900">{item.title}</p>
                                    {item.created_at && <time className="shrink-0 text-xs text-slate-400">{new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(item.created_at))}</time>}
                                </div>
                                <p className="mt-1 text-sm leading-6 text-slate-600">{item.message}</p>
                            </div>
                        </Link>
                    ))}
                </div>

                <div className="mt-5 flex flex-wrap gap-2">
                    {notifications.links.map((link, index) => (
                        <button key={index} disabled={!link.url} onClick={() => link.url && router.get(link.url)} className={`rounded-lg border px-3 py-2 text-sm ${link.active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-600'} disabled:opacity-40`} dangerouslySetInnerHTML={{ __html: link.label }} />
                    ))}
                </div>
            </main>
        </AuthenticatedLayout>
    );
}
