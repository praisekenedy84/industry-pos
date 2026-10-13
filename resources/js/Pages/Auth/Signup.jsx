import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { AuthCard, Button, Errors, Field, inputClass } from '@/lib/ui';

export default function Signup({ templates }) {
    const t = useT();
    const form = useForm({ name: '', slug: '', owner_name: '', owner_email: '', owner_password: '', industry_key: templates.some((template) => template.key === 'bakery') ? 'bakery' : (templates[0]?.key || 'general') });
    return (
        <AuthCard title={t("Create a workspace")}>
            <p className="mb-3 text-sm text-muted">{t("You can use the workspace for 3 days. After that, an admin must approve it. Call +255743463710 or +255715148503, or email info@mernettechnologies.com.")}</p>
            <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); form.post('/signup'); }}>
                <Errors errors={form.errors} />
                <Field error={form.errors.name} label={t("Business name")}><Input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <Field error={form.errors.slug} label={t("Workspace address")}><Input className={inputClass} value={form.data.slug} onChange={(event) => form.setData('slug', event.target.value.toLowerCase())} placeholder="acme" /></Field>
                <Field error={form.errors.industry_key} label={t("Industry")}>
                    <Input select className={inputClass} value={form.data.industry_key} onChange={(event) => form.setData('industry_key', event.target.value)}>
                        {templates.map((template) => <option key={template.key} value={template.key}>{template.name}</option>)}
                    </Input>
                </Field>
                <Field error={form.errors.owner_name} label={t("Your name")}><Input className={inputClass} value={form.data.owner_name} onChange={(event) => form.setData('owner_name', event.target.value)} /></Field>
                <Field error={form.errors.owner_email} label={t("Email")}><Input className={inputClass} type="email" value={form.data.owner_email} onChange={(event) => form.setData('owner_email', event.target.value)} /></Field>
                <Field error={form.errors.owner_password} label={t("Password")}><Input className={inputClass} type="password" value={form.data.owner_password} onChange={(event) => form.setData('owner_password', event.target.value)} /></Field>
                <Button icon="add" loading={form.processing} type="submit" disabled={form.processing}>{t("Create workspace")}</Button>
            </form>
        </AuthCard>
    );
}
