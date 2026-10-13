import { useT } from '@/lib/ui';
import { router } from '@inertiajs/react';
import { ConfirmButton, Shell } from '@/lib/ui';
import { w } from '@/lib/paths';

export default function Sessions({ sessions = [] }) {
    const t = useT();
    return (
        <Shell title={t("Active sessions")}>
            <ul className="divide-y divide-hairline rounded-xl border border-hairline bg-surface">
                {sessions.map((session) => (
                    <li key={session.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                        <div>
                            <p className="font-medium">{session.device}{session.current ? ' · This device' : ''}</p>
                            <p className="text-muted">{session.location} · {session.workspaces?.join(', ') || 'No open workspace'}</p>
                        </div>
                        {!session.current && (
                            <ConfirmButton icon="suspend" tone="danger" title={t("Revoke this session?")} description={t("This device will be signed out and will need to sign in again.")} onConfirm={() => router.delete(w(`/account/sessions/${session.id}`))}>{t("Revoke")}</ConfirmButton>
                        )}
                    </li>
                ))}
            </ul>
        </Shell>
    );
}
