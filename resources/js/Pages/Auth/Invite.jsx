import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { AuthCard, Button, Errors, Field, inputClass } from '@/lib/ui';

export default function Invite({ email, name, token }) {
    const t = useT();
    const form = useForm({ password: '', password_confirmation: '' });
    return (
        <AuthCard title={t("Accept invite")}>
            <p className="mb-3 text-sm text-muted">{email}</p>
            <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); form.post(`/invite/${token}`); }}>
                <Errors errors={form.errors} />
                <p className="text-sm">{name}</p>
                <Field error={form.errors.password} label={t("Password")}><Input className={inputClass} type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} /></Field>
                <Field error={form.errors.password_confirmation} label={t("Confirm password")}><Input className={inputClass} type="password" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} /></Field>
                <Button icon="invite" loading={form.processing} type="submit">{t("Join workspace")}</Button>
            </form>
        </AuthCard>
    );
}
