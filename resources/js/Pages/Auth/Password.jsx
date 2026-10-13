import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { AuthCard, Button, Field, inputClass } from '@/lib/ui';

export default function Password({ action }) {
    const t = useT();
    const form = useForm({ password: '', password_confirmation: '' });

    return (
        <AuthCard title={t("Change your password")}>
            <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); form.post(action); }}>
                <Field label={t("New password")} error={form.errors.password}>
                    <Input className={inputClass} type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} />
                </Field>
                <Field error={form.errors.password_confirmation} label={t("Confirm password")}>
                    <Input className={inputClass} type="password" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} />
                </Field>
                <Button icon="save" loading={form.processing} type="submit" disabled={form.processing}>{t("Save password")}</Button>
            </form>
        </AuthCard>
    );
}
