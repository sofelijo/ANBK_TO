import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

type Student = { id: number; name: string; student_identifier?: string; grade_level: number; room_id?: number; last_message?: { content?: string; sender_type: string; created_at: string; needs_attention: boolean } };

export default function TeacherIndex({ students }: { students: Student[] }) {
    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Pengawasan</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Chat Siswa dengan AI</h1></div>}>
            <Head title="Chat Siswa" />
            <div className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
                <div className="mb-5 rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm leading-6 text-indigo-900">Guru memiliki akses baca untuk pengawasan. Siswa hanya berbicara dengan AI dan tidak dapat melihat room siswa lain.</div>
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    {students.length === 0 ? <p className="p-10 text-center text-sm text-slate-500">Belum ada siswa.</p> : <div className="divide-y divide-slate-100">{students.map((student) => (
                        <Link key={student.id} href={route('teacher-chat.show', student.id)} className="flex items-center justify-between gap-4 p-5 hover:bg-slate-50">
                            <div className="min-w-0"><div className="flex items-center gap-2"><p className="font-semibold text-slate-900">{student.name}</p>{student.last_message?.needs_attention && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Perlu perhatian</span>}</div><p className="mt-1 text-xs text-slate-500">{student.student_identifier || 'Tanpa NIS'} · Kelas {student.grade_level}</p><p className="mt-2 truncate text-sm text-slate-500">{student.last_message?.content || 'Belum ada percakapan.'}</p></div>
                            <span className="shrink-0 text-sm font-semibold text-emerald-700">Buka →</span>
                        </Link>
                    ))}</div>}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
