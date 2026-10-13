import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { setWorkspaceBase, w } from '@/lib/paths';
import AppTheme from '@/lib/theme';

const visit = router.visit.bind(router);
router.visit = (href, options = {}) => visit(typeof href === 'string' ? w(href) : href, options);

function rememberPage(page) {
    const token = page?.props?.csrf;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (token && meta) {
        meta.setAttribute('content', token);
    }
    setWorkspaceBase(page?.props?.base || '');
    const href = page?.props?.manifest;
    let link = document.querySelector('link[rel="manifest"]');
    if (!href) {
        link?.remove();
        return;
    }
    if (!link) {
        link = document.createElement('link');
        link.rel = 'manifest';
        document.head.appendChild(link);
    }
    link.href = href;
    fetch(`${page.props.base}/offline`).catch(() => {});
}

createInertiaApp({
    title: (title) => (title ? `${title} · Makerly` : 'Makerly'),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        rememberPage(props.initialPage);
        applyTheme(props.initialPage?.props?.theme);
        createRoot(el).render(<AppTheme><App {...props} /></AppTheme>);
    },
    progress: { color: '#5745de' },
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then((registration) => {
            registration.addEventListener('updatefound', () => {
                const worker = registration.installing;
                worker?.addEventListener('statechange', () => {
                    if (worker.state !== 'installed' || !navigator.serviceWorker.controller) {
                        return;
                    }
                    if (Number(localStorage.getItem('makerly.pos.cartCount') || 0) > 0) {
                        return;
                    }
                    const banner = document.createElement('button');
                    banner.type = 'button';
                    banner.textContent = 'New version available. Refresh';
                    banner.style.cssText = 'position:fixed;bottom:16px;right:16px;z-index:50;background:#0075de;color:#fff;border:0;border-radius:8px;padding:10px 14px;';
                    banner.onclick = () => {
                        worker.postMessage('skipWaiting');
                        window.location.reload();
                    };
                    document.body.appendChild(banner);
                });
            });
        }).catch(() => {});
    });
}

function themeChoice(value) {
    return value === 'light' || value === 'dark' || value === 'system' ? value : null;
}

function storedTheme() {
    const match = document.cookie.match(/(?:^|; )theme=([^;]*)/);
    const fromCookie = themeChoice(match ? decodeURIComponent(match[1]) : '');
    if (fromCookie) {
        return fromCookie;
    }
    try {
        return themeChoice(localStorage.getItem('makerly.theme')) || 'system';
    } catch {
        return 'system';
    }
}

function applyTheme(theme) {
    const choice = themeChoice(theme) || storedTheme();
    if (themeChoice(theme)) {
        try {
            localStorage.setItem('makerly.theme', theme);
        } catch {
            // Private browsing can block storage.
        }
    }
    const dark = choice === 'dark' || (choice !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
}

router.on('success', (event) => {
    rememberPage(event.detail.page);
    applyTheme(event.detail.page.props.theme);
});

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (storedTheme() === 'system') {
        applyTheme('system');
    }
});
