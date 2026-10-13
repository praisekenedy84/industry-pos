import { useT } from '@/lib/ui';
import { Link, router } from '@inertiajs/react';
import { Button, Card, Shell } from '@/lib/ui';

export default function Onboarding({ checklist }) {
    const t = useT();
    return (
        <Shell title={t("Getting started")} actions={<Button icon="cancel" onClick={() => router.post('/onboarding')}>{t("Dismiss")}</Button>}>
            <Card className="divide-y divide-hairline">
                {checklist.steps.map((step) => (
                    <Link key={step.key} href={step.href} className="flex items-center justify-between px-4 py-3 text-sm">
                        <span className={step.done ? 'text-muted line-through' : ''}>{step.label.replace('checklist.', '')}</span>
                        <span>{step.done ? 'Done' : 'Open'}</span>
                    </Link>
                ))}
            </Card>
        </Shell>
    );
}
