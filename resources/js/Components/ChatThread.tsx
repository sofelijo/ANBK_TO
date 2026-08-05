import { useEffect, useRef, useState } from 'react';

export type ChatMessage = {
    id: number;
    sender_type: 'student' | 'assistant';
    type: 'chat' | 'attempt_summary' | 'safety';
    status: 'pending' | 'completed' | 'failed';
    content?: string;
    metadata?: { assessment_title?: string; score?: number; max_score?: number; needs_teacher_attention?: boolean };
    created_at: string;
};

export default function ChatThread({ roomId, initialMessages, teacherView = false }: { roomId: number; initialMessages: ChatMessage[]; teacherView?: boolean }) {
    const [messages, setMessages] = useState(initialMessages);
    const bottomRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        setMessages(initialMessages);
    }, [initialMessages]);
    useEffect(() => {
        const poll = window.setInterval(async () => {
            try {
                const response = await fetch(
                    route('chat.messages.index', roomId),
                    { headers: { Accept: 'application/json' } },
                );
                if (response.ok) {
                    const payload = await response.json();
                    setMessages(payload.messages);
                }
            } catch {}
        }, 4000);

        return () => window.clearInterval(poll);
    }, [roomId]);
    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    return (
        <div className="space-y-4">
            {messages.length === 0 && <div className="py-16 text-center text-sm text-slate-500">Belum ada percakapan. Mulai dengan menceritakan hal yang ingin dipelajari.</div>}
            {messages.map((message) => {
                const student = message.sender_type === 'student';
                return (
                    <div key={message.id} className={`flex ${student ? 'justify-end' : 'justify-start'}`}>
                        <div className={`max-w-[88%] rounded-2xl px-4 py-3 sm:max-w-[75%] ${student ? 'rounded-br-md bg-emerald-600 text-white' : message.type === 'attempt_summary' ? 'border border-indigo-200 bg-indigo-50 text-indigo-950' : message.type === 'safety' ? 'border border-amber-300 bg-amber-50 text-amber-950' : 'rounded-bl-md bg-slate-100 text-slate-800'}`}>
                            <div className="mb-1 flex flex-wrap items-center gap-2 text-xs font-semibold opacity-75">
                                <span>{student ? (teacherView ? 'Siswa' : 'Kamu') : 'Teman Belajar AI'}</span>
                                {message.type === 'attempt_summary' && <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-indigo-700">Ringkasan tes</span>}
                                {message.metadata?.needs_teacher_attention && teacherView && <span className="rounded-full bg-amber-200 px-2 py-0.5 text-amber-900">Perlu perhatian</span>}
                            </div>
                            {message.status === 'pending' ? <p className="animate-pulse text-sm">AI sedang menyiapkan jawaban…</p> : <p className="whitespace-pre-wrap text-sm leading-6">{message.content}</p>}
                            {message.type === 'attempt_summary' && message.metadata?.assessment_title && <p className="mt-2 border-t border-indigo-200 pt-2 text-xs text-indigo-700">{message.metadata.assessment_title} · Skor {message.metadata.score}/{message.metadata.max_score}</p>}
                        </div>
                    </div>
                );
            })}
            <div ref={bottomRef} />
        </div>
    );
}
