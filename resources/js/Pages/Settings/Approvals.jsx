import { useT } from '@/lib/ui';
import { router } from '@inertiajs/react';
import { Button, Card, Pager, rowsOf, Shell } from '@/lib/ui';

export default function Approvals({ approvals }) {
    const t = useT();
    return (
        <Shell title={t("Approvals")}>
            <Card>
                {rowsOf(approvals).map((row) => (
                    <div key={row.id} className="flex items-center justify-between border-b border-hairline px-4 py-3 text-sm">
                        <span>{row.type || row.action || 'Request'} · {row.status}</span>
                        {row.status === 'pending' && (
                            <span className="flex gap-2">
                                <Button icon="approve" onClick={() => router.post(`/settings/approvals/${row.id}`, { status: 'approved' })}>{t("Approve")}</Button>
                                <Button icon="reject" tone="ghost" onClick={() => router.post(`/settings/approvals/${row.id}`, { status: 'rejected' })}>{t("Reject")}</Button>
                            </span>
                        )}
                    </div>
                ))}
                <Pager paginator={approvals} />
            </Card>
        </Shell>
    );
}
