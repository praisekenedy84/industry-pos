import { useT } from '@/lib/ui';
import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { IconButton, InputAdornment, Tooltip } from '@mui/material';
import { Eye, EyeOff } from 'lucide-react';
import { AuthCard, Button, Errors, Field, Input } from '@/lib/ui';

export default function Login() {
    const t = useT();
    const [showPassword, setShowPassword] = useState(false);
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
                    <Input type={showPassword ? 'text' : 'password'} autoComplete="current-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} slotProps={{ input: { endAdornment: <InputAdornment position="end"><Tooltip title={showPassword ? 'Hide password' : 'Show password'}><IconButton aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword} onClick={() => setShowPassword((value) => !value)} onMouseDown={(event) => event.preventDefault()} edge="end">{showPassword ? <EyeOff size={18} /> : <Eye size={18} />}</IconButton></Tooltip></InputAdornment> } }} />
                </Field>
                <Button icon="login" type="submit" fullWidth loading={form.processing}>{t("Sign in")}</Button>
            </form>
        </AuthCard>
    );
}
