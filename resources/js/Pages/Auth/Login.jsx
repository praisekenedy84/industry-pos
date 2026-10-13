import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { IconButton, InputAdornment, Tooltip } from '@mui/material';
import { Eye, EyeOff } from 'lucide-react';
import { AuthCard, Button, Errors, Field, inputClass } from '@/lib/ui';

export default function Login({ action = '/login', workspace = null }) {
    const t = useT();
    const [code, setCode] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const form = useForm({
        email: '',
        username: '',
        password: '',
        workspace_code: workspace?.slug || '',
        remember_device: false,
        force: false,
    });

    return (
        <AuthCard title={workspace ? `Sign in to ${workspace.name}` : t("Sign in")}>
            <form className="space-y-5" onSubmit={(event) => { event.preventDefault(); form.post(action); }}>
                <Errors errors={form.errors} />
                {code && !workspace && (
                    <Field label={t("Workspace code")} error={form.errors.workspace_code}>
                        <Input className={inputClass} autoComplete="organization" required value={form.data.workspace_code} onChange={(event) => form.setData('workspace_code', event.target.value)} />
                    </Field>
                )}
                {code ? (
                    <Field label={t("Username")} error={form.errors.email}>
                        <Input className={inputClass} autoComplete="username" required value={form.data.username} onChange={(event) => form.setData('username', event.target.value)} />
                    </Field>
                ) : (
                    <Field label={t("Email or phone")} error={form.errors.email}>
                        <Input className={inputClass} autoComplete="username" required value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} />
                    </Field>
                )}
                <Field error={form.errors.password} label={t("Password")}>
                    <Input className={inputClass} type={showPassword ? 'text' : 'password'} autoComplete="current-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} slotProps={{ input: { endAdornment: <InputAdornment position="end"><Tooltip title={showPassword ? 'Hide password' : 'Show password'}><IconButton aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword} onClick={() => setShowPassword((value) => !value)} onMouseDown={(event) => event.preventDefault()} edge="end">{showPassword ? <EyeOff size={18} /> : <Eye size={18} />}</IconButton></Tooltip></InputAdornment> } }} />
                </Field>
                <label className="flex items-center gap-2 text-sm">
                    <Input type="checkbox" checked={form.data.remember_device} onChange={(event) => form.setData('remember_device', event.target.checked)} />
                    {t("Remember this device")}
                </label>
                <Button icon="login" type="submit" fullWidth loading={form.processing}>{t("Sign in")}</Button>
                {form.errors.concurrent && (
                    <Button icon="logout" onClick={() => { form.transform((data) => ({ ...data, force: true })); form.post(action); }}>{t("Sign out the other device and continue")}</Button>
                )}
            </form>
            <p className="mt-4 text-sm">
                <Button icon="key" tone="ghost" onClick={() => setCode((value) => !value)}>{code ? 'Use email or phone' : 'Use workspace code'}</Button>
            </p>
            <p className="mt-2 text-sm"><Button icon="key" component={Link} tone="ghost" href="/forgot-password">{t("Forgot password?")}</Button></p>
        </AuthCard>
    );
}
