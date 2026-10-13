import { useT } from '@/lib/ui';
import { Link } from '@inertiajs/react';

export default function HttpError({ status }) {
    const t = useT();
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
            <Link href="/dashboard" className="mt-6 text-primary">{t("Back to the dashboard")}</Link>
        </div>
    );
}
