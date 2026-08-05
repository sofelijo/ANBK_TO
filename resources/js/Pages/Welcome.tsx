import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';

type WelcomeProps = PageProps<{
    canLogin: boolean;
    canRegister: boolean;
}>;

const features = [
    {
        title: 'Bank Soal Berbantuan AI',
        description:
            'Guru membuat variasi soal, soal cerita, dan stimulus lebih cepat dengan kendali penuh.',
        icon: '✦',
    },
    {
        title: 'Try Out Fleksibel',
        description:
            'Atur jumlah soal, durasi, jadwal, dan paket try out sesuai kebutuhan sekolah.',
        icon: '◷',
    },
    {
        title: 'Analisis Kemampuan',
        description:
            'Lihat ringkasan hasil, titik lemah siswa, dan rekomendasi latihan yang relevan.',
        icon: '↗',
    },
];

export default function Welcome({ auth, canLogin, canRegister }: WelcomeProps) {
    const primaryHref = auth.user ? route('dashboard') : route('login');

    return (
        <>
            <Head title="ANBK Cerdas" />
            <div className="min-h-screen overflow-hidden bg-[#f7faf9] text-slate-900">
                <header className="relative z-20 border-b border-emerald-100/80 bg-white/90 backdrop-blur">
                    <div className="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 sm:px-8 lg:px-10">
                        <Link href="/" className="flex items-center gap-3">
                            <BrandMark className="h-11 w-11" />
                            <div>
                                <p className="text-lg font-bold tracking-tight text-slate-900">
                                    ANBK Cerdas
                                </p>
                                <p className="text-[11px] font-medium uppercase tracking-[0.16em] text-emerald-600">
                                    Belajar lebih terarah
                                </p>
                            </div>
                        </Link>

                        <nav className="flex items-center gap-2 sm:gap-3">
                            {auth.user ? (
                                <Link
                                    href={route('dashboard')}
                                    className="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700"
                                >
                                    Buka Dashboard
                                </Link>
                            ) : (
                                <>
                                    {canRegister && (
                                        <Link
                                            href={route('register')}
                                            className="hidden rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 sm:inline-flex"
                                        >
                                            Daftar Guru
                                        </Link>
                                    )}
                                    {canLogin && (
                                        <Link
                                            href={route('login')}
                                            className="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700"
                                        >
                                            Masuk
                                        </Link>
                                    )}
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                <main>
                    <section className="relative">
                        <div className="absolute -right-40 -top-32 h-[34rem] w-[34rem] rounded-full bg-emerald-200/40 blur-3xl" />
                        <div className="absolute -left-52 bottom-0 h-96 w-96 rounded-full bg-amber-100/70 blur-3xl" />

                        <div className="relative mx-auto grid max-w-7xl items-center gap-14 px-5 py-16 sm:px-8 sm:py-24 lg:grid-cols-[1.05fr_0.95fr] lg:px-10 lg:py-28">
                            <div>
                                <div className="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-white px-3 py-1.5 text-xs font-bold uppercase tracking-[0.14em] text-emerald-700 shadow-sm">
                                    <span className="h-2 w-2 rounded-full bg-emerald-500" />
                                    Try Out ANBK Berbasis AI
                                </div>
                                <h1 className="mt-6 max-w-3xl text-4xl font-black leading-[1.08] tracking-tight text-slate-950 sm:text-5xl lg:text-6xl">
                                    Latihan lebih cerdas,
                                    <span className="block text-emerald-600">
                                        hasil lebih terarah.
                                    </span>
                                </h1>
                                <p className="mt-6 max-w-2xl text-base leading-8 text-slate-600 sm:text-lg">
                                    Platform try out untuk membantu guru menyusun
                                    soal berkualitas dan membantu siswa memahami
                                    kemampuan mereka melalui analisis berbantuan AI.
                                </p>
                                <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                                    <Link
                                        href={primaryHref}
                                        className="inline-flex items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-emerald-600/20 transition hover:-translate-y-0.5 hover:bg-emerald-500"
                                    >
                                        {auth.user
                                            ? 'Lanjut ke Dashboard'
                                            : 'Mulai Sekarang'}
                                        <span aria-hidden="true">→</span>
                                    </Link>
                                    {!auth.user && canRegister && (
                                        <Link
                                            href={route('register')}
                                            className="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-6 py-3.5 text-sm font-bold text-slate-700 shadow-sm transition hover:border-emerald-300 hover:text-emerald-700"
                                        >
                                            Daftar sebagai Guru
                                        </Link>
                                    )}
                                </div>
                                <div className="mt-8 flex flex-wrap gap-x-6 gap-y-3 text-sm text-slate-500">
                                    <span className="flex items-center gap-2">
                                        <CheckIcon /> Mudah untuk siswa SD
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <CheckIcon /> Tanpa instalasi
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <CheckIcon /> Siap digunakan sekolah
                                    </span>
                                </div>
                            </div>

                            <HeroPreview />
                        </div>
                    </section>

                    <section className="border-y border-slate-200/80 bg-white py-16 sm:py-20">
                        <div className="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                            <div className="max-w-2xl">
                                <p className="text-sm font-bold uppercase tracking-[0.16em] text-emerald-600">
                                    Satu platform
                                </p>
                                <h2 className="mt-3 text-3xl font-black tracking-tight text-slate-900 sm:text-4xl">
                                    Dari pembuatan soal sampai evaluasi siswa
                                </h2>
                            </div>
                            <div className="mt-10 grid gap-5 md:grid-cols-3">
                                {features.map((feature) => (
                                    <article
                                        key={feature.title}
                                        className="rounded-3xl border border-slate-200 bg-[#fbfdfc] p-6 transition hover:-translate-y-1 hover:border-emerald-200 hover:shadow-xl hover:shadow-emerald-900/5"
                                    >
                                        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 text-xl font-bold text-emerald-700">
                                            {feature.icon}
                                        </div>
                                        <h3 className="mt-5 text-lg font-bold text-slate-900">
                                            {feature.title}
                                        </h3>
                                        <p className="mt-2 text-sm leading-7 text-slate-600">
                                            {feature.description}
                                        </p>
                                    </article>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section className="px-5 py-16 sm:px-8 sm:py-24 lg:px-10">
                        <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-8 overflow-hidden rounded-[2rem] bg-slate-950 px-7 py-10 text-center text-white shadow-2xl shadow-slate-900/15 sm:px-12 sm:py-14 lg:flex-row lg:text-left">
                            <div>
                                <p className="text-sm font-bold uppercase tracking-[0.16em] text-emerald-400">
                                    Siap mencoba?
                                </p>
                                <h2 className="mt-3 text-3xl font-black tracking-tight">
                                    Mulai perjalanan belajar yang lebih terarah.
                                </h2>
                                <p className="mt-3 text-sm leading-7 text-slate-300">
                                    Siswa dapat langsung masuk menggunakan NPSN dan
                                    NISN.
                                </p>
                            </div>
                            <Link
                                href={primaryHref}
                                className="shrink-0 rounded-2xl bg-emerald-500 px-7 py-4 text-sm font-bold text-white transition hover:bg-emerald-400"
                            >
                                {auth.user ? 'Buka Dashboard' : 'Masuk ke ANBK Cerdas'}
                            </Link>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-slate-200 bg-white">
                    <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-3 px-5 py-7 text-sm text-slate-500 sm:flex-row sm:px-8 lg:px-10">
                        <div className="flex items-center gap-2 font-semibold text-slate-700">
                            <BrandMark className="h-8 w-8" /> ANBK Cerdas
                        </div>
                        <p>Platform try out dan pembelajaran berbantuan AI.</p>
                    </div>
                </footer>
            </div>
        </>
    );
}

function BrandMark({ className }: { className: string }) {
    return (
        <span
            className={`inline-flex items-center justify-center rounded-2xl bg-emerald-600 text-white shadow-sm ${className}`}
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-5 w-5"
                stroke="currentColor"
                strokeWidth="1.8"
            >
                <path d="M4 5.5c2.8 0 5.2.7 8 2.5v11c-2.8-1.8-5.2-2.5-8-2.5v-11Z" />
                <path d="M20 5.5c-2.8 0-5.2.7-8 2.5v11c2.8-1.8 5.2-2.5 8-2.5v-11Z" />
                <path d="m16.5 2 .45 1.05L18 3.5l-1.05.45L16.5 5l-.45-1.05L15 3.5l1.05-.45L16.5 2Z" />
            </svg>
        </span>
    );
}

function CheckIcon() {
    return (
        <span className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-700">
            ✓
        </span>
    );
}

function HeroPreview() {
    return (
        <div className="relative mx-auto w-full max-w-lg lg:mr-0">
            <div className="absolute -inset-5 rotate-3 rounded-[2.5rem] bg-emerald-200/50" />
            <div className="relative overflow-hidden rounded-[2rem] border border-white/80 bg-white p-5 shadow-2xl shadow-emerald-900/15 sm:p-7">
                <div className="flex items-center justify-between border-b border-slate-100 pb-5">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-wider text-emerald-600">
                            Ringkasan Latihan
                        </p>
                        <h3 className="mt-1 text-lg font-bold text-slate-900">
                            Literasi Membaca
                        </h3>
                    </div>
                    <div className="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-lg font-black text-emerald-700 ring-4 ring-emerald-100">
                        84
                    </div>
                </div>

                <div className="mt-6 space-y-5">
                    <Progress label="Memahami teks" value="88%" width="88%" />
                    <Progress label="Menarik kesimpulan" value="76%" width="76%" />
                    <Progress label="Evaluasi informasi" value="68%" width="68%" />
                </div>

                <div className="mt-7 rounded-2xl bg-amber-50 p-4">
                    <div className="flex gap-3">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-400 font-black text-white">
                            AI
                        </span>
                        <div>
                            <p className="text-sm font-bold text-slate-900">
                                Fokus latihan berikutnya
                            </p>
                            <p className="mt-1 text-xs leading-5 text-slate-600">
                                Latih kembali kemampuan mengevaluasi informasi
                                melalui 3 soal rekomendasi.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

function Progress({
    label,
    value,
    width,
}: {
    label: string;
    value: string;
    width: string;
}) {
    return (
        <div>
            <div className="mb-2 flex justify-between text-sm">
                <span className="font-medium text-slate-600">{label}</span>
                <span className="font-bold text-slate-900">{value}</span>
            </div>
            <div className="h-2.5 overflow-hidden rounded-full bg-slate-100">
                <div
                    className="h-full rounded-full bg-emerald-500"
                    style={{ width }}
                />
            </div>
        </div>
    );
}
