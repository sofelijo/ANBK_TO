import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import ThemeToggle from '@/Components/ThemeToggle';
import { Link, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useState } from 'react';

type NavigationItem = { label: string; href: string; active: string };
type NavigationGroup = { label: string; items: NavigationItem[] };

export default function AuthenticatedLayout({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const { auth, flash, notifications } = usePage().props;
    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);
    const isStudent = auth.user.role === 'student';

    const dashboard: NavigationItem = { label: 'Dashboard', href: route('dashboard'), active: 'dashboard' };
    const studentNavigation: NavigationItem[] = [
        dashboard,
        { label: 'Try Out', href: route('assessments.index'), active: 'assessments.*' },
        { label: 'Teman Belajar ASKA', href: route('student-chat.show'), active: 'student-chat.*' },
    ];
    const academicGroups: NavigationGroup[] = [
        {
            label: 'Pelaksanaan',
            items: [
                { label: 'Paket Ujian', href: route('assessments.index'), active: 'assessments.*' },
                ...(auth.user.role === 'admin'
                    ? [
                          { label: 'Jadwal Sekolah', href: route('schedules.index'), active: 'schedules.*' },
                          { label: 'Monitoring TO', href: route('monitoring.index'), active: 'monitoring.*' },
                      ]
                    : []),
            ],
        },
        {
            label: 'Konten',
            items: [
                { label: 'Bank Soal', href: route('questions.index'), active: 'questions.*' },
                { label: 'Mata Pelajaran', href: route('subjects.index'), active: 'subjects.*' },
                { label: 'Kompetensi', href: route('competencies.index'), active: 'competencies.*' },
                { label: 'Tipe Soal B. Indonesia', href: route('question-types.index'), active: 'question-types.*' },
            ],
        },
        {
            label: 'Pemantauan',
            items: [
                { label: 'Chat Siswa', href: route('teacher-chat.index'), active: 'teacher-chat.*' },
                { label: 'Kata Sensor ASKA', href: route('censored-words.index'), active: 'censored-words.*' },
                { label: 'Laporan', href: route('reports.index'), active: 'reports.*' },
            ],
        },
        ...(auth.user.role === 'admin'
            ? [{
                  label: 'Administrasi',
                  items: [
                      { label: 'Data Sekolah', href: route('school.edit'), active: 'school.edit' },
                      { label: 'Data Siswa', href: route('school.students.index'), active: 'school.students.*' },
                      { label: 'Pengguna', href: route('admin.users.index'), active: 'admin.users.*' },
                      { label: 'Analisis Verifikasi', href: route('admin.teacher-verifications.index'), active: 'admin.teacher-verifications.*' },
                      { label: 'Bentuk Soal Aktif', href: route('admin.question-types.edit'), active: 'admin.question-types.*' },
                      { label: 'Kuota AI Guru', href: route('admin.ai-quotas.edit'), active: 'admin.ai-quotas.*' },
                      { label: 'Default Bundle B. Indonesia', href: route('admin.indonesian-bundles.edit'), active: 'admin.indonesian-bundles.*' },
                  ],
              }]
            : []),
    ];
    const operatorGroups: NavigationGroup[] = [
        {
            label: 'Pelaksanaan',
            items: [
                { label: 'Jadwal Sekolah', href: route('schedules.index'), active: 'schedules.*' },
                { label: 'Monitoring TO', href: route('monitoring.index'), active: 'monitoring.*' },
            ],
        },
        {
            label: 'Administrasi',
            items: [
                { label: 'Data Sekolah', href: route('school.edit'), active: 'school.edit' },
                { label: 'Data Siswa', href: route('school.students.index'), active: 'school.students.*' },
            ],
        },
    ];
    const managementGroups = auth.user.role === 'operator' ? operatorGroups : academicGroups;
    const roleLabels = { admin: 'Admin', operator: 'Operator', teacher: 'Guru', student: 'Siswa' };
    const isItemActive = (item: NavigationItem) => Boolean(route().current(item.active));
    const isGroupActive = (group: NavigationGroup) => group.items.some(isItemActive);

    return (
        <div className="min-h-screen bg-slate-50">
            <nav className="border-b border-slate-200 bg-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex">
                            <Link
                                href={route('dashboard')}
                                className="flex shrink-0 items-center gap-3"
                            >
                                <ApplicationLogo className="h-9 w-9" />
                                <span className="font-semibold text-slate-900">
                                    TOA
                                </span>
                            </Link>

                            <div className="hidden xl:ms-8 xl:flex xl:items-stretch xl:gap-5">
                                <NavLink href={dashboard.href} active={isItemActive(dashboard)}>Dashboard</NavLink>
                                {!isStudent ? managementGroups.map((group) => (
                                    <Dropdown key={group.label}>
                                        <Dropdown.Trigger>
                                            <button className={`inline-flex h-16 items-center border-b-2 px-1 text-sm font-medium transition ${isGroupActive(group) ? 'border-indigo-400 text-slate-900' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'}`}>
                                                {group.label}<span className="ms-1.5 text-xs">⌄</span>
                                            </button>
                                        </Dropdown.Trigger>
                                        <Dropdown.Content align="left" contentClasses="py-1 bg-white">
                                            {group.items.map((item) => (
                                                <Dropdown.Link key={item.label} href={item.href} className={isItemActive(item) ? 'bg-indigo-50 font-semibold text-indigo-700' : ''}>
                                                    {item.label}
                                                </Dropdown.Link>
                                            ))}
                                        </Dropdown.Content>
                                    </Dropdown>
                                )) : studentNavigation.slice(1).map((item) => (
                                    <NavLink key={item.label} href={item.href} active={isItemActive(item)}>{item.label}</NavLink>
                                ))}
                            </div>
                        </div>

                        <div className="flex items-center gap-2 sm:ms-6">
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button type="button" aria-label={`Notifikasi${notifications.unread_count ? `, ${notifications.unread_count} belum dibaca` : ''}`} className="relative flex h-11 w-11 items-center justify-center rounded-lg text-xl text-slate-600 hover:bg-slate-100">
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 17H9m9-6a6 6 0 0 0-12 0c0 3-1.5 4-2 5h16c-.5-1-2-2-2-5ZM13.7 20a2 2 0 0 1-3.4 0" />
                                        </svg>
                                        {notifications.unread_count > 0 && <span className="absolute right-0.5 top-0.5 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-bold text-white">{notifications.unread_count > 99 ? '99+' : notifications.unread_count}</span>}
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content width="80" contentClasses="bg-white">
                                    <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                        <div><p className="text-sm font-bold text-slate-900">Notifikasi</p><p className="text-[11px] text-slate-500">{notifications.unread_count} belum dibaca</p></div>
                                        {notifications.unread_count > 0 && <Link href={route('notifications.read-all')} method="post" as="button" className="text-xs font-semibold text-indigo-700">Baca semua</Link>}
                                    </div>
                                    {notifications.items.length === 0 ? <p className="px-4 py-6 text-center text-sm text-slate-500">Belum ada notifikasi.</p> : notifications.items.map((item) => (
                                        <Link key={item.id} href={route('notifications.open', item.id)} className={`block border-b border-slate-100 px-4 py-3 last:border-b-0 hover:bg-slate-50 ${item.read_at ? '' : 'bg-indigo-50/60'}`}>
                                            <div className="flex gap-2"><span className={`mt-1 h-2 w-2 shrink-0 rounded-full ${item.read_at ? 'bg-slate-200' : item.kind === 'warning' ? 'bg-amber-500' : item.kind === 'success' ? 'bg-emerald-500' : 'bg-indigo-500'}`} /><div className="min-w-0"><p className="text-sm font-bold text-slate-900">{item.title}</p><p className="mt-0.5 line-clamp-2 text-xs leading-5 text-slate-600">{item.message}</p>{item.created_at && <p className="mt-1 text-[10px] text-slate-400">{new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(item.created_at))}</p>}</div></div>
                                        </Link>
                                    ))}
                                    <Link href={route('notifications.index')} className="block px-4 py-3 text-center text-xs font-bold text-indigo-700 hover:bg-indigo-50">Lihat semua notifikasi</Link>
                                </Dropdown.Content>
                            </Dropdown>
                            <ThemeToggle showLabel={false} />

                            <div className="hidden xl:flex xl:items-center">
                                <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-emerald-700">
                                    {roleLabels[auth.user.role]}
                                </span>
                                <div className="relative ms-3">
                                    <Dropdown>
                                        <Dropdown.Trigger>
                                            <button className="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900">
                                                <span className="max-w-40 truncate">{auth.user.name}</span>
                                                <span className="ms-2">⌄</span>
                                            </button>
                                        </Dropdown.Trigger>
                                        <Dropdown.Content>
                                            <Dropdown.Link href={route('profile.edit')}>
                                                Profil
                                            </Dropdown.Link>
                                            <Dropdown.Link
                                                href={route('logout')}
                                                method="post"
                                                as="button"
                                            >
                                                Keluar
                                            </Dropdown.Link>
                                        </Dropdown.Content>
                                    </Dropdown>
                                </div>
                            </div>

                            <button
                                type="button"
                                aria-label={showingNavigationDropdown ? 'Tutup menu navigasi' : 'Buka menu navigasi'}
                                aria-expanded={showingNavigationDropdown}
                                aria-controls="mobile-navigation"
                                onClick={() =>
                                    setShowingNavigationDropdown((value) => !value)
                                }
                                className="my-auto min-h-11 min-w-11 rounded-lg p-2 text-slate-500 xl:hidden"
                            >
                                ☰
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    id="mobile-navigation"
                    className={`${showingNavigationDropdown ? 'block' : 'hidden'} border-t border-slate-100 xl:hidden`}
                >
                    <div className="space-y-1 py-2">
                        <ResponsiveNavLink href={dashboard.href} active={isItemActive(dashboard)}>Dashboard</ResponsiveNavLink>
                        {!isStudent ? managementGroups.map((group) => (
                            <div key={group.label} className="border-t border-slate-100 pt-2">
                                <p className="px-4 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">{group.label}</p>
                                {group.items.map((item) => <ResponsiveNavLink key={item.label} href={item.href} active={isItemActive(item)}>{item.label}</ResponsiveNavLink>)}
                            </div>
                        )) : studentNavigation.slice(1).map((item) => (
                            <ResponsiveNavLink key={item.label} href={item.href} active={isItemActive(item)}>{item.label}</ResponsiveNavLink>
                        ))}
                        <ResponsiveNavLink href={route('profile.edit')}>
                            Profil
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            method="post"
                            href={route('logout')}
                            as="button"
                        >
                            Keluar
                        </ResponsiveNavLink>
                    </div>
                </div>
            </nav>

            {header && (
                <header className="border-b border-slate-200 bg-white">
                    <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        {header}
                    </div>
                </header>
            )}

            {(flash.success || flash.error) && (
                <div className="mx-auto mt-6 max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div
                        className={`rounded-xl border px-4 py-3 text-sm ${
                            flash.error
                                ? 'border-rose-200 bg-rose-50 text-rose-700'
                                : 'border-emerald-200 bg-emerald-50 text-emerald-700'
                        }`}
                    >
                        {flash.error || flash.success}
                    </div>
                </div>
            )}

            <main>{children}</main>
        </div>
    );
}
