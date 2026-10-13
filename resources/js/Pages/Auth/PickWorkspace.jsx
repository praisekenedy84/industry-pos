import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { AuthCard, Button } from '@/lib/ui';

export default function PickWorkspace({ workspaces = [] }) {
    const t = useT();
    const form = useForm({
        tenant_id: workspaces[0]?.tenant_id || '',
        remember: false,
        force: false,
    });

    return (
        <AuthCard title={t("Choose a workspace")}>
            <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); form.post('/workspaces'); }}>
                {workspaces.map((workspace) => (
                    <label key={workspace.tenant_id} className={`flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-3 ${form.data.tenant_id === workspace.tenant_id ? 'border-primary' : 'border-hairline'}`}>
                        <Input type="radio" name="tenant_id" checked={form.data.tenant_id === workspace.tenant_id} onChange={() => form.setData('tenant_id', workspace.tenant_id)} />
                        <span>
                            <span className="block font-medium">{workspace.name}</span>
                            <span className="text-sm text-muted">{workspace.role}</span>
                        </span>
                    </label>
                ))}
                <label className="flex items-center gap-2 text-sm">
                    <Input type="checkbox" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} />
                    {t("Remember my choice")}
                </label>
                {form.errors.concurrent && (
                    <Button icon="logout" onClick={() => { form.transform((data) => ({ ...data, force: true })); form.post('/workspaces'); }}>{t("Sign out the other device and continue")}</Button>
                )}
                <Button icon="next" loading={form.processing} type="submit" disabled={form.processing || !form.data.tenant_id}>{t("Continue")}</Button>
            </form>
        </AuthCard>
    );
}
