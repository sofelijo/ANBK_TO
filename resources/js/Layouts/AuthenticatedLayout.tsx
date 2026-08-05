import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import { Link, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useState } from 'react';

export default function AuthenticatedLayout({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const { auth, flash } = usePage().props;
    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);
    const canManage = ['admin', 'teacher'].includes(auth.user.role);

    const navigation = [
        { label: 'Dashboard', href: route('dashboard'), active: 'dashboard' },
        {
            label: canManage ? 'Paket Ujian' : 'Try Out',
            href: route('assessments.index'),
            active: 'assessments.*',
        },
        {
            label: canManage ? 'Chat Siswa' : 'Teman Belajar',
            href: canManage ? route('teacher-chat.index') : route('student-chat.show'),
            active: canManage ? 'teacher-chat.*' : 'student-chat.*',
        },
        ...(canManage
            ? [
                  {
                      label: 'Bank Soal',
                      href: route('questions.index'),
                      active: 'questions.*',
                  },
                  {
                      label: 'Kompetensi',
                      href: route('competencies.index'),
                      active: 'competencies.*',
                  },
                  {
                      label: 'Laporan',
                      href: route('reports.index'),
                      active: 'reports.*',
                  },
              ]
            : []),
        ...(auth.user.role === 'admin'
            ? [
                  {
                      label: 'Pengguna',
                      href: route('admin.users.index'),
                      active: 'admin.users.*',
                  },
              ]
            : []),
    ];

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
                                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-600 p-2 text-white">
                                    <ApplicationLogo className="h-full w-full fill-current" />
                                </span>
                                <span className="font-semibold text-slate-900">
                                    ANBK Cerdas
                                </span>
                            </Link>

                            <div className="hidden space-x-8 sm:ms-10 sm:flex">
                                {navigation.map((item) => (
                                    <NavLink
                                        key={item.label}
                                        href={item.href}
                                        active={route().current(item.active)}
                                    >
                                        {item.label}
                                    </NavLink>
                                ))}
                            </div>
                        </div>

                        <div className="hidden sm:ms-6 sm:flex sm:items-center">
                            <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-emerald-700">
                                {auth.user.role}
                            </span>
                            <div className="relative ms-3">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <button className="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900">
                                            {auth.user.name}
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
                            onClick={() =>
                                setShowingNavigationDropdown((value) => !value)
                            }
                            className="my-auto rounded-lg p-2 text-slate-500 sm:hidden"
                        >
                            ☰
                        </button>
                    </div>
                </div>

                <div
                    className={`${showingNavigationDropdown ? 'block' : 'hidden'} border-t border-slate-100 sm:hidden`}
                >
                    <div className="space-y-1 py-2">
                        {navigation.map((item) => (
                            <ResponsiveNavLink
                                key={item.label}
                                href={item.href}
                                active={route().current(item.active)}
                            >
                                {item.label}
                            </ResponsiveNavLink>
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
