import { useT } from '@/lib/ui';
import { Card, Shell } from '@/lib/ui';
import { Link } from '@inertiajs/react';

export default function Index({ reports }) {
    const t = useT();
    const groups = reports.reduce((map, report) => {
        map[report.group] = map[report.group] || [];
        map[report.group].push(report);
        return map;
    }, {});
    return (
        <Shell title={t("Reports")}>
            <p className="mb-4 text-muted">{t("Choose a report from the Reports submenu in the navigation.")}</p>
            <div className="grid gap-4 md:grid-cols-2">
                {Object.entries(groups).map(([group, rows]) => (
                    <Card key={group} className="p-4">
                        <h2 className="mb-2 font-medium">{t(group)}</h2>
                        <ul className="space-y-1 text-sm">
                            {rows.map((report) => <li key={report.key}><Link className="text-link hover:underline" href={`/reports/${report.key}`}>{t(report.name)}</Link></li>)}
                        </ul>
                    </Card>
                ))}
            </div>
        </Shell>
    );
}
