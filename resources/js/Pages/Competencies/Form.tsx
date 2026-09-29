import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type ParentOption = {
    id: number;
    subject_id: number;
    code: string;
    name: string;
    grade_level: number;
};

type Competency = {
    id: number;
    subject_id: number;
    code: string;
    domain: string;
    name: string;
    description?: string;
    grade_level: number;
    parent_id?: number;
    question_blueprint_ids?: number[];
};

type QuestionBlueprint = { id: number; subject_id: number; code: string; name: string };

const normalizeGradeLevel = (gradeLevel?: number): number => {
    const legacyGradeLevels: Record<number, number> = {
        5: 6,
        8: 9,
        11: 12,
    };

    return legacyGradeLevels[gradeLevel ?? 6] ?? gradeLevel ?? 6;
};

export default function Form({
    competency,
    defaultParentId,
    defaultSubjectId,
    parents,
    subjects,
    questionBlueprints,
}: {
    competency?: Competency;
    defaultParentId?: number;
    defaultSubjectId?: number;
    parents: ParentOption[];
    subjects: { id: number; code: string; name: string }[];
    questionBlueprints: QuestionBlueprint[];
}) {
    const editing = Boolean(competency);
    const selectedParentId = competency?.parent_id || defaultParentId;
    const initialParent = parents.find((parent) => parent.id === selectedParentId);
    const { data, setData, post, put, processing, errors } = useForm({
        subject_id: competency?.subject_id ? String(competency.subject_id) : initialParent ? String(initialParent.subject_id) : defaultSubjectId ? String(defaultSubjectId) : '',
        code: competency?.code || '',
        domain: competency?.domain || '',
        name: competency?.name || '',
        description: competency?.description || '',
        grade_level: normalizeGradeLevel(competency?.grade_level ?? initialParent?.grade_level),
        parent_id: selectedParentId ? String(selectedParentId) : '',
        question_blueprint_ids: competency?.question_blueprint_ids || [],
    });
    const selectedSubject = subjects.find((subject) => subject.id === Number(data.subject_id));
    const usesQuestionBlueprints = selectedSubject?.code === 'BIND' && data.parent_id === '';
    const availableQuestionBlueprints = questionBlueprints.filter((item) => item.subject_id === Number(data.subject_id));
    const availableParents = parents.filter(
        (parent) => normalizeGradeLevel(parent.grade_level) === data.grade_level && parent.subject_id === Number(data.subject_id),
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (editing && competency) {
            put(route('competencies.update', competency.id));
        } else {
            post(route('competencies.store'));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-sm font-medium text-emerald-600">
                        Klasifikasi Bank Soal
                    </p>
                    <h1 className="mt-1 text-2xl font-bold text-slate-900">
                        {editing ? `Edit ${competency?.parent_id ? 'Subkompetensi' : 'Kompetensi'}` : `Tambah ${data.parent_id ? 'Subkompetensi' : 'Kompetensi'}`}
                    </h1>
                </div>
            }
        >
            <Head title={editing ? 'Edit Kompetensi' : 'Tambah Kompetensi'} />

            <div className="mx-auto max-w-3xl px-4 py-8 sm:px-6">
                <form
                    onSubmit={submit}
                    className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"
                >
                    <div>
                        <label htmlFor="subject_id" className="block text-sm font-medium text-slate-700">Mata pelajaran</label>
                        <select id="subject_id" value={data.subject_id} onChange={(event) => setData((current) => ({ ...current, subject_id: event.target.value, parent_id: '', question_blueprint_ids: [] }))} className="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">Pilih mata pelajaran</option>
                            {subjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.code} · {subject.name}</option>)}
                        </select>
                        <InputError message={errors.subject_id} className="mt-2" />
                    </div>

                    {usesQuestionBlueprints && <div className="mt-5 rounded-xl border border-indigo-200 bg-indigo-50 p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-bold text-indigo-950">Tipe soal default</h2><p className="mt-1 text-sm text-indigo-800">Boleh memilih beberapa tipe reusable. Guru masih dapat menyesuaikannya saat membuat soal.</p></div><Link href={route('question-types.index')} className="text-sm font-bold text-indigo-700 underline">Kelola tipe soal</Link></div>
                        <div className="mt-4 grid gap-2 sm:grid-cols-2">{availableQuestionBlueprints.map((blueprint) => <label key={blueprint.id} className="flex items-center gap-3 rounded-lg bg-white p-3 text-sm text-slate-800"><input type="checkbox" checked={data.question_blueprint_ids.includes(blueprint.id)} onChange={() => setData('question_blueprint_ids', data.question_blueprint_ids.includes(blueprint.id) ? data.question_blueprint_ids.filter((id) => id !== blueprint.id) : [...data.question_blueprint_ids, blueprint.id])} className="rounded border-slate-300 text-indigo-600" /><span><strong>{blueprint.name}</strong><span className="ml-2 font-mono text-xs text-slate-400">{blueprint.code}</span></span></label>)}</div>
                        {availableQuestionBlueprints.length === 0 && <p className="mt-4 text-sm text-amber-700">Belum ada tipe soal. Tambahkan melalui menu Tipe Soal B. Indonesia.</p>}
                        <InputError message={errors.question_blueprint_ids} className="mt-2" />
                    </div>}

                    <div className="mt-5">
                        <label htmlFor="name" className="block text-sm font-medium text-slate-700">{data.parent_id ? 'Nama subkompetensi' : 'Nama kompetensi'}</label>
                        <input
                            id="name"
                            type="text"
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            placeholder="Contoh: Membuat inferensi"
                            autoFocus
                            className="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                        />
                        <InputError message={errors.name} className="mt-2" />
                    </div>

                    <div className="mt-5">
                        <label htmlFor="description" className="block text-sm font-medium text-slate-700">Deskripsi <span className="font-normal text-slate-400">(opsional)</span></label>
                        <textarea
                            id="description"
                            rows={4}
                            value={data.description}
                            onChange={(event) =>
                                setData('description', event.target.value)
                            }
                            placeholder="Jelaskan kemampuan yang diukur oleh kompetensi ini."
                            className="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                        />
                        <InputError
                            message={errors.description}
                            className="mt-2"
                        />
                    </div>

                    <div className="mt-5 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label htmlFor="grade_level" className="block text-sm font-medium text-slate-700">Jenjang kelas</label>
                            <select
                                id="grade_level"
                                value={data.grade_level}
                                onChange={(event) => {
                                    setData((current) => ({
                                        ...current,
                                        grade_level: Number(event.target.value),
                                        parent_id: '',
                                    }));
                                }}
                                className="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option value={6}>Kelas 6</option>
                                <option value={9}>Kelas 9</option>
                                <option value={12}>Kelas 12</option>
                            </select>
                            <InputError
                                message={errors.grade_level}
                                className="mt-2"
                            />
                        </div>

                        <div>
                            <label htmlFor="parent_id" className="block text-sm font-medium text-slate-700">Jenis dan kompetensi induk</label>
                            <select
                                id="parent_id"
                                value={data.parent_id}
                                onChange={(event) =>
                                    setData('parent_id', event.target.value)
                                }
                                className="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option value="">Kompetensi utama</option>
                                {availableParents.map((parent) => (
                                    <option key={parent.id} value={parent.id}>
                                        {parent.code} · {parent.name}
                                    </option>
                                ))}
                            </select>
                            <InputError
                                message={errors.parent_id}
                                className="mt-2"
                            />
                        </div>
                    </div>

                    <div className="mt-8 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Link
                            href={route('competencies.index')}
                            className="rounded-xl border border-slate-300 px-5 py-3 text-center text-sm font-semibold text-slate-600 hover:bg-slate-50"
                        >
                            Batal
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-xl bg-emerald-600 px-6 py-3 text-sm font-bold text-white hover:bg-emerald-500 disabled:opacity-50"
                        >
                            {processing
                                ? 'Menyimpan…'
                                : editing
                                  ? 'Simpan perubahan'
                                : `Tambah ${data.parent_id ? 'subkompetensi' : 'kompetensi'}`}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
