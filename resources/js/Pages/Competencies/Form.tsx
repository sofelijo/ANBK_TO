import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type ParentOption = {
    id: number;
    code: string;
    name: string;
    grade_level: number;
};

type Competency = {
    id: number;
    code: string;
    domain: string;
    name: string;
    description?: string;
    grade_level: number;
    parent_id?: number;
};

export default function Form({
    competency,
    parents,
}: {
    competency?: Competency;
    parents: ParentOption[];
}) {
    const editing = Boolean(competency);
    const { data, setData, post, put, processing, errors } = useForm({
        code: competency?.code || '',
        domain: competency?.domain || '',
        name: competency?.name || '',
        description: competency?.description || '',
        grade_level: competency?.grade_level || 5,
        parent_id: competency?.parent_id ? String(competency.parent_id) : '',
    });
    const availableParents = parents.filter(
        (parent) => parent.grade_level === data.grade_level,
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
                        {editing ? 'Edit Kompetensi' : 'Tambah Kompetensi'}
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
                    <div className="grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="code" value="Kode kompetensi" />
                            <TextInput
                                id="code"
                                value={data.code}
                                onChange={(event) =>
                                    setData(
                                        'code',
                                        event.target.value.toUpperCase(),
                                    )
                                }
                                placeholder="Contoh: LIT5-INFER"
                                className="mt-1 block w-full font-mono uppercase"
                                isFocused
                            />
                            <InputError
                                message={errors.code}
                                className="mt-2"
                            />
                        </div>

                        <div>
                            <InputLabel htmlFor="domain" value="Domain" />
                            <TextInput
                                id="domain"
                                value={data.domain}
                                onChange={(event) =>
                                    setData('domain', event.target.value)
                                }
                                placeholder="Literasi atau Numerasi"
                                className="mt-1 block w-full"
                            />
                            <InputError
                                message={errors.domain}
                                className="mt-2"
                            />
                        </div>
                    </div>

                    <div className="mt-5">
                        <InputLabel htmlFor="name" value="Nama kompetensi" />
                        <TextInput
                            id="name"
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            placeholder="Contoh: Membuat inferensi"
                            className="mt-1 block w-full"
                        />
                        <InputError message={errors.name} className="mt-2" />
                    </div>

                    <div className="mt-5">
                        <InputLabel htmlFor="description" value="Deskripsi" />
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
                            <InputLabel htmlFor="grade_level" value="Jenjang kelas" />
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
                                <option value={5}>Kelas 5</option>
                                <option value={8}>Kelas 8</option>
                                <option value={11}>Kelas 11</option>
                            </select>
                            <InputError
                                message={errors.grade_level}
                                className="mt-2"
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="parent_id"
                                value="Kompetensi induk (opsional)"
                            />
                            <select
                                id="parent_id"
                                value={data.parent_id}
                                onChange={(event) =>
                                    setData('parent_id', event.target.value)
                                }
                                className="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"
                            >
                                <option value="">Tanpa kompetensi induk</option>
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
                                  : 'Tambah kompetensi'}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
