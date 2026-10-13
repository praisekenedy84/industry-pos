import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { router, useForm } from '@inertiajs/react';
import { Button, Shell } from '@/lib/ui';

export default function RoleEditor({ role, grants, permissions }) {
    const t = useT();
    const initial = permissions.map((permission) => {
        const grant = grants.find((row) => row.permission_key === permission.key);
        return { permission_key: permission.key, scope: grant?.scope || 'none', module: permission.module };
    });
    const form = useForm({ name: role.name, grants: initial });
    return (
        <Shell title={role.name} actions={<Button icon="copy" tone="ghost" onClick={() => router.post(`/settings/roles/${role.id}/clone`)}>{t("Clone")}</Button>}>
            <form onSubmit={(event) => { event.preventDefault(); form.put(`/settings/roles/${role.id}`); }}>
                <Input label={t("Name")} className="mb-3 w-full" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                <div className="max-h-[32rem] overflow-auto rounded-xl border border-hairline bg-surface">
                    {form.data.grants.map((grant, index) => (
                        <div key={grant.permission_key} className="grid grid-cols-[1fr_140px] items-center gap-2 border-b border-hairline px-3 py-2 text-sm">
                            <span>{grant.permission_key}</span>
                            <Input select label={t("Scope")} className="" value={grant.scope} onChange={(event) => form.setData('grants', form.data.grants.map((row, i) => i === index ? { ...row, scope: event.target.value } : row))}>
                                <option value="none">{t("None")}</option>
                                <option value="own">{t("Own")}</option>
                                <option value="branch">{t("Branch")}</option>
                                <option value="all">{t("All")}</option>
                            </Input>
                        </div>
                    ))}
                </div>
                <Button icon="save" loading={form.processing} className="mt-3" type="submit">{t("Save role")}</Button>
            </form>
        </Shell>
    );
}
