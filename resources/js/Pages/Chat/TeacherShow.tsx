import ChatThread, { ChatMessage } from '@/Components/ChatThread';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function TeacherShow({ student, room, messages }: { student: { id: number; name: string; student_identifier?: string; grade_level: number }; room: { id: number }; messages: ChatMessage[] }) {
    return (
        <AuthenticatedLayout header={<div><p className="text-sm font-medium text-emerald-600">Room privat siswa–AI</p><h1 className="mt-1 text-2xl font-bold text-slate-900">{student.name}</h1><p className="mt-1 text-sm text-slate-500">{student.student_identifier || 'Tanpa NIS'} · Kelas {student.grade_level}</p></div>}>
            <Head title={`Chat ${student.name}`} />
            <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6">
                <Link href={route('teacher-chat.index')} className="mb-4 inline-flex text-sm font-semibold text-emerald-700">← Kembali ke daftar siswa</Link>
                <div className="rounded-2xl border border-slate-200 bg-white shadow-sm"><div className="border-b border-slate-100 bg-slate-50 px-5 py-3 text-xs text-slate-500">Mode pengawasan · Guru tidak dapat mengirim pesan ke room ini.</div><div className="h-[68vh] overflow-y-auto p-5"><ChatThread roomId={room.id} initialMessages={messages} teacherView /></div></div>
            </div>
        </AuthenticatedLayout>
    );
}
