import { useForm } from '@inertiajs/react';
import { Button, Card, Field, Input, money, Pager, quantity, rowsOf, Shell, Table, useT } from '@/lib/ui';

export default function DirectReceipts({ receipts, items, bills, units, canReceive }) {
    const t = useT();
    const form = useForm({ request_id: crypto.randomUUID(), item_id: items[0]?.id || '', qty: '', unit_cost: '', lot_code: '', expiry_date: '', lot_attributes: { notes: '' }, notes: '' });
    const hasBill = !!bills[form.data.item_id];
    return <Shell title={t('Direct receipts')}>
        <p className="mb-4 text-sm text-muted">{t('Receive goods without scheduling a run. Goods with a bill consume their inputs; purchased goods add stock only.')}</p>
        {canReceive && <form className="mb-6 grid max-w-5xl items-start gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]" onSubmit={(event) => {
            event.preventDefault();
            form.post('/inventory/direct-receipts', { onSuccess: () => { form.reset(); form.setData('request_id', crypto.randomUUID()); } });
        }}>
            <Field label={t('Item')} error={form.errors.item_id}><Input select value={form.data.item_id} onChange={(e) => form.setData('item_id', e.target.value)}><option value="">{t('Choose stocked good')}</option>{items.map((item) => <option key={item.id} value={item.id}>{item.name} / {units[item.base_unit_id]?.symbol || units[item.base_unit_id]?.name}</option>)}</Input></Field>
            <Field label={t('Quantity')} error={form.errors.qty}><Input type="number" value={form.data.qty} onChange={(e) => form.setData('qty', e.target.value)} /></Field>
            {!hasBill && <Field label={t('Unit cost')} error={form.errors.unit_cost}><Input type="number" value={form.data.unit_cost} onChange={(e) => form.setData('unit_cost', e.target.value)} /></Field>}
            {hasBill && <p className="self-center text-sm text-muted">{t('Bill of materials')}: {bills[form.data.item_id]}. {t('Cost is calculated from consumed inputs and bill costs.')}</p>}
            <Field label={t('Lot code')} error={form.errors.lot_code}><Input value={form.data.lot_code} onChange={(e) => form.setData('lot_code', e.target.value)} /></Field>
            <Field label={t('Expiry date')} error={form.errors.expiry_date}><Input type="date" value={form.data.expiry_date} onChange={(e) => form.setData('expiry_date', e.target.value)} /></Field>
            <Field label={t('Lot details')} error={form.errors['lot_attributes.notes']}><Input value={form.data.lot_attributes.notes} onChange={(e) => form.setData('lot_attributes', { notes: e.target.value })} /></Field>
            <Field className="sm:col-span-2" label={t('Notes')} error={form.errors.notes}><Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
            <div className="self-end"><Button icon="receive" type="submit" loading={form.processing} disabled={!items.length}>{t('Receive stock')}</Button></div>
            {form.errors.request_id && <p role="alert">{t(form.errors.request_id)}</p>}
        </form>}
        <Card><Table rows={rowsOf(receipts)} empty={t('No direct receipts yet.')} columns={[
            { key: 'number', label: t('Reference') },
            { key: 'item', label: t('Item'), render: (row) => row.item?.name },
            { key: 'qty', label: t('Quantity'), render: (row) => quantity(row.qty) },
            { key: 'unit_cost', label: t('Unit cost'), render: (row) => money(row.unit_cost) },
            { key: 'recipe_version_id', label: t('Source'), render: (row) => t(row.recipe_version_id ? 'Made using a bill' : 'Purchased or received') },
            { key: 'lot_code', label: t('Lot code') },
        ]} /><Pager paginator={receipts} /></Card>
    </Shell>;
}
