import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Box, Chip, Dialog, DialogActions, DialogContent, DialogTitle, Stack, Typography } from '@mui/material';
import { Button, Card, Errors, Field, Input, money, Pager, rowsOf, Shell, Table, useT } from '@/lib/ui';

export default function Sales({ sales, total, filters, sellers, ownOnly, approvers = [], can }) {
    const t = useT();
    const [editTarget, setEditTarget] = useState(null);
    const [voidTarget, setVoidTarget] = useState(null);
    const editForm = useForm({ notes: '' });
    const voidForm = useForm({ reason: '', approved_by: '' });
    return <Shell title={t('Sales')} actions={can.create && <Button component={Link} href="/pos" icon="add">{t('New sale')}</Button>}>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>{t('Completed sales for the selected branch. Choose a day and, when you can see other people, a seller.')}</Typography>
        <Card sx={{ p: 3, mb: 3 }}><Typography variant="body2" color="text.secondary">{t('Completed sales')}</Typography><Typography variant="h4" sx={{ mt: 2 }}>{money(total)}</Typography><Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>{filters.date} · {t('Voided sales stay in the list and are left out of this total')}</Typography></Card>
        <Card>
            <Stack component="form" direction={{ xs: 'column', md: 'row' }} sx={{ p: 2.5, gap: 2, flexWrap: 'wrap' }} onSubmit={(event) => { event.preventDefault(); router.get('/pos/sales', Object.fromEntries(new FormData(event.currentTarget)), { preserveState: true }); }}>
                <Input label={t('Day')} type="date" name="date" defaultValue={filters.date} />
                <Input select label={t('Seller')} name="seller" defaultValue={filters.seller} disabled={ownOnly} sx={{ minWidth: 220 }}><option value="">{t('All sellers')}</option>{sellers.map((seller) => <option key={seller.id} value={seller.id}>{seller.name}</option>)}</Input>
                <Button icon="filter" type="submit" tone="soft">{t('Apply filters')}</Button>
            </Stack>
            <Table rows={rowsOf(sales)} empty={t('No sales for this day.')} columns={[
                { key: 'number', label: t('Sale'), render: (row) => <Box sx={{ minWidth: 140 }}><Button component={Link} href={`/pos/sales/${row.id}`} tone="ghost">{row.number}</Button>{row.notes && <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{row.notes}</Typography>}{row.void_reason && <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{row.void_reason}</Typography>}</Box> },
                { key: 'sold_at', label: t('Time') },
                { key: 'seller', label: t('Seller'), render: (row) => row.seller || t('Unavailable') },
                { key: 'customer', label: t('Customer'), render: (row) => row.customer || t('Walk-in') },
                { key: 'total', label: t('Total'), render: (row) => <Typography variant="body2" sx={{ whiteSpace: 'nowrap', fontWeight: 600 }}>{money(row.total)}</Typography> },
                { key: 'status', label: t('Status'), render: (row) => <Chip size="small" variant="outlined" color={row.status === 'void' ? 'default' : 'success'} label={t(row.status === 'void' ? 'Voided' : 'Completed')} /> },
                { key: 'actions', label: t('Actions'), render: (row) => <Stack direction="row" sx={{ gap: 1, flexWrap: 'wrap' }}>
                    <Button component={Link} href={`/pos/sales/${row.id}`} icon="receipt" tone="ghost">{t('View')}</Button>
                    {row.can_update && <Button icon="edit" tone="ghost" onClick={() => { editForm.clearErrors(); editForm.setData('notes', row.notes || ''); setEditTarget(row); }}>{t('Edit')}</Button>}
                    {row.can_delete && <Button icon="suspend" tone="ghost" color="error" onClick={() => { voidForm.reset(); voidForm.clearErrors(); setVoidTarget(row); }}>{t('Void sale')}</Button>}
                </Stack> },
            ]} />
            <Pager paginator={sales} />
        </Card>
        <Dialog open={Boolean(editTarget)} onClose={() => { if (!editForm.processing) setEditTarget(null); }} aria-labelledby="edit-sale-title" fullWidth maxWidth="sm">
            <Box component="form" onSubmit={(event) => { event.preventDefault(); editForm.put(`/pos/sales/${editTarget.id}`, { preserveScroll: true, onSuccess: () => setEditTarget(null) }); }}>
                <DialogTitle id="edit-sale-title">{t('Edit')} {editTarget?.number}</DialogTitle>
                <DialogContent>
                    <Typography variant="body2" sx={{ mb: 3 }}>{t('Notes can be corrected here. Items, prices, tax, and payments stay as they were recorded. Void the sale and record a new one to change those.')}</Typography>
                    <Errors errors={editForm.errors} />
                    <Field label={t('Notes')} error={editForm.errors.notes}><Input autoFocus multiline minRows={3} value={editForm.data.notes} onChange={(event) => editForm.setData('notes', event.target.value)} /></Field>
                </DialogContent>
                <DialogActions><Button icon="cancel" tone="ghost" disabled={editForm.processing} onClick={() => setEditTarget(null)}>{t('Cancel')}</Button><Button icon="save" type="submit" loading={editForm.processing}>{t('Save changes')}</Button></DialogActions>
            </Box>
        </Dialog>
        <Dialog open={Boolean(voidTarget)} onClose={() => { if (!voidForm.processing) setVoidTarget(null); }} aria-labelledby="void-sale-title" fullWidth maxWidth="sm">
            <Box component="form" onSubmit={(event) => { event.preventDefault(); voidForm.delete(`/pos/sales/${voidTarget.id}`, { preserveScroll: true, onSuccess: () => setVoidTarget(null) }); }}>
                <DialogTitle id="void-sale-title">{t('Void sale')} {voidTarget?.number}</DialogTitle>
                <DialogContent>
                    <Typography variant="body2" sx={{ mb: 3 }}>{t('The sale stays in this list. Stock is returned, customer credit is reversed, and the sale is left out of sales totals.')}</Typography>
                    <Errors errors={voidForm.errors} />
                    <Stack sx={{ gap: 2.5 }}>
                        <Field label={t('Reason')} error={voidForm.errors.reason}><Input autoFocus multiline required minRows={2} value={voidForm.data.reason} onChange={(event) => voidForm.setData('reason', event.target.value)} /></Field>
                        {approvers.length > 0 && <Field label={t('Manager approval')} error={voidForm.errors.approval}><Input select required value={voidForm.data.approved_by} onChange={(event) => voidForm.setData('approved_by', event.target.value)}><option value="">{t('Choose a manager')}</option>{approvers.map((approver) => <option key={approver.id} value={approver.id}>{approver.name}</option>)}</Input></Field>}
                    </Stack>
                </DialogContent>
                <DialogActions><Button icon="cancel" tone="ghost" disabled={voidForm.processing} onClick={() => setVoidTarget(null)}>{t('Cancel')}</Button><Button icon="suspend" tone="danger" type="submit" loading={voidForm.processing}>{t('Void sale')}</Button></DialogActions>
            </Box>
        </Dialog>
    </Shell>;
}
