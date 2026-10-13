import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { router, useForm } from '@inertiajs/react';
import { Button, Card, Field, inputClass, Shell } from '@/lib/ui';
import RecordActions from '@/lib/RecordActions';

export default function Branches({ branches, multi, limit, canManage }) {
    const t = useT();
    const form = useForm({ name: '', code: '', address: '', phone: '' });
    return (
        <Shell title={t("Branches")}>
            <p className="mb-3 text-sm text-muted">{multi ? `Plan allows ${limit || 'unlimited'} branches.` : 'Multi-branch is off for this plan.'}</p>
            {canManage && <form className="mb-4 grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.post('/settings/branches'); }}>
                <Field error={form.errors.name} label={t("Name")}><Input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <Field error={form.errors.code} label={t("Code")}><Input className={inputClass} value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} /></Field>
                <Button icon="add" loading={form.processing} type="submit">{t("Add branch")}</Button>
            </form>}
            <Card className="mb-4 divide-y divide-hairline text-sm">
                {branches.map((branch) => (
                    <div key={branch.id} className="flex items-center justify-between px-4 py-3">
                        <span>{branch.name} · {branch.code} · {branch.status}</span>
                        <RecordActions record={branch} path={`/settings/branches/${branch.id}`} canEdit={canManage} fields={[{ name: 'name', label: 'Name', required: true }, { name: 'address', label: 'Address' }, { name: 'phone', label: 'Phone' }]} />
                        {canManage && <Button icon={branch.status === "active" ? "suspend" : "start"} tone="ghost" onClick={() => router.post(`/settings/branches/${branch.id}/suspend`, { status: branch.status === 'active' ? 'suspended' : 'active', reason: 'Changed from settings' })}>{branch.status === 'active' ? t("Suspend") : t("Activate")}</Button>}
                    </div>
                ))}
            </Card>
        </Shell>
    );
}
