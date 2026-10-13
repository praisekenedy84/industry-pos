import { useT } from '@/lib/ui';
import { useForm, usePage } from '@inertiajs/react';
import { AuthCard, Button, Errors, Field, Input } from '@/lib/ui';

export default function Login() {
    const t = useT();
    const form = useForm({ email: '', password: '' });
    const action = usePage().url.startsWith('/admin') ? '/admin/login' : '/platform/login';

    return (
        <AuthCard title={t("Platform sign in")} kicker="Platform console">
            <form className="space-y-5" onSubmit={(event) => { event.preventDefault(); form.post(action); }}>
                <Errors errors={form.errors} />
                <Field label={t("Email")} error={form.errors.email}>
                    <Input type="email" autoComplete="username" required value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} />
                </Field>
                <Field label={t("Password")} error={form.errors.password}>
                    <Input type="password" autoComplete="current-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} />
                </Field>
                <Button icon="login" type="submit" fullWidth loading={form.processing}>{t("Sign in")}</Button>
            </form>
        </AuthCard>
    );
}
