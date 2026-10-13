import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { AuthCard, Button, Field, inputClass } from '@/lib/ui';

export default function ForgotPassword() {
    const t = useT();
    const form = useForm({ email: '' });

    return (
        <AuthCard title={t("Reset password")}>
            <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); form.post('/forgot-password'); }}>
                <Field label={t("Email or phone")} error={form.errors.email}>
                    <Input className={inputClass} value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} />
                </Field>
                <Button icon="mail" loading={form.processing} type="submit" disabled={form.processing}>{t("Send reset link")}</Button>
            </form>
        </AuthCard>
    );
}
