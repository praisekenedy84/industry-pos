import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Alert, Box, Chip, Dialog, DialogActions, DialogContent, DialogTitle, Stack, Typography } from '@mui/material';
import { Plus } from 'lucide-react';
import { Button, Card, Errors, Field, Input, money, Pager, rowsOf, Shell, Table, useT } from '@/lib/ui';
import { RankingChart } from '@/lib/charts';

export default function Index({ expenses, total, breakdown = [], filters, categories, methods, today, can }) {
    const t = useT();
    const { errors } = usePage().props;
    const [open, setOpen] = useState(false);
    const [voidTarget, setVoidTarget] = useState(null);
    const form = useForm({ request_id: crypto.randomUUID(), expense_date: today, category: 'utilities', description: '', payee: '', amount: '', payment_method: methods[0]?.key || '', reference: '' });
    const voidForm = useForm({ reason: '' });
    const method = methods.find((item) => item.key === form.data.payment_method);
    return <Shell title={t('Expenses')} actions={can.create && <Button startIcon={<Plus size={17} />} onClick={() => setOpen(true)}>{t('New expense')}</Button>}>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>{t('Record paid operating costs for the selected branch. Stock purchases and production costs belong in their existing workflows.')}</Typography>
        <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', lg: '1fr 2fr' }, gap: 3, mb: 3 }}>
            <Card sx={{ p: 3 }}><Typography variant="body2" color="text.secondary">{t('Recorded expenses')}</Typography><Typography variant="h4" sx={{ mt: 2 }}>{money(total)}</Typography><Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>{filters.from} – {filters.to} · {t('Voided entries excluded')}</Typography></Card>
            <Card>{breakdown.length ? <RankingChart rows={breakdown.map((row) => ({ name: t(categories[row.category] || row.category), gross: row.amount }))} formatValue={money} title={t('Expenses by category')} caption={t('Paid operating expenses · TZS')} /> : <Typography variant="body2" color="text.secondary" sx={{ p: 3 }}>{t('No recorded expenses for these filters.')}</Typography>}</Card>
        </Box>
        {!open && !voidTarget && <Errors errors={errors} />}
        <Card>
            <Stack component="form" direction="row" sx={{ p: 2.5, gap: 2, flexWrap: 'wrap', alignItems: 'flex-start' }} onSubmit={(event) => { event.preventDefault(); router.get('/expenses', Object.fromEntries(new FormData(event.currentTarget)), { preserveState: true }); }}>
                <Input label={t('From date')} type="date" name="from" defaultValue={filters.from} />
                <Input label={t('To date')} type="date" name="to" defaultValue={filters.to} />
                <Input select label={t('Category')} name="category" defaultValue={filters.category} sx={{ width: { xs: '100%', sm: 224 } }}><option value="">{t('All categories')}</option>{Object.entries(categories).map(([key, label]) => <option key={key} value={key}>{t(label)}</option>)}</Input>
                <Input select label={t('Status')} name="status" defaultValue={filters.status} sx={{ width: { xs: '100%', sm: 160 } }}><option value="recorded">{t('Recorded')}</option><option value="void">{t('Voided')}</option></Input>
                <Button icon="filter" type="submit" tone="soft">{t('Apply filters')}</Button>
            </Stack>
            <Table rows={rowsOf(expenses)} empty={t('No expenses match these filters.')} columns={[
                { key: 'number', label: t('Reference') }, { key: 'expense_date', label: t('Paid on'), render: (row) => row.expense_date.slice(0, 10) },
                { key: 'description', label: t('Description'), render: (row) => <Box sx={{ minWidth: 180 }}><Typography variant="body2">{row.description}</Typography><Typography variant="caption" color="text.secondary">{row.payee || '—'}{row.void_reason && ` · ${row.void_reason}`}</Typography></Box> },
                { key: 'category', label: t('Category'), render: (row) => t(categories[row.category] || row.category) },
                { key: 'payment_method', label: t('Payment'), render: (row) => <Box>{methods.find((item) => item.key === row.payment_method)?.name || row.payment_method}<Typography variant="caption" sx={{ display: 'block' }}>{row.reference}</Typography></Box> },
                { key: 'amount', label: t('Amount'), render: (row) => <Typography variant="body2" sx={{ whiteSpace: 'nowrap', fontWeight: 600 }}>{money(row.amount)}</Typography> },
                { key: 'status', label: t('Status'), render: (row) => <Chip size="small" variant="outlined" color={row.status === 'void' ? 'default' : 'success'} label={t(row.status === 'void' ? 'Voided' : 'Recorded')} /> },
                { key: 'actions', label: t('Actions'), render: (row) => row.can_void && row.status === 'recorded' ? <Button icon="suspend" tone="ghost" color="error" onClick={() => { voidForm.reset(); voidForm.clearErrors(); setVoidTarget(row); }}>{t('Void expense')}</Button> : '—' },
            ]} />
            <Pager paginator={expenses} />
        </Card>
        <Dialog open={open} onClose={() => { if (!form.processing) setOpen(false); }} aria-labelledby="expense-title" fullWidth maxWidth="sm">
            <Box component="form" onSubmit={(event) => { event.preventDefault(); form.post('/expenses', { preserveScroll: true, onSuccess: () => { setOpen(false); form.reset(); form.setData('request_id', crypto.randomUUID()); } }); }}>
                <DialogTitle id="expense-title">{t('Record expense')}</DialogTitle>
                <DialogContent><Stack sx={{ gap: 2.5, pt: 1 }}>
                    <Alert severity="info">{t('Enter the full amount paid in TZS. This records an operating expense; input VAT is not calculated.')}</Alert>
                    <Errors errors={form.errors} />
                    <Field label={t('Description')} error={form.errors.description}><Input autoFocus required value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} /></Field>
                    <Stack direction={{ xs: 'column', sm: 'row' }} sx={{ gap: 2 }}>
                        <Field label={t('Paid on')} error={form.errors.expense_date}><Input type="date" required max={today} value={form.data.expense_date} onChange={(event) => form.setData('expense_date', event.target.value)} /></Field>
                        <Field label={t('Amount (TZS)')} error={form.errors.amount}><Input type="number" required min="0.01" step="0.01" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} /></Field>
                    </Stack>
                    <Field label={t('Category')} error={form.errors.category}><Input select value={form.data.category} onChange={(event) => form.setData('category', event.target.value)}>{Object.entries(categories).map(([key, label]) => <option key={key} value={key}>{t(label)}</option>)}</Input></Field>
                    <Field label={t('Payee (optional)')} error={form.errors.payee}><Input value={form.data.payee} onChange={(event) => form.setData('payee', event.target.value)} /></Field>
                    <Field label={t('Payment method')} error={form.errors.payment_method}><Input select required value={form.data.payment_method} onChange={(event) => form.setData('payment_method', event.target.value)}><option value="" disabled>{t('Choose a payment method')}</option>{methods.map((item) => <option key={item.key} value={item.key}>{item.name}</option>)}</Input></Field>
                    <Field label={t(method?.requires_reference ? 'Payment reference' : 'Payment reference (optional)')} error={form.errors.reference}><Input required={Boolean(method?.requires_reference)} value={form.data.reference} onChange={(event) => form.setData('reference', event.target.value)} /></Field>
                </Stack></DialogContent>
                <DialogActions><Button icon="cancel" tone="ghost" disabled={form.processing} onClick={() => setOpen(false)}>{t('Cancel')}</Button><Button icon="add" type="submit" loading={form.processing} disabled={!methods.length}>{t('Record expense')}</Button></DialogActions>
            </Box>
        </Dialog>
        <Dialog open={Boolean(voidTarget)} onClose={() => { if (!voidForm.processing) setVoidTarget(null); }} aria-labelledby="void-expense-title">
            <Box component="form" onSubmit={(event) => { event.preventDefault(); voidForm.post(`/expenses/${voidTarget.id}/void`, { preserveScroll: true, onSuccess: () => setVoidTarget(null) }); }}>
                <DialogTitle id="void-expense-title">{t('Void expense')} {voidTarget?.number}</DialogTitle>
                <DialogContent><Typography variant="body2" sx={{ mb: 3 }}>{t('The original record remains in the audit trail and will be excluded from expense and profit totals. Record a new expense if you need to correct its details.')}</Typography><Field label={t('Reason')} error={voidForm.errors.reason}><Input autoFocus multiline required minRows={2} value={voidForm.data.reason} onChange={(event) => voidForm.setData('reason', event.target.value)} /></Field></DialogContent>
                <DialogActions><Button icon="cancel" tone="ghost" disabled={voidForm.processing} onClick={() => setVoidTarget(null)}>{t('Cancel')}</Button><Button icon="suspend" tone="danger" type="submit" loading={voidForm.processing}>{t('Void expense')}</Button></DialogActions>
            </Box>
        </Dialog>
    </Shell>;
}
