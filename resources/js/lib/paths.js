let base = '';

const centralPrefixes = [
    '/login',
    '/signup',
    '/platform',
    '/admin',
    '/invite',
    '/forgot-password',
    '/reset-password',
    '/workspaces',
    '/two-factor',
    '/up',
    '/build',
    '/api',
    '/sw.js',
    '/offline.html',
];

export function setWorkspaceBase(value) {
    base = value || '';
}

export function workspaceBase() {
    return base;
}

export function w(path) {
    if (!path || typeof path !== 'string') {
        return path;
    }
    if (!base || path.startsWith('http') || path.startsWith('//') || path.startsWith(base)) {
        return path;
    }
    if (centralPrefixes.some((prefix) => path === prefix || path.startsWith(`${prefix}/`) || path.startsWith(`${prefix}?`))) {
        return path;
    }
    if (path.startsWith('/')) {
        return base + path;
    }

    return path;
}
