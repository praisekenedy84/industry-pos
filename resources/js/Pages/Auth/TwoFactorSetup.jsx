import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { AuthCard, Button, Errors, Field, inputClass } from '@/lib/ui';

export default function TwoFactorSetup({ secret, uri, action = '/two-factor/setup' }) {
    const t = useT();
    const form = useForm({ code: '' });

    return (
        <AuthCard title={t("Set up an authenticator")}>
            <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); form.post(action); }}>
                <p className="text-sm text-stone-600">{t("Add this key to your authenticator app, then enter the 6-digit code.")}</p>
                <p className="break-all font-mono text-sm">{secret}</p>
                <a className="block break-all text-sm underline" href={uri}>{t("Open in your authenticator")}</a>
                <Errors errors={form.errors} />
                <Field label={t("Code")} error={form.errors.code}>
                    <Input className={inputClass} inputMode="numeric" autoComplete="one-time-code" value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} autoFocus />
                </Field>
                <Button icon="confirm" loading={form.processing} type="submit">{t("Confirm")}</Button>
            </form>
        </AuthCard>
    );
}
