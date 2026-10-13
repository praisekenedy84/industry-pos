import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { router, useForm } from '@inertiajs/react';
import { Button, Card, Field, inputClass, Shell } from '@/lib/ui';

export default function UserShow({ person, assignments, sessions, activity, roles, branches, effective }) {
    const t = useT();
    const form = useForm({ name: person.name, phone: person.phone || '', job_title: person.job_title || '', employee_code: person.employee_code || '', role_id: assignments[0]?.role_id || '', branch_id: assignments[0]?.branch_id || '', pin: '' });
    return (
        <Shell title={person.name} actions={<><Button icon="suspend" tone="ghost" onClick={() => router.post(`/settings/users/${person.id}/suspend`)}>{t("Suspend")}</Button><Button icon="suspend" tone="danger" onClick={() => router.post(`/settings/users/${person.id}/deactivate`)}>{t("Deactivate")}</Button><Button icon="logout" tone="soft" onClick={() => router.post(`/settings/users/${person.id}/sessions`)}>{t("Sign out sessions")}</Button></>}>
            <form className="mb-4 grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.put(`/settings/users/${person.id}`); }}>
                <Field error={form.errors.name} label={t("Name")}><Input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <Field error={form.errors.phone} label={t("Phone")}><Input className={inputClass} value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} /></Field>
                <Field error={form.errors.job_title} label={t("Job title")}><Input className={inputClass} value={form.data.job_title} onChange={(event) => form.setData('job_title', event.target.value)} /></Field>
                <Field error={form.errors.pin} label="PIN"><Input className={inputClass} value={form.data.pin} onChange={(event) => form.setData('pin', event.target.value)} /></Field>
                <Field error={form.errors.role_id} label={t("Role")}><Input select className={inputClass} value={form.data.role_id} onChange={(event) => form.setData('role_id', event.target.value)}>{roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}</Input></Field>
                <Field error={form.errors.branch_id} label={t("Branch")}><Input select className={inputClass} value={form.data.branch_id} onChange={(event) => form.setData('branch_id', event.target.value)}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</Input></Field>
                <Button icon="save" loading={form.processing} type="submit">{t("Save")}</Button>
            </form>
            <div className="grid gap-4 md:grid-cols-2">
                <Card className="p-4 text-sm"><h2 className="font-medium">{t("Sessions")}</h2>{sessions.map((session) => <p key={session.id}>{session.ip} · {session.last_active_at}</p>)}</Card>
                <Card className="p-4 text-sm"><h2 className="font-medium">{t("Recent activity")}</h2>{activity.map((row) => <p key={row.id}>{row.action}</p>)}</Card>
            </div>
            <Card className="mt-4 max-h-64 overflow-auto p-4 text-xs">
                {effective.filter((row) => row.allowed).map((row) => <span key={row.key} className="mr-2 inline-block rounded bg-sticker-green px-2 py-1">{row.key}</span>)}
            </Card>
        </Shell>
    );
}
