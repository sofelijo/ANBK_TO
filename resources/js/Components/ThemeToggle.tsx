import { useEffect, useState } from 'react';

type Theme = 'light' | 'dark';

const currentTheme = (): Theme => document.documentElement.classList.contains('dark') ? 'dark' : 'light';

export default function ThemeToggle({ className = '', showLabel = true }: { className?: string; showLabel?: boolean }) {
    const [theme, setTheme] = useState<Theme>(currentTheme);

    useEffect(() => {
        document.documentElement.classList.toggle('dark', theme === 'dark');
        document.documentElement.style.colorScheme = theme;

        try {
            window.localStorage.setItem('toa-theme', theme);
        } catch {
            // Tema tetap diterapkan untuk sesi aktif ketika penyimpanan browser tidak tersedia.
        }
    }, [theme]);

    const dark = theme === 'dark';

    return (
        <button
            type="button"
            aria-label={dark ? 'Gunakan mode terang' : 'Gunakan mode gelap'}
            aria-pressed={dark}
            title={dark ? 'Gunakan mode terang' : 'Gunakan mode gelap'}
            onClick={() => setTheme(dark ? 'light' : 'dark')}
            className={`inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 print:hidden ${className}`}
        >
            <span aria-hidden="true" className="text-lg leading-none">{dark ? '☀' : '☾'}</span>
            {showLabel && <span className="hidden lg:inline">{dark ? 'Mode terang' : 'Mode gelap'}</span>}
        </button>
    );
}
