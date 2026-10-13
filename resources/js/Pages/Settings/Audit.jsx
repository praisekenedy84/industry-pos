import { useT } from '@/lib/ui';
import { Card, Pager, rowsOf, Shell } from '@/lib/ui';

export default function Audit({ logs }) {
    const t = useT();
    return (
        <Shell title={t("Audit trail")}>
            <Card>
                <ul className="divide-y divide-hairline text-sm">
                    {rowsOf(logs).map((log) => <li key={log.id} className="px-4 py-3"><span className="font-medium">{log.action}</span><span className="ml-2 text-muted">{log.created_at}</span></li>)}
                </ul>
                <Pager paginator={logs} />
            </Card>
        </Shell>
    );
}
