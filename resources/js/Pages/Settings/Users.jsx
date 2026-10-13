import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { Link, useForm } from '@inertiajs/react';
import { Button, Card, Field, inputClass, Shell } from '@/lib/ui';

export default function Users({ users, roles, branches, assignments }) {
    const t = useT();
    const form = useForm({ name: '', email: '', phone: '', role_id: roles[0]?.id || '', branch_id: branches[0]?.id || '', job_title: '' });
    const csv = useForm({ csv: 'name,email,phone\n', role_id: roles[0]?.id || '', branch_id: branches[0]?.id || '' });
    return (
        <Shell title={t("People")}>
            <form className="mb-6 grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.post('/settings/users'); }}>
                <Field error={form.errors.name} label={t("Name")}><Input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <Field error={form.errors.email} label={t("Email")}><Input className={inputClass} value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></Field>
                <Field error={form.errors.role_id} label={t("Role")}><Input select className={inputClass} value={form.data.role_id} onChange={(event) => form.setData('role_id', event.target.value)}>{roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}</Input></Field>
                <Field error={form.errors.branch_id} label={t("Branch")}><Input select className={inputClass} value={form.data.branch_id} onChange={(event) => form.setData('branch_id', event.target.value)}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</Input></Field>
                <Button icon="invite" loading={form.processing} type="submit">{t("Invite person")}</Button>
            </form>
            <form className="mb-4 space-y-2" onSubmit={(event) => { event.preventDefault(); csv.post('/settings/users/import'); }}>
                <Field error={csv.errors.csv} label={t("CSV import")}><Input multiline className={inputClass} rows={3} value={csv.data.csv} onChange={(event) => csv.setData('csv', event.target.value)} /></Field>
                <Button icon="import" loading={csv.processing} type="submit" tone="soft">{t("Import people")}</Button>
            </form>
            <Card className="mb-4 divide-y divide-hairline text-sm">
                {users.map((user) => <Link key={user.id} href={`/settings/users/${user.id}`} className="flex justify-between px-4 py-3"><span>{user.name}<span className="block text-muted">{user.email}</span></span><span className="rounded bg-sticker-purple px-2">{user.status}</span></Link>)}
            </Card>
            <p className="mb-2 text-xs text-muted">{assignments.length} {t("role assignments")}</p>
        </Shell>
    );
}
