import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { AuthCard, Button, Errors, Field, inputClass } from '@/lib/ui';

export default function Pin() {
    const t = useT();
    const form = useForm({ pin: '' });
    return (
        <AuthCard title={t("Staff PIN")}>
            <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); form.post('/pin'); }}>
                <Errors errors={form.errors} />
                <Field error={form.errors.pin} label={t("4–6 digits")}><Input className={inputClass} inputMode="numeric" value={form.data.pin} onChange={(event) => form.setData('pin', event.target.value)} autoFocus /></Field>
                <Button icon="unlock" loading={form.processing} type="submit">{t("Unlock")}</Button>
            </form>
        </AuthCard>
    );
}
