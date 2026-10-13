import { Link, useForm } from '@inertiajs/react';
import { Button, Card, Errors, Field, Input, money, quantity, Table, useT } from '@/lib/ui';

export default function AddStock({ item, latestPurchaseCost, suppliers = [], stockReceipts = [], canReceive, units = [], availableQty }) {
    const t = useT();
    const form = useForm({ request_id: crypto.randomUUID(), qty: '', unit_cost: latestPurchaseCost ?? '', supplier_id: '', reference: '', notes: '' });
    const unit = units.find((row) => row.id === item.base_unit_id)?.symbol || '';
    return <Card className="mt-5 space-y-4 p-5" id="stock">
        <h2 className="text-lg font-semibold">{t('Add stock')}</h2>
        <p className="text-sm text-muted">{t('Available at the current branch')}: {quantity(availableQty)} {unit}</p>
        {canReceive && <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); form.post(`/catalog/items/${item.id}/stock`, { preserveScroll: true, onSuccess: () => { form.setData({ ...form.data, request_id: crypto.randomUUID(), qty: '', reference: '', notes: '' }); } }); }}>
            <Errors errors={form.errors} />
            <div className="grid max-w-2xl items-start gap-4 sm:grid-cols-2">
                <Field label={`${t('Quantity received')} (${unit})`}><Input required type="number" min="0.0001" step="0.0001" value={form.data.qty} onChange={(event) => form.setData('qty', event.target.value)} /></Field>
                <Field label={t('Purchase cost per unit')}><Input required type="number" min="0" step="0.0001" value={form.data.unit_cost} onChange={(event) => form.setData('unit_cost', event.target.value)} helperText={t('Enter zero for free stock. Each delivery keeps its own cost.')} /></Field>
            </div>
            <details><summary className="cursor-pointer">{t('Supplier, reference and notes (optional)')}</summary><div className="mt-3 grid gap-4 md:grid-cols-2">
                <Field label={t('Supplier')}><Input select value={form.data.supplier_id} onChange={(event) => form.setData('supplier_id', event.target.value)}><option value="">{t('None')}</option>{suppliers.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Input></Field>
                <Field label={t('Receipt reference')}><Input value={form.data.reference} onChange={(event) => form.setData('reference', event.target.value)} /></Field>
                <Field label={t('Notes')}><Input value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} /></Field>
            </div></details>
            <p className="text-sm text-muted">{t('The current branch, date and your name are recorded automatically. Selling prices stay unchanged.')}</p>
            <Button type="submit" loading={form.processing}>{t('Add stock')}</Button>
        </form>}
        <details><summary className="cursor-pointer text-sm text-muted">{t('Recent stock receipts')}</summary><Table rows={stockReceipts} columns={[
            { key: 'number', label: t('Receipt') }, { key: 'qty', label: t('Quantity'), render: (row) => quantity(row.qty) },
            { key: 'unit_cost', label: t('Purchase cost'), render: (row) => money(row.unit_cost) },
            { key: 'reference', label: t('Reference') }, { key: 'created_at', label: t('Received'), render: (row) => new Date(row.created_at).toLocaleString() },
        ]} /><p className="mt-3 text-sm text-muted">{t('Receipts are permanent. Use a stock adjustment with a reason to correct a mistake.')}</p>{canReceive && <Button component={Link} href={`/inventory?item_id=${item.id}`} tone="ghost">{t('Correct stock with an adjustment')}</Button>}</details>
    </Card>;
}
