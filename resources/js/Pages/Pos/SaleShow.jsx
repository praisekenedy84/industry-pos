import { Button, Card, Input, money, quantity, Shell, useT } from '@/lib/ui';
import { w } from '@/lib/paths';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Box, Dialog, DialogActions, DialogContent, DialogTitle } from '@mui/material';

export default function SaleShow({ sale, items, canViewMargin }) {
    const t = useT();
    const { flash } = usePage().props;
    const [previewOpen, setPreviewOpen] = useState(Boolean(flash?.open_receipt));
    const form = useForm({ sale_id: sale.id, reason: '', restock: true, lines: sale.lines.map((line) => ({ sale_line_id: line.id, qty: line.qty })) });
    const receiptPdf = w(`/pos/sales/${sale.id}/receipt.pdf`);
    const downloadPdf = `${receiptPdf}?download=1`;

    useEffect(() => {
        if (flash?.open_receipt) {
            setPreviewOpen(true);
        }
    }, [flash?.open_receipt, sale.id]);

    return (
        <Shell title={sale.number} actions={<><Button icon="back" component={Link} tone="ghost" href="/pos/sales">{t('Sales')}</Button><Button icon="receipt" tone="ghost" onClick={() => setPreviewOpen(true)}>{t('Preview receipt')}</Button><Button icon="export" tone="ghost" component="a" href={downloadPdf}>{t('Download')}</Button><Button icon="print" component={Link} tone="ghost" href={`/pos/sales/${sale.id}/receipt`}>{t('Print receipt')}</Button></>}>
            <form className="mb-4 space-y-2" onSubmit={(event) => { event.preventDefault(); form.post('/pos/returns'); }}>
                <Input className="w-full" placeholder={t('Return reason')} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                <Button icon="reset" loading={form.processing} type="submit" tone="soft">{t('Return sale')}</Button>
                <Button icon="print" type="button" tone="ghost" onClick={() => router.post(`/pos/sales/${sale.id}/reprint`)}>{t('Reprint')}</Button>
            </form>
            <Card className="mb-4 p-4 text-sm">
                <p>{t('Status')} {sale.status} · {money(sale.total)}</p>
                <p className="mt-1 text-muted">{t('Seller:')} {sale.seller?.name || 'Unavailable'}</p>
                {sale.lines.some((line) => line.costing_status === 'pending') && <p className="mt-2">{t('Pending costing')}: {t('Production cost has not yet been assigned to this sale.')}</p>}
                {canViewMargin && !sale.lines.some((line) => line.costing_status === 'pending') && <p className="mt-2">{t('Gross profit')}: {money(sale.lines.reduce((sum, line) => sum + Number(line.net) - Number(line.total_cost ?? Number(line.unit_cost) * Number(line.qty)), 0))}</p>}
                <ul className="mt-2 space-y-1">
                    {sale.lines.map((line) => <li key={line.id}>{items[line.item_id]?.name} × {quantity(line.qty)} · {money(line.gross)}</li>)}
                </ul>
                <ul className="mt-2 text-muted">{sale.payments.map((payment) => <li key={payment.id}>{payment.method} {money(payment.amount)}</li>)}</ul>
            </Card>
            <Dialog open={previewOpen} onClose={() => setPreviewOpen(false)} aria-labelledby="receipt-preview-title" fullWidth maxWidth="sm">
                <DialogTitle id="receipt-preview-title">{t('Receipt preview')}</DialogTitle>
                <DialogContent dividers sx={{ p: 0, bgcolor: '#525659', height: { xs: '60vh', sm: 640 } }}>
                    {previewOpen ? <Box component="iframe" title={t('Receipt preview')} src={receiptPdf} sx={{ display: 'block', width: '100%', height: '100%', border: 0, bgcolor: '#fff' }} /> : null}
                </DialogContent>
                <DialogActions sx={{ flexDirection: 'column', alignItems: 'stretch', px: 2.5, py: 2 }}>
                    <Button icon="cancel" tone="ghost" onClick={() => setPreviewOpen(false)}>{t('Close')}</Button>
                    <Button icon="export" fullWidth component="a" href={downloadPdf}>{t('Download')}</Button>
                </DialogActions>
            </Dialog>
        </Shell>
    );
}
