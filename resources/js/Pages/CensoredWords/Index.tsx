import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type WordItem = {
    id: number;
    school_id: number | null;
    word: string;
    is_global: boolean;
    created_at: string;
};

type ResponseItem = {
    id: number;
    school_id: number | null;
    response_text: string;
    is_active: boolean;
    is_global: boolean;
    created_at: string;
};

export default function Index({
    words,
    responses,
}: {
    words: WordItem[];
    responses: ResponseItem[];
}) {
    const [activeTab, setActiveTab] = useState<'words' | 'responses'>('words');
    const [wordSearch, setWordSearch] = useState('');

    // Modal state for batch words
    const [addWordModalOpen, setAddWordModalOpen] = useState(false);
    const { data: wordData, setData: setWordData, post: postWord, processing: wordProcessing, errors: wordErrors, reset: resetWord } = useForm({
        words_input: '',
    });

    // Modal state for response templates
    const [responseModalOpen, setResponseModalOpen] = useState(false);
    const [editingResponse, setEditingResponse] = useState<ResponseItem | null>(null);
    const { data: respData, setData: setRespData, post: postResp, put: putResp, processing: respProcessing, errors: respErrors, reset: resetResp } = useForm({
        response_text: '',
        is_active: true,
    });

    // Live test box state
    const [testMessage, setTestMessage] = useState('');
    const [testLoading, setTestLoading] = useState(false);
    const [testResult, setTestResult] = useState<{ triggered: boolean; response: string } | null>(null);

    const submitWords = (e: FormEvent) => {
        e.preventDefault();
        postWord(route('censored-words.store'), {
            onSuccess: () => {
                setAddWordModalOpen(false);
                resetWord();
            },
        });
    };

    const deleteWord = (item: WordItem) => {
        if (confirm(`Hapus kata "${item.word}" dari daftar sensor?`)) {
            router.delete(route('censored-words.destroy', item.id));
        }
    };

    const openAddResponseModal = () => {
        setEditingResponse(null);
        resetResp();
        setResponseModalOpen(true);
    };

    const openEditResponseModal = (item: ResponseItem) => {
        setEditingResponse(item);
        setRespData({
            response_text: item.response_text,
            is_active: item.is_active,
        });
        setResponseModalOpen(true);
    };

    const submitResponse = (e: FormEvent) => {
        e.preventDefault();
        if (editingResponse) {
            putResp(route('censored-words.responses.update', editingResponse.id), {
                onSuccess: () => {
                    setResponseModalOpen(false);
                    resetResp();
                    setEditingResponse(null);
                },
            });
        } else {
            postResp(route('censored-words.responses.store'), {
                onSuccess: () => {
                    setResponseModalOpen(false);
                    resetResp();
                },
            });
        }
    };

    const toggleResponseActive = (item: ResponseItem) => {
        router.put(route('censored-words.responses.update', item.id), {
            response_text: item.response_text,
            is_active: !item.is_active,
        });
    };

    const deleteResponse = (item: ResponseItem) => {
        if (confirm('Hapus respon edukasi ini dari pool ASKA?')) {
            router.delete(route('censored-words.responses.destroy', item.id));
        }
    };

    const handleTest = async (e: FormEvent) => {
        e.preventDefault();
        if (!testMessage.trim()) return;

        setTestLoading(true);
        setTestResult(null);

        try {
            const res = await fetch(route('censored-words.test'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                },
                body: JSON.stringify({ message: testMessage }),
            });

            if (res.ok) {
                const json = await res.json();
                setTestResult(json);
            }
        } catch {
            setTestResult({
                triggered: false,
                response: 'Gagal menguji pesan.',
            });
        } finally {
            setTestLoading(false);
        }
    };

    const filteredWords = words.filter((w) =>
        w.word.toLowerCase().includes(wordSearch.toLowerCase()),
    );

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p className="text-sm font-medium text-emerald-600">Keamanan Chat AI</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">Kata Sensor & Respon Edukasi ASKA</h1>
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={() => setAddWordModalOpen(true)}
                            className="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500"
                        >
                            + Tambah Kata Terlarang
                        </button>
                        <button
                            onClick={openAddResponseModal}
                            className="rounded-xl border border-indigo-300 bg-indigo-50 px-4 py-2.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-100"
                        >
                            + Tambah Respon Edukasi ASKA
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="Kata Sensor & Respon ASKA" />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
                {/* ── Interactive Tester Box ── */}
                <div className="rounded-2xl border border-indigo-100 bg-gradient-to-r from-indigo-50/80 via-sky-50/50 to-white p-6 shadow-sm">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white font-bold text-sm">
                            ASKA
                        </div>
                        <div>
                            <h2 className="font-semibold text-slate-900">Simulasi & Uji Coba Respon Acak ASKA</h2>
                            <p className="text-xs text-slate-500">
                                Uji kalimat siswa (misal: "apa itu jancok?"). Jika memicu kata terlarang, ASKA akan memilih salah satu respon edukasi secara acak.
                            </p>
                        </div>
                    </div>

                    <form onSubmit={handleTest} className="mt-4 flex flex-col sm:flex-row gap-3">
                        <input
                            type="text"
                            value={testMessage}
                            onChange={(e) => setTestMessage(e.target.value)}
                            placeholder="Ketik kalimat simulasi siswa (misal: apa itu jancok?)..."
                            className="flex-1 rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <button
                            type="submit"
                            disabled={testLoading || !testMessage.trim()}
                            className="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {testLoading ? 'Menguji…' : 'Uji Respon Acak'}
                        </button>
                    </form>

                    {testResult && (
                        <div
                            className={`mt-4 rounded-xl border p-4 text-sm ${
                                testResult.triggered
                                    ? 'border-amber-200 bg-amber-50 text-amber-950'
                                    : 'border-emerald-200 bg-emerald-50 text-emerald-950'
                            }`}
                        >
                            <div className="flex items-center justify-between gap-2 font-bold mb-1">
                                <span>{testResult.triggered ? '⚠️ Sensor Dipicu (Respon ASKA Acak Dipilih)' : '✅ Pesan Aman'}</span>
                                {testResult.triggered && (
                                    <button
                                        type="button"
                                        onClick={handleTest}
                                        className="text-xs font-normal text-indigo-700 underline hover:text-indigo-900"
                                    >
                                        🔄 Coba Uji Lagi (Acak Ulang)
                                    </button>
                                )}
                            </div>
                            <p className="whitespace-pre-wrap leading-relaxed">{testResult.response}</p>
                        </div>
                    )}
                </div>

                {/* ── Tabs Navigation ── */}
                <div className="flex border-b border-slate-200">
                    <button
                        onClick={() => setActiveTab('words')}
                        className={`px-5 py-3 text-sm font-semibold border-b-2 transition-colors ${
                            activeTab === 'words'
                                ? 'border-emerald-600 text-emerald-700'
                                : 'border-transparent text-slate-500 hover:text-slate-700'
                        }`}
                    >
                        Daftar Kata Terlarang ({words.length})
                    </button>
                    <button
                        onClick={() => setActiveTab('responses')}
                        className={`px-5 py-3 text-sm font-semibold border-b-2 transition-colors ${
                            activeTab === 'responses'
                                ? 'border-indigo-600 text-indigo-700'
                                : 'border-transparent text-slate-500 hover:text-slate-700'
                        }`}
                    >
                        Pool Respon Edukasi ASKA (Acak) ({responses.length})
                    </button>
                </div>

                {/* ── TAB 1: Daftar Kata Terlarang ── */}
                {activeTab === 'words' && (
                    <div className="space-y-4">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <input
                                type="text"
                                value={wordSearch}
                                onChange={(e) => setWordSearch(e.target.value)}
                                placeholder="Cari kata terlarang…"
                                className="max-w-md rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                            />
                            <button
                                onClick={() => setAddWordModalOpen(true)}
                                className="text-xs font-bold text-emerald-600 hover:underline"
                            >
                                + Tambah Kata Terlarang (Bisa Masukkan Banyak Sekaligus)
                            </button>
                        </div>

                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 className="text-sm font-semibold text-slate-700 mb-4">
                                Kata/Frasa yang Di-Sensor saat Siswa Bertanya
                            </h3>
                            {filteredWords.length === 0 ? (
                                <p className="text-sm text-slate-400">Belum ada kata terlarang.</p>
                            ) : (
                                <div className="flex flex-wrap gap-2">
                                    {filteredWords.map((item) => (
                                        <div
                                            key={item.id}
                                            className="group flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-800 hover:border-rose-300 hover:bg-rose-50/50 transition-colors"
                                        >
                                            <span>{item.word}</span>
                                            <button
                                                onClick={() => deleteWord(item)}
                                                className="ml-1 font-bold text-slate-400 hover:text-rose-600"
                                                title="Hapus kata"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* ── TAB 2: Pool Respon Edukasi ASKA (Acak) ── */}
                {activeTab === 'responses' && (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <p className="text-sm text-slate-600">
                                Setiap kali pesan siswa memicu kata terlarang, ASKA akan memilih secara <strong>ACAK</strong> salah satu respon aktif di bawah ini agar pesan edukasi tidak monoton.
                            </p>
                            <button
                                onClick={openAddResponseModal}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 shrink-0"
                            >
                                + Tambah Variasi Respon
                            </button>
                        </div>

                        <div className="grid gap-3 md:grid-cols-2">
                            {responses.map((item) => (
                                <div
                                    key={item.id}
                                    className={`flex flex-col justify-between rounded-2xl border p-5 transition-colors ${
                                        item.is_active
                                            ? 'border-indigo-100 bg-white shadow-sm'
                                            : 'border-slate-200 bg-slate-50 opacity-60'
                                    }`}
                                >
                                    <div>
                                        <div className="flex items-center justify-between gap-2 mb-2">
                                            <span className="rounded-md bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700">
                                                Respon ASKA
                                            </span>
                                            <button
                                                onClick={() => toggleResponseActive(item)}
                                                className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${
                                                    item.is_active
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : 'bg-slate-200 text-slate-600'
                                                }`}
                                            >
                                                {item.is_active ? 'Aktif' : 'Non-aktif'}
                                            </button>
                                        </div>
                                        <p className="text-sm leading-relaxed text-slate-800">
                                            "{item.response_text}"
                                        </p>
                                    </div>

                                    <div className="mt-4 flex justify-end gap-2 border-t border-slate-100 pt-3">
                                        <button
                                            onClick={() => openEditResponseModal(item)}
                                            className="text-xs font-semibold text-slate-600 hover:text-slate-900"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            onClick={() => deleteResponse(item)}
                                            className="text-xs font-semibold text-rose-600 hover:text-rose-800"
                                        >
                                            Hapus
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* ── Modal Batch Words Input ── */}
            <Modal show={addWordModalOpen} onClose={() => setAddWordModalOpen(false)} maxWidth="md">
                <form onSubmit={submitWords} className="p-6">
                    <h2 className="text-lg font-bold text-slate-900">Tambah Kata Terlarang</h2>
                    <p className="mt-1 text-xs text-slate-500">
                        Ketik kata-kata terlarang. Anda bisa memasukkan beberapa kata sekaligus yang dipisahkan dengan koma atau baris baru.
                    </p>

                    <div className="mt-4">
                        <textarea
                            value={wordData.words_input}
                            onChange={(e) => setWordData('words_input', e.target.value)}
                            rows={4}
                            placeholder="Contoh: jancok, anjing, babi, kontol"
                            required
                            className="w-full rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                        />
                        <InputError message={wordErrors.words_input} className="mt-1" />
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <button
                            type="button"
                            onClick={() => setAddWordModalOpen(false)}
                            className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            disabled={wordProcessing}
                            className="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-bold text-white hover:bg-emerald-500 disabled:opacity-50"
                        >
                            {wordProcessing ? 'Menyimpan…' : 'Simpan Kata Terlarang'}
                        </button>
                    </div>
                </form>
            </Modal>

            {/* ── Modal Response Template Input / Edit ── */}
            <Modal show={responseModalOpen} onClose={() => setResponseModalOpen(false)} maxWidth="md">
                <form onSubmit={submitResponse} className="p-6">
                    <h2 className="text-lg font-bold text-slate-900">
                        {editingResponse ? 'Edit Respon Edukasi ASKA' : 'Tambah Respon Edukasi ASKA Baru'}
                    </h2>
                    <p className="mt-1 text-xs text-slate-500">
                        Teks respon edukatif ramah yang akan dipilih secara acak jika siswa memicu kata terlarang.
                    </p>

                    <div className="mt-4 space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-slate-700">Teks Respon ASKA</label>
                            <textarea
                                value={respData.response_text}
                                onChange={(e) => setRespData('response_text', e.target.value)}
                                rows={3}
                                placeholder="Contoh: Menurut ASKA, itu kata-kata kurang sopan. Yuk gunakan kata yang ramah ya!"
                                required
                                className="mt-1 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <InputError message={respErrors.response_text} className="mt-1" />
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <button
                            type="button"
                            onClick={() => setResponseModalOpen(false)}
                            className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            disabled={respProcessing}
                            className="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-bold text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {respProcessing ? 'Menyimpan…' : editingResponse ? 'Simpan Perubahan' : 'Tambah ke Pool Respon'}
                        </button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
