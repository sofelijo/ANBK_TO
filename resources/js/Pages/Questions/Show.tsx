import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StimulusVisual, { StimulusVisualData } from '@/Components/StimulusVisual';
import PositionedImage from '@/Components/PositionedImage';
import FormattedText from '@/Components/FormattedText';
import { StimulusTextStyle } from '@/Components/StimulusText';
import StimulusDocument from '@/Components/StimulusDocument';
import FreeformStimulusDocument from '@/Components/FreeformStimulusDocument';
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
    secondary_illustration_url?: string;
    additional_illustration_urls?: string[];
    explanation_image_url?: string;
    metadata?: {
        accepted_answers?: string[];
        illustration?: { alt?: string; source?: string; display_width?: number; display_height?: number; text_position?: number; document_x?: number; document_y?: number; display_zoom?: number; display_offset_x?: number; display_offset_y?: number };
        secondary_illustration?: { alt?: string; display_width?: number; document_x?: number; document_y?: number };
        additional_illustrations?: { alt?: string; display_width?: number; document_x?: number; document_y?: number }[];
        explanation_illustration?: { alt?: string };
        stimulus_visual?: StimulusVisualData;
        stimulus_text_style?: StimulusTextStyle;
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
    hasOpenComments: boolean;
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

type ReviewComment = { id: number; comment: string; reviewer: string; reviewer_id: number; created_at?: string; resolved_at?: string; resolved_by?: string };

export default function Show({
    question,
    verification,
    latestGeneration,
    packageUsage = [],
    availablePackages = [],
    studentPreview = false,
    canManageStatus = false,
    isAuthor = false,
    reviewComments = [],
    canRequestRevision = false,
}: {
    question: Question;
    verification: VerificationSummary;
    latestGeneration?: { status: string; error?: string };
    packageUsage?: PackageUsage[];
    availablePackages?: PackageUsage[];
    studentPreview?: boolean;
    canManageStatus?: boolean;
    isAuthor?: boolean;
    reviewComments?: ReviewComment[];
    canRequestRevision?: boolean;
}) {
    const [isVerifying, setIsVerifying] = useState(false);
    const [selectedPackageId, setSelectedPackageId] = useState<number | ''>(availablePackages[0]?.id ?? '');
    const [isUpdatingPackage, setIsUpdatingPackage] = useState(false);
    const [isUpdatingStatus, setIsUpdatingStatus] = useState(false);
    const [revisionComment, setRevisionComment] = useState('');
    const [isRequestingRevision, setIsRequestingRevision] = useState(false);

    if (studentPreview) return <QuestionStudentPreview question={question} />;

    const openReviewComments = reviewComments.filter((comment) => !comment.resolved_at);
    const status = openReviewComments.length > 0
        ? { label: 'Perlu perbaikan', className: 'bg-rose-100 text-rose-800' }
        : questionStatusPresentation(question.status);

    const submitForReview = () => {
        if (isUpdatingStatus) return;
        setIsUpdatingStatus(true);
        router.post(route('questions.update-status', question.id), { status: 'review' }, {
            preserveScroll: true,
            onFinish: () => setIsUpdatingStatus(false),
        });
    };

    const requestRevision = () => {
        if (revisionComment.trim().length < 5 || isRequestingRevision) return;
        setIsRequestingRevision(true);
        router.post(route('questions.request-revision', question.id), { comment: revisionComment }, {
            preserveScroll: true,
            onSuccess: () => setRevisionComment(''),
            onFinish: () => setIsRequestingRevision(false),
        });
    };

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
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="text-sm font-medium text-emerald-600">Detail soal #{question.id}</p>
                            <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${status.className}`}>Status: {status.label}</span>
                        </div>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900">{question.title || 'Soal tanpa judul'}</h1>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('questions.index')} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">← Bank soal</Link>
                        <a href={`${route('questions.show', question.id)}?student_preview=1`} target="_blank" rel="noopener noreferrer" className="rounded-lg border border-indigo-300 bg-white px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">Preview Siswa ↗</a>
                        {isAuthor && <Link href={route('questions.edit', question.id)} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Edit soal</Link>}
                    </div>
                </div>
            }
        >
            <Head title={question.title || 'Detail Soal'} />
            <div className="mx-auto grid max-w-7xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:px-8">
                <div className="space-y-6">
                    <section className={`overflow-hidden rounded-2xl border shadow-sm ${verification.hasOpenComments ? 'border-rose-200 bg-rose-50/40' : verification.currentUserVerified ? 'border-emerald-200 bg-emerald-50' : 'border-blue-200 bg-white'}`}>
                        <div className="p-5 sm:p-6">
                            <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                                <div className="max-w-2xl">
                                    <p className={`text-xs font-bold uppercase tracking-wide ${verification.currentUserVerified ? 'text-emerald-700' : 'text-blue-700'}`}>Langkah verifikasi guru</p>
                                    <h2 className="mt-1 text-xl font-bold text-slate-900">
                                        {question.status === 'draft' ? 'Soal masih berstatus Draft' : verification.hasOpenComments ? 'Soal menunggu perbaikan' : verification.currentUserVerified ? 'Verifikasi Anda sudah tercatat' : 'Periksa soal, lalu catat verifikasi Anda'}
                                    </h2>
                                    {question.status === 'draft' ? (
                                        <p className="mt-2 text-sm leading-6 text-slate-600">Gunakan tombol Ajukan dan verifikasi pada panel ini agar guru lain dapat memeriksa soal.</p>
                                    ) : verification.hasOpenComments ? (
                                        <p className="mt-2 text-sm leading-6 text-rose-700">Verifikasi dihentikan sementara sampai semua komentar perbaikan ditindaklanjuti oleh pembuat soal.</p>
                                    ) : verification.currentUserVerified ? (
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
                                    {question.status === 'draft' && canManageStatus && <button type="button" disabled={isUpdatingStatus} onClick={submitForReview} className="mt-4 w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-500 disabled:cursor-wait disabled:opacity-60">{isUpdatingStatus ? 'Mengajukan…' : isAuthor ? 'Ajukan dan verifikasi' : 'Ajukan verifikasi'}</button>}
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

                        {(reviewComments.length > 0 || canRequestRevision) && <div className="border-t border-slate-200 bg-slate-50/70 px-5 py-4 sm:px-6">
                            {reviewComments.length > 0 && <div className="space-y-2">
                                <p className="text-xs font-bold uppercase tracking-wide text-slate-600">Komentar peninjauan</p>
                                {reviewComments.map((comment) => <div key={comment.id} className={`rounded-xl border p-3 ${comment.resolved_at ? 'border-emerald-200 bg-emerald-50/60' : 'border-rose-200 bg-white'}`}>
                                    <div className="flex flex-wrap items-start justify-between gap-2"><div><p className="text-xs font-bold text-slate-700">{comment.reviewer}</p><p className="mt-1 whitespace-pre-wrap text-sm leading-6 text-slate-800">{comment.comment}</p></div><span className={`rounded-full px-2 py-1 text-[10px] font-bold ${comment.resolved_at ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}`}>{comment.resolved_at ? 'Selesai' : 'Belum ditindaklanjuti'}</span></div>
                                    {!comment.resolved_at && isAuthor && <button type="button" onClick={() => router.patch(route('questions.review-comments.resolve', [question.id, comment.id]), {}, { preserveScroll: true })} className="mt-3 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-500">Tandai sudah diperbaiki</button>}
                                </div>)}
                            </div>}
                            {canRequestRevision && <div className={reviewComments.length ? 'mt-4 border-t border-slate-200 pt-4' : ''}>
                                <label className="text-xs font-bold uppercase tracking-wide text-slate-600">Minta perbaikan<textarea value={revisionComment} onChange={(event) => setRevisionComment(event.target.value)} rows={3} maxLength={3000} placeholder="Jelaskan bagian yang perlu diperbaiki secara spesifik…" className="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm normal-case tracking-normal focus:border-rose-400 focus:ring-rose-400" /></label>
                                <button type="button" disabled={revisionComment.trim().length < 5 || isRequestingRevision} onClick={requestRevision} className="mt-2 rounded-lg border border-rose-300 bg-white px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-40">{isRequestingRevision ? 'Mengirim…' : 'Beri status Perlu perbaikan'}</button>
                            </div>}
                        </div>}
                    </section>

                    <article className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="flex flex-wrap gap-2 text-xs font-semibold">
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
                        {question.metadata?.stimulus_visual && <StimulusVisual visual={question.metadata.stimulus_visual} className="mt-6" />}
                        {(question.stimulus || question.illustration_url || question.secondary_illustration_url || question.additional_illustration_urls?.length) && (question.metadata?.illustration?.source === 'template-svg'
                            ? <StimulusDocument text={question.stimulus} style={question.metadata?.stimulus_text_style} image={question.illustration_url ? <PositionedImage src={question.illustration_url} alt={question.metadata?.illustration?.alt || 'Ilustrasi soal'} width={question.metadata?.illustration?.display_width || 800} height={question.metadata?.illustration?.display_height || 450} className="my-4" /> : undefined} className="mt-6 rounded-xl bg-slate-50 p-5" />
                            : <FreeformStimulusDocument text={question.stimulus} style={question.metadata?.stimulus_text_style} imageUrl={question.illustration_url} imageAlt={question.metadata?.illustration?.alt || 'Ilustrasi soal'} imageLayout={{ x: question.metadata?.illustration?.document_x, y: question.metadata?.illustration?.document_y, width: question.metadata?.illustration?.display_width }} secondaryImageUrl={question.secondary_illustration_url} secondaryImageAlt={question.metadata?.secondary_illustration?.alt} secondaryImageLayout={{ x: question.metadata?.secondary_illustration?.document_x, y: question.metadata?.secondary_illustration?.document_y, width: question.metadata?.secondary_illustration?.display_width }} additionalImages={(question.additional_illustration_urls || []).map((url, index) => ({ url, alt: question.metadata?.additional_illustrations?.[index]?.alt, layout: { x: question.metadata?.additional_illustrations?.[index]?.document_x, y: question.metadata?.additional_illustrations?.[index]?.document_y, width: question.metadata?.additional_illustrations?.[index]?.display_width } }))} className="mt-6 bg-slate-50" />)}
                        <h2 className="mt-6 text-lg font-semibold leading-7 text-slate-900"><FormattedText text={question.prompt} /></h2>
                        {question.options.length > 0 && (
                            <div className="mt-5 space-y-3">
                                {question.options.map((option) => (
                                    <div key={option.id} className={`flex gap-3 rounded-xl border p-4 ${option.is_correct ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200'}`}>
                                        {question.type === 'multiple_choice' ? <span className={`flex h-5 w-5 shrink-0 items-center justify-center rounded border text-xs ${option.is_correct ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 text-transparent'}`}>✓</span> : <span className="font-semibold">{option.label}.</span>}
                                        <FormattedText text={option.content} />
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
                        {(question.explanation || question.explanation_image_url) && <div className="mt-6 border-t border-slate-100 pt-5"><h3 className="text-sm font-semibold text-slate-900">Pembahasan</h3>{question.explanation && <FormattedText text={question.explanation} className="mt-2 block whitespace-pre-wrap text-sm leading-6 text-slate-600" />}{question.explanation_image_url && <img src={question.explanation_image_url} alt={question.metadata?.explanation_illustration?.alt || 'Gambar pembahasan soal'} className="mt-4 max-h-96 w-full rounded-xl border border-slate-200 bg-white object-contain" />}</div>}
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
                            {isAuthor && question.status !== 'archived' && <button onClick={() => window.confirm('Arsipkan soal ini?') && router.post(route('questions.archive', question.id))} className="rounded-lg border border-rose-100 px-3 py-2 text-left text-sm font-semibold text-rose-700 hover:bg-rose-50">Arsipkan soal</button>}
                        </div>
                    </section>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}

function QuestionStudentPreview({ question }: { question: Question }) {
    const [selectedOptions, setSelectedOptions] = useState<number[]>([]);
    const [matrixAnswers, setMatrixAnswers] = useState<Record<string, string>>({});
    const hasStimulus = Boolean(question.illustration_url || question.secondary_illustration_url || question.additional_illustration_urls?.length || question.metadata?.stimulus_visual || question.stimulus);
    const matchingChoices = [
        ...(question.metadata?.matching_pairs || []).map((pair) => ({ id: pair.right_id, content: pair.right })),
        ...(question.metadata?.matching_distractors || []).map((item) => ({ id: item.id, content: item.content })),
    ];

    const chooseOption = (optionId: number) => setSelectedOptions((current) => question.type === 'single_choice'
        ? [optionId]
        : current.includes(optionId) ? current.filter((id) => id !== optionId) : [...current, optionId]);

    return (
        <div className="min-h-screen bg-slate-100">
            <Head title="Preview POV Siswa" />
            <header className="bg-slate-900 text-white shadow-sm">
                <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <div><p className="text-xs font-bold uppercase tracking-widest text-emerald-300">Preview POV siswa</p><h1 className="mt-1 font-bold">Simulasi Adaptif</h1></div>
                    <button type="button" onClick={() => window.close()} className="rounded-lg border border-white/20 px-3 py-2 text-sm font-semibold text-white hover:bg-white/10">Tutup tab</button>
                </div>
            </header>
            <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                    <span>Ini tampilan siswa. Jawaban pada preview tidak disimpan.</span><span className="font-bold">Soal 1 dari 1</span>
                </div>
                <main className={`grid overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm ${hasStimulus ? 'lg:grid-cols-2' : ''}`}>
                    {hasStimulus && <aside className="border-b border-slate-200 bg-slate-50/70 p-5 lg:border-b-0 lg:border-r sm:p-6">
                        <p className="text-xs font-bold uppercase tracking-wider text-indigo-600">Stimulus</p>
                        {question.metadata?.stimulus_visual && <StimulusVisual visual={question.metadata.stimulus_visual} className="mt-3" />}
                        {(question.stimulus || question.illustration_url || question.secondary_illustration_url || question.additional_illustration_urls?.length) && (question.metadata?.illustration?.source === 'template-svg'
                            ? <StimulusDocument text={question.stimulus} style={question.metadata?.stimulus_text_style} image={question.illustration_url ? <PositionedImage src={question.illustration_url} alt={question.metadata?.illustration?.alt || 'Ilustrasi soal'} width={question.metadata?.illustration?.display_width || 800} height={question.metadata?.illustration?.display_height || 450} className="my-3" /> : undefined} className="mt-4" />
                            : <FreeformStimulusDocument text={question.stimulus} style={question.metadata?.stimulus_text_style} imageUrl={question.illustration_url} imageAlt={question.metadata?.illustration?.alt || 'Ilustrasi soal'} imageLayout={{ x: question.metadata?.illustration?.document_x, y: question.metadata?.illustration?.document_y, width: question.metadata?.illustration?.display_width }} secondaryImageUrl={question.secondary_illustration_url} secondaryImageAlt={question.metadata?.secondary_illustration?.alt} secondaryImageLayout={{ x: question.metadata?.secondary_illustration?.document_x, y: question.metadata?.secondary_illustration?.document_y, width: question.metadata?.secondary_illustration?.display_width }} additionalImages={(question.additional_illustration_urls || []).map((url, index) => ({ url, alt: question.metadata?.additional_illustrations?.[index]?.alt, layout: { x: question.metadata?.additional_illustrations?.[index]?.document_x, y: question.metadata?.additional_illustrations?.[index]?.document_y, width: question.metadata?.additional_illustrations?.[index]?.display_width } }))} className="mt-4" />)}
                    </aside>}
                    <section className="min-w-0 p-5 sm:p-7">
                        <p className="text-xs font-bold uppercase tracking-wider text-emerald-600">Soal 1</p>
                        <h2 className="mt-2 text-lg font-semibold leading-8 text-slate-900"><FormattedText text={question.prompt} /></h2>

                        {['single_choice', 'multiple_choice'].includes(question.type) && <div className="mt-6 space-y-3">
                            {question.options.map((option) => {
                                const selected = selectedOptions.includes(option.id);
                                return <button key={option.id} type="button" onClick={() => chooseOption(option.id)} className={`flex w-full items-start gap-3 rounded-xl border p-4 text-left transition ${selected ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' : 'border-slate-200 hover:border-indigo-300 hover:bg-indigo-50/40'}`}>
                                    <span className={`mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center border text-xs font-bold ${question.type === 'single_choice' ? 'rounded-full' : 'rounded-md'} ${selected ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 text-slate-500'}`}>{selected ? '✓' : option.label}</span>
                                    <FormattedText text={option.content} className="leading-6 text-slate-800" />
                                </button>;
                            })}
                        </div>}

                        {question.type === 'short_answer' && <label className="mt-6 block text-sm font-semibold text-slate-700">Jawaban Anda<input type="text" className="mt-2 block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500" placeholder="Ketik jawaban" /></label>}

                        {question.type === 'matching' && <div className="mt-6 space-y-3">
                            <p className="text-sm text-slate-600">Pilih pasangan yang sesuai untuk setiap pernyataan.</p>
                            {(question.metadata?.matching_pairs || []).map((pair, index) => <label key={pair.left_id} className="grid gap-2 rounded-xl border border-slate-200 p-3 text-sm sm:grid-cols-2 sm:items-center"><span><strong className="mr-2 text-slate-500">{index + 1}.</strong>{pair.left}</span><select defaultValue="" className="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"><option value="" disabled>Pilih pasangan</option>{matchingChoices.map((choice) => <option key={choice.id} value={choice.id}>{choice.content}</option>)}</select></label>)}
                        </div>}

                        {question.type === 'category_matrix' && <div className="mt-6 space-y-3">
                            <p className="text-sm text-slate-600">Pilih satu jawaban untuk setiap pernyataan.</p>
                            {(question.metadata?.matrix_rows || []).map((row, index) => <div key={row.id} className="rounded-xl border border-slate-200 p-3"><p className="text-sm leading-6 text-slate-800"><strong className="mr-2 text-slate-500">{index + 1}.</strong>{row.statement}</p><div className="mt-3 flex flex-wrap gap-2">{(question.metadata?.matrix_columns || []).map((column) => { const selected = matrixAnswers[row.id] === column.id; return <button key={column.id} type="button" onClick={() => setMatrixAnswers((current) => ({ ...current, [row.id]: column.id }))} className={`rounded-lg border px-3 py-2 text-xs font-semibold ${selected ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 text-slate-700 hover:bg-indigo-50'}`}>{column.label}</button>; })}</div></div>)}
                        </div>}
                    </section>
                </main>
            </div>
        </div>
    );
}

function questionStatusPresentation(status: string): { label: string; className: string } {
    return {
        draft: { label: 'Draft', className: 'bg-amber-100 text-amber-800' },
        review: { label: 'Menunggu verifikasi', className: 'bg-blue-100 text-blue-800' },
        published: { label: 'Terbit', className: 'bg-emerald-100 text-emerald-800' },
        archived: { label: 'Diarsipkan', className: 'bg-slate-200 text-slate-700' },
    }[status] || { label: status, className: 'bg-slate-100 text-slate-700' };
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
