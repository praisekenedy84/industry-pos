import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { AuthCard, Button, Errors, Field, inputClass } from '@/lib/ui';

export default function TwoFactor({ action = '/two-factor' }) {
    const t = useT();
    const form = useForm({ code: '' });
    return (
        <AuthCard title={t("Two-factor code")}>
            <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); form.post(action); }}>
                <Errors errors={form.errors} />
                <Field error={form.errors.code} label={t("Authenticator or recovery code")}><Input className={inputClass} value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} autoFocus /></Field>
                <Button icon="next" loading={form.processing} type="submit">{t("Continue")}</Button>
            </form>
        </AuthCard>
    );
}
