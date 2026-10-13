import { useT } from '@/lib/ui';
import { Link, usePage } from '@inertiajs/react';

export default function HttpError({ status, home }) {
    const t = useT();
    const { auth, base = '' } = usePage().props;
    const href = home || (base ? `${base}/dashboard` : auth?.admin ? '/platform' : '/');
    const label = href.endsWith('/dashboard') ? 'Back to the dashboard' : (href === '/platform' || href === '/admin' ? 'Back to workspaces' : 'Back to the start');
    const copy = {
        403: 'You do not have access to this page.',
        404: 'That page is not in this workspace.',
        419: 'The page expired. Refresh and try again.',
        500: 'Something went wrong on our side.',
        503: 'Makerly is briefly unavailable.',
    };
    return (
        <div className="flex min-h-screen flex-col items-center justify-center px-6 text-center">
            <p className="text-sm text-muted">{status}</p>
            <h1 className="mt-2 text-2xl font-semibold">{copy[status] || 'Request could not be completed.'}</h1>
            <Link href={href} className="mt-6 text-primary">{t(label)}</Link>
        </div>
    );
}
