import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Stack, TableBody, TableCell, TableContainer, TableHead, TableRow, Table as MuiTable } from '@mui/material';
import { Button, Card, Errors, Field, Input, inputClass, money, quantity, Shell, useT } from '@/lib/ui';

const sourceLabels = { produced: 'Produced', stocked: 'Purchased or added', service: 'Service' };

export default function Stock({ rows, selectedItem, canAdjust, filters = {} }) {
    const t = useT();
    const { url } = usePage();
    const current = new URLSearchParams(url.split('?')[1] || '');
    const branchId = current.get('branch_id') || undefined;
    const form = useForm({ request_id: crypto.randomUUID(), item_id: selectedItem || rows[0]?.id || '', signed_qty: '', reason: '', unit_cost: '' });
    return (
        <Shell title={t("Stock")}>
            <Errors errors={form.errors} />
            {canAdjust && <form className="mb-4 grid items-start gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,2fr)_auto]" onSubmit={(event) => { event.preventDefault(); form.post('/inventory/adjustments', { onSuccess: () => form.setData({ ...form.data, request_id: crypto.randomUUID(), signed_qty: '', reason: '' }) }); }}>
                <Field error={form.errors.item_id} label={t("Item")}><Input select className={inputClass} value={form.data.item_id} onChange={(event) => form.setData('item_id', event.target.value)}>{rows.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Input></Field>
                <Field error={form.errors.signed_qty} label={t("Quantity change")}><Input type="number" step="any" className={inputClass} value={form.data.signed_qty} onChange={(event) => form.setData('signed_qty', event.target.value)} /></Field>
                <Field error={form.errors.reason} label={t("Reason")}><Input className={inputClass} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} /></Field>
                <div className="self-end"><Button icon="post" loading={form.processing} type="submit">{t("Post adjustment")}</Button></div>
            </form>}
            <Stack component="form" direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ mb: 3, alignItems: { sm: 'center' } }} onSubmit={(event) => { event.preventDefault(); const values = new FormData(event.currentTarget); router.get('/inventory', { q: values.get('q') || undefined, source: values.get('source') || undefined, branch_id: branchId, item_id: current.get('item_id') || undefined }, { preserveState: true }); }}>
                <Input label={t("Search stock")} name="q" defaultValue={filters.q || ''} sx={{ flex: 1 }} />
                <Input select label={t("Source")} name="source" defaultValue={filters.source || ''} sx={{ minWidth: 220 }}>
                    <option value="">{t("All stock")}</option>
                    <option value="produced">{t("Produced")}</option>
                    <option value="stocked">{t("Purchased or added")}</option>
                </Input>
                <Button icon="filter" type="submit" tone="soft">{t("Apply filters")}</Button>
                {(filters.q || filters.source) && <Button icon="reset" component={Link} href={branchId ? `/inventory?branch_id=${branchId}` : '/inventory'} tone="ghost">{t("Reset")}</Button>}
            </Stack>
            <Card>
                <TableContainer tabIndex={0} role="region" aria-label={t("Scrollable records")}><MuiTable className="w-full text-left text-sm">
                    <TableHead className="text-muted"><TableRow><TableCell scope="col" className="px-4 py-2">{t("Item")}</TableCell><TableCell scope="col">{t("Source")}</TableCell><TableCell scope="col">{t("On hand")}</TableCell><TableCell scope="col">{t("Value")}</TableCell><TableCell scope="col">{t("Avg cost")}</TableCell><TableCell scope="col">{t("History")}</TableCell></TableRow></TableHead>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.id} className="border-t border-hairline">
                                <TableCell className="px-4 py-2">{row.name}<div className="text-muted">{row.sku}</div></TableCell>
                                <TableCell>{t(sourceLabels[row.source] || row.source)}</TableCell>
                                <TableCell>{quantity(row.qty ?? 0)}</TableCell>
                                <TableCell>{money(row.value)}</TableCell>
                                <TableCell>{money(row.avg_cost)}</TableCell>
                                <TableCell><Button component={Link} href={`/inventory/movements?item_id=${row.id}${branchId ? `&branch_id=${branchId}` : ''}`} tone="ghost">{t("History")}</Button></TableCell>
                            </TableRow>
                        ))}
                        {!rows.length && <TableRow><TableCell colSpan={6} className="px-4 py-6 text-muted">{t("No stock matches these filters.")}</TableCell></TableRow>}
                    </TableBody>
                </MuiTable></TableContainer>
            </Card>
        </Shell>
    );
}
