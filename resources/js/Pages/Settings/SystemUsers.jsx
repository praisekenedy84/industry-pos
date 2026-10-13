import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Card, ConfirmButton, Field, inputClass, Shell } from '@/lib/ui';

export default function SystemUsers({ systemUsers, tokens, permissions }) {
    const t = useT();
    const create = useForm({ name: '', purpose: '' });
    const [scopes, setScopes] = useState(['catalog.items.view']);
    const token = useForm({ name: 'Integration', scopes, expires_at: '' });
    return (
        <Shell title={t("System users")}>
            <form className="mb-6 flex gap-2" onSubmit={(event) => { event.preventDefault(); create.post('/settings/system-users'); }}>
                <Input className={inputClass} placeholder={t("Name")} value={create.data.name} onChange={(event) => create.setData('name', event.target.value)} />
                <Button icon="add" loading={create.processing} type="submit">{t("Create system user")}</Button>
            </form>
            {systemUsers[0] && (
                <form className="mb-4 space-y-2" onSubmit={(event) => { event.preventDefault(); token.transform((data) => ({ ...data, scopes })); token.post(`/settings/system-users/${systemUsers[0].id}/tokens`); }}>
                    <Field error={token.errors.name} label={t("Token name")}><Input className={inputClass} value={token.data.name} onChange={(event) => token.setData('name', event.target.value)} /></Field>
                    <Input select multiple label={t("Permissions")} className={inputClass + ' h-32'} value={scopes} onChange={(event) => setScopes([...event.target.selectedOptions].map((option) => option.value))}>
                        {permissions.slice(0, 40).map((permission) => <option key={permission.key} value={permission.key}>{permission.key}</option>)}
                    </Input>
                    <Button icon="key" type="submit">{t("Issue token")}</Button>
                </form>
            )}
            <Card className="mb-4 divide-y divide-hairline text-sm">
                {systemUsers.map((user) => <div key={user.id} className="px-4 py-3">{user.name} · {user.purpose}</div>)}
            </Card>
            <Card className="mt-4 divide-y divide-hairline text-sm">
                {tokens.map((row) => (
                    <div key={row.id} className="flex items-center justify-between px-4 py-3">
                        <span>{row.name} · {row.token_prefix}… {row.revoked_at ? 'revoked' : 'active'}</span>
                        <span className="flex gap-2">
                            <Button icon="sync" tone="ghost" onClick={() => router.post(`/settings/tokens/${row.id}/rotate`)}>{t("Rotate")}</Button>
                            <ConfirmButton icon="suspend" tone="ghost" title={`Revoke ${row.name}?`} description={t("Any integration using this token will lose access. It will need a new token to reconnect.")} onConfirm={() => router.post(`/settings/tokens/${row.id}/revoke`)}>{t("Revoke")}</ConfirmButton>
                        </span>
                    </div>
                ))}
            </Card>
        </Shell>
    );
}
