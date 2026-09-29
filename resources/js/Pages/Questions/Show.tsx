import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StimulusVisual, { StimulusVisualData } from '@/Components/StimulusVisual';
import PositionedImage from '@/Components/PositionedImage';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Option = { id: number; label: string; content: string; is_correct: boolean };
type MatchingPair = { left_id: string; left: string; right_id: string; right: string };
type MatchingDistractor = { id: string; content: string };
type Question = {
    id: number;
    version: number;
    revision_of_id?: number;
    superseded_by_id?: number;
    title?: string;
    stimulus?: string;
    prompt: string;
    explanation?: string;
    type: string;
    status: string;
    difficulty: number;
    grade_level: number;
    illustration_url?: string;
    explanation_image_url?: string;
    metadata?: {
        accepted_answers?: string[];
        illustration?: { alt?: string; display_width?: number; display_height?: number; display_zoom?: number; display_offset_x?: number; display_offset_y?: number };
        explanation_illustration?: { alt?: string };
        stimulus_visual?: StimulusVisualData;
        matching_pairs?: MatchingPair[];
        matching_distractors?: MatchingDistractor[];
        matrix_columns?: { id: string; label: string }[];
        matrix_rows?: { id: string; statement: string; correct_column_id: string }[];
    };
    options: Option[];
    competency: { code: string; domain: string; name: string; subject?: { code: string; name: string } };
    question_blueprint?: { code: string; name: string };
    author: { name: string };
    approver?: { name: string };
    approved_at?: string;
    variants: Question[];
    revision_of?: { id: number; title?: string; version: number; status: string };
    superseded_by?: { id: number; title?: string; version: number; status: string };
};

type VerificationSummary = {
    required: number;
    count: number;
    remaining: number;
    currentUserVerified: boolean;
    canVerify: boolean;
    verifiers: { id: number | null; name: string; verifiedAt: string }[];
};

type PackageUsage = {
    id: number;
    title: string;
    status: string;
    position?: number;
    attemptsCount: number;
    questionsCount?: number;
};

export default function Show({
    question,
    verification,
    latestGeneration,
    packageUsage = [],
    availablePackages = [],
}: {
    question: Question;
    verification: VerificationSummary;
    latestGeneration?: { status: string; error?: string };
    packageUsage?: PackageUsage[];
    availablePackages?: PackageUsage[];
}) {
    const [isVerifying, setIsVerifying] = useState(false);
    const [selectedPackageId, setSelectedPackageId] = useState<number | ''>(availablePackages[0]?.id ?? '');
    const [isUpdatingPackage, setIsUpdatingPackage] = useState(false);

    const verifyQuestion = () => {
        if (verification.currentUserVerified || isVerifying) return;
        if (!window.confirm('Saya sudah memeriksa isi soal, kunci jawaban, dan pembahasannya. Catat verifikasi saya?')) return;

        setIsVerifying(true);
        router.post(route('questions.approve', question.id), {}, {
            preserveScroll: true,
            onFinish: () => setIsVerifying(false),
        });
    };

    const addToPackage = () => {
        if (!selectedPackageId || isUpdatingPackage) return;

        setIsUpdatingPackage(true);
        router.post(route('assessments.questions.attach', selectedPackageId), { question_id: question.id }, {
            preserveScroll: true,
            onSuccess: () => setSelectedPackageId(''),
            onFinish: () => setIsUpdatingPackage(false),
        });
    };

    const removeFromPackage = (item: PackageUsage) => {
        if (!window.confirm(`Lepas soal ini dari paket “${item.title}”? Soal tetap tersimpan di Bank Soal.`)) return;

        router.delete(route('assessments.questions.remove', [item.id, question.id]), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <p className="text-sm font-medium text-emerald-600">Detail soal #{question.id}</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">{question.title || 'Soal tanpa judul'}</h1>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('questions.index')} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">← Bank soal</Link>
                        <Link href={route('questions.edit', question.id)} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Edit soal</Link>
                    </div>
                </div>
            }
        >
            <Head title={question.title || 'Detail Soal'} />
            <div className="mx-auto grid max-w-7xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:px-8">
                <div className="space-y-6">
                    <section className={`overflow-hidden rounded-2xl border shadow-sm ${verification.currentUserVerified ? 'border-emerald-200 bg-emerald-50' : 'border-blue-200 bg-white'}`}>
                        <div className="p-5 sm:p-6">
                            <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                                <div className="max-w-2xl">
                                    <p className={`text-xs font-bold uppercase tracking-wide ${verification.currentUserVerified ? 'text-emerald-700' : 'text-blue-700'}`}>Langkah verifikasi guru</p>
                                    <h2 className="mt-1 text-xl font-bold text-slate-900">
                                        {verification.currentUserVerified ? 'Verifikasi Anda sudah tercatat' : 'Periksa soal, lalu catat verifikasi Anda'}
                                    </h2>
                                    {verification.currentUserVerified ? (
                                        <p className="mt-2 text-sm leading-6 text-slate-600">
                                            Tidak perlu melakukan apa pun lagi. {verification.remaining > 0 ? `Soal ini masih menunggu ${verification.remaining} guru lain.` : 'Syarat verifikasi sudah terpenuhi.'}
                                        </p>
                                    ) : verification.canVerify ? (
                                        <ol className="mt-4 grid gap-2 text-sm text-slate-700 sm:grid-cols-3">
                                            <li className="rounded-xl border border-blue-100 bg-blue-50 px-3 py-3"><strong className="block text-blue-800">1. Baca soal</strong><span className="mt-1 block text-xs leading-5">Pastikan kalimat jelas dan tidak ambigu.</span></li>
                                            <li className="rounded-xl border border-blue-100 bg-blue-50 px-3 py-3"><strong className="block text-blue-800">2. Cek jawaban</strong><span className="mt-1 block text-xs leading-5">Jawaban benar ditandai warna hijau.</span></li>
                                            <li className="rounded-xl border border-blue-100 bg-blue-50 px-3 py-3"><strong className="block text-blue-800">3. Cek pembahasan</strong><span className="mt-1 block text-xs leading-5">Pastikan penjelasannya sesuai dengan kunci.</span></li>
                                        </ol>
                                    ) : (
                                        <p className="mt-2 text-sm leading-6 text-slate-600">Soal ini tidak tersedia untuk diverifikasi pada status saat ini.</p>
                                    )}
                                </div>

                                <div className="w-full min-w-0 rounded-xl sm:w-auto sm:min-w-64 border border-slate-200 bg-white p-4 shadow-sm">
                                    <div className="flex items-center justify-between gap-4">
                                        <div>
                                            <p className="text-xs font-medium text-slate-500">Progres</p>
                                            <p className="mt-0.5 text-lg font-bold text-slate-900">{verification.count}/{verification.required} guru</p>
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            {Array.from({ length: verification.required }).map((_, index) => (
                                                <span key={index} className={`flex h-8 w-8 items-center justify-center rounded-full border-2 text-xs font-bold ${index < verification.count ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-300'}`}>{index < verification.count ? '✓' : index + 1}</span>
                                            ))}
                                        </div>
                                    </div>
                                    {verification.canVerify && !verification.currentUserVerified && (
                                        <button
                                            type="button"
                                            disabled={isVerifying}
                                            onClick={verifyQuestion}
                                            className="mt-4 w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-500 disabled:cursor-wait disabled:opacity-60"
                                        >
                                            {isVerifying ? 'Menyimpan verifikasi...' : '✓ Saya sudah periksa — Verifikasi'}
                                        </button>
                                    )}
                                    {verification.currentUserVerified && <p className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-center text-xs font-semibold text-emerald-700">✓ Anda sudah memverifikasi soal ini</p>}
                                </div>
                            </div>
                        </div>

                        {verification.verifiers.length > 0 && (
                            <div className="border-t border-slate-200 bg-white/70 px-5 py-3 sm:px-6">
                                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-500">
                                    <span className="font-semibold text-slate-700">Sudah memverifikasi:</span>
                                    {verification.verifiers.map((verifier, index) => (
                                        <span key={`${verifier.id ?? 'inactive'}-${index}`}>✓ {verifier.name} · {formatVerificationDate(verifier.verifiedAt)}</span>
                                    ))}
                                </div>
                            </div>
                        )}
                    </section>

                    <article className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="flex flex-wrap gap-2 text-xs font-semibold">
                            <span className="rounded-full bg-emerald-50 px-3 py-1 text-emerald-700">{question.status}</span>
                            <span className="rounded-full bg-indigo-50 px-3 py-1 text-indigo-700">Versi {question.version}</span>
                            <span className="rounded-full bg-slate-100 px-3 py-1 text-slate-600">Kelas {question.grade_level}</span>
                            <span className="rounded-full bg-slate-100 px-3 py-1 text-slate-600">Kesulitan {question.difficulty}</span>
                        </div>
                        {question.revision_of && (
                            <p className="mt-4 rounded-xl border border-indigo-100 bg-indigo-50 p-3 text-sm text-indigo-800">
                                Revisi dari <Link href={route('questions.show', question.revision_of.id)} className="font-semibold underline">versi {question.revision_of.version}</Link>. Versi lama tetap digunakan oleh paket yang sudah diterbitkan.
                            </p>
                        )}
                        {question.superseded_by && (
                            <p className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                Versi ini sudah digantikan oleh <Link href={route('questions.show', question.superseded_by.id)} className="font-semibold underline">versi {question.superseded_by.version}</Link>.
                            </p>
                        )}
                        {question.illustration_url && <PositionedImage src={question.illustration_url} alt={question.metadata?.illustration?.alt || 'Ilustrasi soal'} width={question.metadata?.illustration?.display_width || 800} height={question.metadata?.illustration?.display_height || 450} zoom={question.metadata?.illustration?.display_zoom || 1} offsetX={question.metadata?.illustration?.display_offset_x || 0} offsetY={question.metadata?.illustration?.display_offset_y || 0} className="mt-6" />}
                        {question.metadata?.stimulus_visual && <StimulusVisual visual={question.metadata.stimulus_visual} className="mt-6" />}
                        {question.stimulus && <div className="mt-6 whitespace-pre-wrap rounded-xl bg-slate-50 p-5 leading-7 text-slate-700">{question.stimulus}</div>}
                        <h2 className="mt-6 text-lg font-semibold leading-7 text-slate-900">{question.prompt}</h2>
                        {question.options.length > 0 && (
                            <div className="mt-5 space-y-3">
                                {question.options.map((option) => (
                                    <div key={option.id} className={`flex gap-3 rounded-xl border p-4 ${option.is_correct ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200'}`}>
                                        {question.type === 'multiple_choice' ? <span className={`flex h-5 w-5 shrink-0 items-center justify-center rounded border text-xs ${option.is_correct ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 text-transparent'}`}>✓</span> : <span className="font-semibold">{option.label}.</span>}
                                        <span>{option.content}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                        {question.type === 'matching' && question.metadata?.matching_pairs && (
                            <div className="mt-5 overflow-hidden rounded-xl border border-slate-200">
                                <div className="grid grid-cols-[minmax(0,1fr)_40px_minmax(0,1fr)] bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-700">
                                    <span>Lajur kiri</span><span /><span>Lajur kanan (kunci)</span>
                                </div>
                                {question.metadata.matching_pairs.map((pair, index) => (
                                    <div key={pair.left_id} className="grid grid-cols-[minmax(0,1fr)_40px_minmax(0,1fr)] items-center border-t border-slate-200 px-4 py-4 text-sm">
                                        <span className="text-slate-800">{pair.left}</span>
                                        <span className="text-center font-semibold text-emerald-600">→</span>
                                        <span className="font-medium text-emerald-800">{pair.right}</span>
                                    </div>
                                ))}
                                {(question.metadata.matching_distractors?.length || 0) > 0 && (
                                    <div className="border-t border-slate-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                        Distraktor: {question.metadata.matching_distractors?.map((item) => item.content).join(', ')}
                                    </div>
                                )}
                            </div>
                        )}
                        {question.type === 'category_matrix' && question.metadata?.matrix_columns && question.metadata?.matrix_rows && (
                            <div className="mt-5 overflow-x-auto rounded-xl border border-slate-200">
                                <table className="w-full min-w-[560px] text-sm">
                                    <thead className="bg-slate-100 text-slate-700"><tr><th className="p-4 text-left">Pernyataan</th>{question.metadata.matrix_columns.map((column) => <th key={column.id} className="p-4 text-center">{column.label}</th>)}</tr></thead>
                                    <tbody>{question.metadata.matrix_rows.map((row) => <tr key={row.id} className="border-t border-slate-200"><td className="p-4 text-slate-800">{row.statement}</td>{question.metadata?.matrix_columns?.map((column) => <td key={column.id} className="p-4 text-center"><span className={`inline-flex h-7 w-7 items-center justify-center rounded-full ${row.correct_column_id === column.id ? 'bg-emerald-600 text-white' : 'border border-slate-300 text-transparent'}`}>✓</span></td>)}</tr>)}</tbody>
                                </table>
                            </div>
                        )}
                        {question.metadata?.accepted_answers && <p className="mt-5 text-sm text-emerald-700">Jawaban diterima: {question.metadata.accepted_answers.join(', ')}</p>}
                        {(question.explanation || question.explanation_image_url) && <div className="mt-6 border-t border-slate-100 pt-5"><h3 className="text-sm font-semibold text-slate-900">Pembahasan</h3>{question.explanation && <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-600">{question.explanation}</p>}{question.explanation_image_url && <img src={question.explanation_image_url} alt={question.metadata?.explanation_illustration?.alt || 'Gambar pembahasan soal'} className="mt-4 max-h-96 w-full rounded-xl border border-slate-200 bg-white object-contain" />}</div>}
                    </article>

                    <section>
                        <div className="flex items-center justify-between">
                            <h2 className="text-lg font-semibold text-slate-900">Variasi soal</h2>
                            {latestGeneration && <span className="text-sm text-slate-500">AI: {latestGeneration.status}</span>}
                        </div>
                        {latestGeneration?.error && <p className="mt-2 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{latestGeneration.error}</p>}
                        <div className="mt-3 grid gap-3">
                            {question.variants.length === 0 ? (
                                <div className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">Belum ada variasi.</div>
                            ) : question.variants.map((variant) => (
                                <Link key={variant.id} href={route('questions.show', variant.id)} className="rounded-xl border border-slate-200 bg-white p-4 hover:border-emerald-300">
                                    <p className="font-medium text-slate-900">{variant.title || variant.prompt}</p>
                                    <p className="mt-1 text-xs text-slate-500">{variant.status} · kesulitan {variant.difficulty}</p>
                                </Link>
                            ))}
                        </div>
                    </section>
                </div>

                <aside className="h-fit space-y-5 lg:sticky lg:top-6">
                    <section className="rounded-2xl border border-indigo-200 bg-white p-5 shadow-sm">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-bold uppercase tracking-wide text-indigo-600">Paket ujian</p>
                                <h2 className="mt-1 font-bold text-slate-900">
                                    {packageUsage.length > 0 ? `Masuk dalam ${packageUsage.length} paket` : 'Belum masuk paket'}
                                </h2>
                            </div>
                            <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${packageUsage.length > 0 ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-600'}`}>{packageUsage.length}</span>
                        </div>

                        {packageUsage.length > 0 ? (
                            <div className="mt-4 space-y-2">
                                {packageUsage.map((item) => (
                                    <div key={item.id} className="rounded-xl border border-slate-200 p-3">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <Link href={route('assessments.show', item.id)} className="block truncate text-sm font-bold text-slate-900 hover:text-indigo-700">{item.title}</Link>
                                                <p className="mt-1 text-xs text-slate-500">Soal ke-{item.position} · {item.status === 'published' ? 'Terbit' : 'Draft'}{item.attemptsCount > 0 ? ` · ${item.attemptsCount} peserta` : ''}</p>
                                            </div>
                                            <button type="button" onClick={() => removeFromPackage(item)} className="shrink-0 text-xs font-semibold text-rose-600 hover:underline">Lepas</button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="mt-3 text-sm leading-6 text-slate-600">
                                Soal ini belum digunakan dalam paket ujian mana pun.
                            </p>
                        )}

                        {question.status === 'published' ? (
                            availablePackages.length > 0 && (
                                <div className="mt-4 border-t border-slate-100 pt-4">
                                    <label htmlFor="target-package" className="text-xs font-semibold text-slate-700">Tambah atau pindahkan ke paket</label>
                                    <select
                                        id="target-package"
                                        value={selectedPackageId}
                                        onChange={(event) => setSelectedPackageId(event.target.value ? Number(event.target.value) : '')}
                                        className="mt-2 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="">Pilih paket tujuan</option>
                                        {availablePackages.map((item) => <option key={item.id} value={item.id}>{item.title} ({item.status})</option>)}
                                    </select>
                                    <button type="button" disabled={!selectedPackageId || isUpdatingPackage} onClick={addToPackage} className="mt-2 w-full rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                                        {isUpdatingPackage ? 'Menyimpan...' : 'Tambahkan ke paket'}
                                    </button>
                                    {packageUsage.length > 0 && <p className="mt-2 text-xs leading-5 text-slate-500">Untuk memindahkan: tambahkan ke paket tujuan, lalu klik <strong>Lepas</strong> pada paket lama.</p>}
                                </div>
                            )
                        ) : (
                            <p className="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800">Soal dapat dimasukkan ke paket setelah statusnya Terbit ({verification.count}/{verification.required} verifikasi).</p>
                        )}
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="font-semibold text-slate-900">Informasi soal</h2>
                    <dl className="mt-4 space-y-3 text-sm">
                        <div><dt className="text-slate-500">Mata Pelajaran</dt><dd className="mt-1 font-medium text-slate-900">{question.competency.subject ? `${question.competency.subject.code} · ${question.competency.subject.name}` : '-'}</dd></div>
                        <div><dt className="text-slate-500">Kompetensi</dt><dd className="mt-1 font-medium text-slate-900">{question.competency.code} · {question.competency.name}</dd></div>
                        <div><dt className="text-slate-500">Tipe Soal</dt><dd className="mt-1 font-medium text-slate-900">{question.question_blueprint ? `${question.question_blueprint.code} · ${question.question_blueprint.name}` : '-'}</dd></div>
                        <div><dt className="text-slate-500">Pembuat</dt><dd className="mt-1 text-slate-900">{question.author.name}</dd></div>
                    </dl>
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="font-semibold text-slate-900">Tindakan lain</h2>
                        <p className="mt-1 text-xs leading-5 text-slate-500">Tidak diperlukan untuk proses verifikasi biasa.</p>
                        <div className="mt-4 grid gap-2">
                            {!['matching', 'category_matrix'].includes(question.type) && <button onClick={() => router.post(route('questions.ai-variants.store', question.id))} className="rounded-lg border border-slate-200 px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50">Buat 3 variasi AI</button>}
                            <button onClick={() => router.post(route('questions.duplicate', question.id))} className="rounded-lg border border-slate-200 px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50">Duplikasi soal</button>
                            {question.status !== 'archived' && <button onClick={() => window.confirm('Arsipkan soal ini?') && router.post(route('questions.archive', question.id))} className="rounded-lg border border-rose-100 px-3 py-2 text-left text-sm font-semibold text-rose-700 hover:bg-rose-50">Arsipkan soal</button>}
                        </div>
                    </section>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}

function formatVerificationDate(value: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}
