import { useT } from '@/lib/ui';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Alert, Box, Chip, Divider, FormControlLabel, Stack, Switch, Typography } from '@mui/material';
import { Button, Card, ConfirmButton, Csrf, Errors, Field, Input, Shell, useConsoleBase, words } from '@/lib/ui';

const statusColor = {
    active: 'success',
    trial: 'info',
    suspended: 'warning',
    pending_deletion: 'error',
};

export default function TenantShow({ tenant, features = [], limits = [], users = [], roles = [], branches = [], impersonations = [], templates = [] }) {
    const t = useT();
    const base = useConsoleBase();
    const profile = useForm({
        name: tenant.name,
        default_locale: tenant.default_locale || 'en',
        receipt_language: tenant.receipt_language || 'bilingual',
        accent_color: tenant.accent_color || '#5745de',
        industry_key: tenant.industry_key || 'general',
    });
    const [rows, setRows] = useState(features.map((feature) => ({
        key: feature.key,
        description: feature.description,
        enabled: feature.enabled,
        globally_enabled: feature.globally_enabled,
        expires_at: feature.expires_at || '',
    })));
    const [savingFeatures, setSavingFeatures] = useState(false);
    const limitForm = useForm({ limits: Object.fromEntries(limits.map((row) => [row.limit_key, row.value])) });
    const impersonate = useForm({ user_id: users[0]?.id || '', reason: '', password: '' });
    const person = useForm({ name: '', email: '', phone: '', password: '', role_id: roles[0]?.id || '', branch_id: branches[0]?.id || '' });
    const trial = tenant.status === 'trial';
    const paused = tenant.status !== 'active';
    const tenantPath = `${base}/tenants/${tenant.id}`;

    function setFeature(index, enabled) {
        setRows(rows.map((item, itemIndex) => (itemIndex === index ? { ...item, enabled } : item)));
    }

    return (
        <Shell title={tenant.name} actions={<>
            <Box component="form" method="post" action={`${tenantPath}/export`} sx={{ display: 'inline-flex' }}><Csrf /><Button icon="export" type="submit" tone="soft">{t("Export")}</Button></Box>
            {trial
                ? <Button icon="approve" tone="soft" onClick={() => router.post(`${tenantPath}/activate`)}>{t("Approve")}</Button>
                : paused
                    ? <Button icon="start" tone="soft" onClick={() => router.post(`${tenantPath}/activate`)}>{t("Activate")}</Button>
                    : <ConfirmButton icon="suspend" tone="danger" title={`Suspend ${tenant.name}?`} description={t("People in this workspace are signed out. Their data stays in place.")} onConfirm={() => router.post(`${tenantPath}/suspend`, { reason: 'Suspended from platform' })}>{t("Suspend")}</ConfirmButton>}
        </>}>
            <Stack direction="row" spacing={1.5} sx={{ mt: -1, mb: 3, alignItems: 'center', flexWrap: 'wrap' }}>
                <Typography variant="body2" color="text.secondary">{`/w/${tenant.slug}/`}</Typography>
                <Chip label={tenant.awaiting_approval ? t("Needs approval") : words(tenant.status)} size="small" color={tenant.awaiting_approval ? 'warning' : (statusColor[tenant.status] || 'default')} variant="outlined" sx={{ textTransform: 'capitalize' }} />
                {trial && tenant.trial_ends_at && <Typography variant="body2" color="text.secondary">{t("Trial ends")} {when(tenant.trial_ends_at)}. {t("After that, an admin must approve it. Call +255743463710 or +255715148503, or email info@mernettechnologies.com.")}</Typography>}
                {tenant.industry_key && <Chip label={templates.find((template) => template.key === tenant.industry_key)?.name || words(tenant.industry_key)} size="small" variant="outlined" />}
            </Stack>
            {tenant.suspended_reason && <Alert severity={paused ? 'warning' : 'info'} sx={{ mb: 3 }}>{tenant.suspended_reason}</Alert>}
            <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', lg: '1fr 1fr' }, gap: 3, alignItems: 'start' }}>
                <Card component="form" onSubmit={(event) => { event.preventDefault(); profile.put(tenantPath); }} sx={{ p: 3 }}>
                    <Typography component="h2" variant="h6">{t("Profile")}</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 3 }}>{t("Name, language, and the accent shown in this workspace.")}</Typography>
                    <Errors errors={profile.errors} />
                    <Stack spacing={3}>
                        <Field label={t("Name")} error={profile.errors.name}><Input required value={profile.data.name} onChange={(event) => profile.setData('name', event.target.value)} /></Field>
                        <Field label={t("Industry template")} error={profile.errors.industry_key}><Input select value={profile.data.industry_key} onChange={(event) => profile.setData('industry_key', event.target.value)} helperText={t("Attaches this industry's wording to the workspace. The flow does not change.")}>{templates.map((template) => <option key={template.key} value={template.key}>{template.name}</option>)}</Input></Field>
                        <Field label={t("Workspace language")} error={profile.errors.default_locale}><Input select value={profile.data.default_locale} onChange={(event) => profile.setData('default_locale', event.target.value)}><option value="en">{t("English")}</option><option value="sw">{t("Kiswahili")}</option></Input></Field>
                        <Field label={t("Receipt language")} error={profile.errors.receipt_language}><Input select value={profile.data.receipt_language} onChange={(event) => profile.setData('receipt_language', event.target.value)}><option value="en">{t("English")}</option><option value="sw">{t("Kiswahili")}</option><option value="bilingual">{t("Bilingual")}</option></Input></Field>
                        <Field label={t("Accent color")} error={profile.errors.accent_color}><Input required value={profile.data.accent_color} onChange={(event) => profile.setData('accent_color', event.target.value)} helperText={t("Hex color, for example #5745de.")} /></Field>
                        <Stack direction="row" sx={{ justifyContent: 'flex-end' }}><Button icon="save" type="submit" loading={profile.processing}>{t("Save profile")}</Button></Stack>
                    </Stack>
                </Card>
                <Card component="form" onSubmit={(event) => { event.preventDefault(); limitForm.put(`${tenantPath}/limits`); }} sx={{ p: 3 }}>
                    <Typography component="h2" variant="h6">{t("Limits")}</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 3 }}>{t("Caps for branches, people, items, and transactions.")}</Typography>
                    <Errors errors={limitForm.errors} />
                    {limits.length ? <Stack spacing={3}>
                        {limits.map((row) => <Field key={row.limit_key} label={words(row.limit_key)} error={limitForm.errors[`limits.${row.limit_key}`]}><Input type="number" min="0" value={limitForm.data.limits[row.limit_key]} onChange={(event) => limitForm.setData('limits', { ...limitForm.data.limits, [row.limit_key]: event.target.value })} /></Field>)}
                        <Stack direction="row" sx={{ justifyContent: 'flex-end' }}><Button icon="save" type="submit" loading={limitForm.processing}>{t("Save limits")}</Button></Stack>
                    </Stack> : <Typography variant="body2" color="text.secondary">{t("No limits are configured for this workspace.")}</Typography>}
                </Card>
                <Card sx={{ p: 3, gridColumn: { lg: '1 / -1' } }}>
                    <Typography component="h2" variant="h6">{t("Features")}</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 2 }}>{t("Turn modules on or off for this workspace only.")}</Typography>
                    {rows.length ? <>
                        <Stack divider={<Divider flexItem />}>
                            {rows.map((row, index) => (
                                <FormControlLabel
                                    key={row.key}
                                    sx={{ mx: 0, py: 1.25, width: '100%', alignItems: 'center', justifyContent: 'space-between', '& .MuiFormControlLabel-label': { flex: 1, minWidth: 0 } }}
                                    labelPlacement="start"
                                    control={<Switch checked={row.enabled} disabled={row.globally_enabled === false} onChange={(event) => setFeature(index, event.target.checked)} />}
                                    label={<Box sx={{ pr: 2 }}><Typography variant="body2" sx={{ textTransform: 'capitalize' }}>{words(row.key)}</Typography>{row.description && <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{row.description}</Typography>}{row.globally_enabled === false && <Typography variant="caption" color="warning.main" sx={{ display: 'block' }}>{t("Turned off for every workspace.")}</Typography>}</Box>}
                                />
                            ))}
                        </Stack>
                        <Stack direction="row" sx={{ justifyContent: 'flex-end', mt: 2 }}><Button icon="save" loading={savingFeatures} onClick={() => router.put(`${tenantPath}/features`, { features: rows.map(({ key, enabled, expires_at }) => ({ key, enabled, expires_at: expires_at || null })) }, { onStart: () => setSavingFeatures(true), onFinish: () => setSavingFeatures(false) })}>{t("Save features")}</Button></Stack>
                    </> : <Typography variant="body2" color="text.secondary">{t("No features are defined yet.")}</Typography>}
                </Card>
                <Card component="form" onSubmit={(event) => { event.preventDefault(); person.post(`${tenantPath}/users`); }} sx={{ p: 3, gridColumn: { lg: '1 / -1' } }}>
                    <Typography component="h2" variant="h6">{t("People")}</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 3 }}>{t("Add someone who can sign in to this workspace with the email and password you set.")}</Typography>
                    {users.length > 0 && <Stack spacing={1} sx={{ mb: 3 }}>{users.map((user) => <Typography key={user.id} variant="body2">{user.name} · {user.email || t("No email")} · {words(user.status)}</Typography>)}</Stack>}
                    <Errors errors={person.errors} />
                    {roles.length && branches.length ? <Stack spacing={3}>
                        <Field label={t("Name")} error={person.errors.name}><Input required value={person.data.name} onChange={(event) => person.setData('name', event.target.value)} /></Field>
                        <Field label={t("Email")} error={person.errors.email}><Input type="email" required value={person.data.email} onChange={(event) => person.setData('email', event.target.value)} /></Field>
                        <Field label={t("Phone")} error={person.errors.phone}><Input value={person.data.phone} onChange={(event) => person.setData('phone', event.target.value)} /></Field>
                        <Field label={t("Password")} error={person.errors.password}><Input type="password" autoComplete="new-password" required value={person.data.password} onChange={(event) => person.setData('password', event.target.value)} helperText={t("At least 10 characters. Share it with them directly.")} /></Field>
                        <Field label={t("Role")} error={person.errors.role_id}><Input select required value={person.data.role_id} onChange={(event) => person.setData('role_id', event.target.value)}>{roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}</Input></Field>
                        <Field label={t("Branch")} error={person.errors.branch_id}><Input select required value={person.data.branch_id} onChange={(event) => person.setData('branch_id', event.target.value)}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</Input></Field>
                        <Stack direction="row" sx={{ justifyContent: 'flex-end' }}><Button icon="add" type="submit" loading={person.processing}>{t("Add person")}</Button></Stack>
                    </Stack> : <Typography variant="body2" color="text.secondary">{t("This workspace is not ready for people yet.")}</Typography>}
                </Card>
                <Card component="form" onSubmit={(event) => { event.preventDefault(); impersonate.post(`${tenantPath}/impersonate`); }} sx={{ p: 3, gridColumn: { lg: '1 / -1' } }}>
                    <Typography component="h2" variant="h6">{t("Sign in as support")}</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 3 }}>{t("Open this workspace as one of its people. The owner is notified, and the session expires on its own.")}</Typography>
                    <Errors errors={impersonate.errors} />
                    {users.length ? <Stack spacing={3}>
                        <Field label={t("Person")} error={impersonate.errors.user_id}><Input select value={impersonate.data.user_id} onChange={(event) => impersonate.setData('user_id', event.target.value)}>{users.map((user) => <option key={user.id} value={user.id}>{user.name} · {user.email}</option>)}</Input></Field>
                        <Field label={t("Reason")} error={impersonate.errors.reason}><Input required value={impersonate.data.reason} onChange={(event) => impersonate.setData('reason', event.target.value)} helperText={t("At least 8 characters. This is stored with the session.")} /></Field>
                        <Field label={t("Your password")} error={impersonate.errors.password}><Input type="password" autoComplete="current-password" required value={impersonate.data.password} onChange={(event) => impersonate.setData('password', event.target.value)} /></Field>
                        <Stack direction="row" sx={{ justifyContent: 'flex-end' }}><Button icon="login" type="submit" loading={impersonate.processing}>{t("Start impersonation")}</Button></Stack>
                    </Stack> : <Typography variant="body2" color="text.secondary">{t("This workspace has no people to sign in as.")}</Typography>}
                </Card>
                <Card sx={{ p: 3, gridColumn: { lg: '1 / -1' } }}>
                    <Typography component="h2" variant="h6">{t("Recent support sessions")}</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 2 }}>{t("The latest impersonation sessions for this workspace.")}</Typography>
                    {impersonations.length ? <Stack spacing={1.5}>{impersonations.map((log) => <Box key={log.id} sx={{ py: 1.25, borderTop: 1, borderColor: 'divider' }}><Typography variant="body2" sx={{ fontWeight: 600 }}>{log.reason}</Typography><Typography variant="caption" color="text.secondary">{when(log.started_at)}{log.expires_at ? ` · ends ${when(log.expires_at)}` : ''}</Typography></Box>)}</Stack> : <Typography variant="body2" color="text.secondary">{t("No support sessions yet.")}</Typography>}
                </Card>
            </Box>
        </Shell>
    );
}

function when(value) {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
}
