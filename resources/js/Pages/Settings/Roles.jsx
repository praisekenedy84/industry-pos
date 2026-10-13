import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { Link, useForm } from '@inertiajs/react';
import { Button, Card, Field, inputClass, Shell } from '@/lib/ui';

export default function Roles({ roles }) {
    const t = useT();
    const form = useForm({ name: '', clone_from: '' });
    return (
        <Shell title={t("Roles")}>
            <form className="mb-4 flex gap-2" onSubmit={(event) => { event.preventDefault(); form.post('/settings/roles'); }}>
                <Field error={form.errors.name} label={t("New role")}><Input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <div className="self-end"><Button icon="add" loading={form.processing} type="submit">{t("Create role")}</Button></div>
            </form>
            <Card className="mb-4 divide-y divide-hairline text-sm">
                {roles.map((role) => <Link key={role.id} href={`/settings/roles/${role.id}`} className="block px-4 py-3">{role.name}</Link>)}
            </Card>
        </Shell>
    );
}
