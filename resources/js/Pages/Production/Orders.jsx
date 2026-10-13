import { Link, useForm } from '@inertiajs/react';
import { Chip, Typography } from '@mui/material';
import { Button, Card, Errors, Field, Input, Pager, quantity, rowsOf, Shell, Table, money, useT } from '@/lib/ui';

export default function Orders({ orders, products = [], units = {}, canManage, selectedProduct }) {
    const t = useT();
    const form = useForm({ request_id: crypto.randomUUID(), item_id: products.find((row) => row.id === selectedProduct)?.id || products[0]?.id || '', planned_qty: '', notes: '' });
    const date = (value) => value ? new Date(value).toLocaleString() : '—';
    const status = (value) => t(value === 'in_progress' ? 'In production' : value === 'completed' || value === 'released' ? 'Completed' : `status.${value}`, value);
    return <Shell title={t('Production · Batches')}>
        <Typography color="text.secondary" sx={{ mb: 3 }}>{t('Start batch → Record good output and waste → Complete batch → Sell')}</Typography>
        {canManage && <Card className="mb-6 p-5">
            <Typography variant="h6" component="h2" sx={{ mb: 2 }}>{t('Start batch')}</Typography>
            <Errors errors={form.errors} />
            <form className="grid max-w-4xl items-start gap-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]" onSubmit={(event) => { event.preventDefault(); form.post('/production/batches'); }}>
                <Field label={t('Product being produced')} error={form.errors.item_id}><Input select required value={form.data.item_id} onChange={(event) => form.setData('item_id', event.target.value)}><option value="">{t('Choose product')}</option>{products.map((product) => <option key={product.id} value={product.id}>{product.name} ({units[product.base_unit_id]?.symbol})</option>)}</Input></Field>
                <Field label={t('Batch number')}><Input disabled value={t('Generated automatically when you start')} /></Field>
                <details className="md:col-span-2"><summary className="cursor-pointer">{t('Target and notes (optional)')}</summary><div className="mt-3 grid gap-4 md:grid-cols-2"><Field fieldWidth="short" label={t('Target quantity (optional)')} error={form.errors.planned_qty}><Input type="number" min="0.0001" step="0.0001" value={form.data.planned_qty} onChange={(event) => form.setData('planned_qty', event.target.value)} /></Field><Field label={t('Notes (optional)')} error={form.errors.notes}><Input value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} /></Field></div></details>
                <Button className="justify-self-start" icon="add" type="submit" loading={form.processing} disabled={!products.length}>{t('Start batch')}</Button>
            </form>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>{t('Starting does not change stock. An existing recipe is selected automatically.')}</Typography>
            <p className="mt-2 text-sm text-muted">{t('Missing a product? Add its recipe in Products first.')}</p>
        </Card>}
        <Card><Table rows={rowsOf(orders)} empty={t('No batches yet. Start your first batch above.')} columns={[
            { key: 'number', label: t('Batch number'), render: (batch) => <Link className="font-medium text-link" href={`/production/batches/${batch.id}`}>{batch.number}</Link> },
            { key: 'product', label: t('Product'), render: (batch) => batch.item?.name || batch.recipe?.item?.name || '—' },
            { key: 'status', label: t('Status'), render: (batch) => <Chip size="small" label={status(batch.status)} color={['completed', 'released'].includes(batch.status) ? 'success' : batch.status === 'cancelled' ? 'default' : 'info'} /> },
            { key: 'good', label: t('Good output'), render: (batch) => batch.run ? `${quantity(batch.run.good_qty)} ${units[batch.output_unit_id || batch.recipe?.item?.base_unit_id]?.symbol || ''}` : '—' },
            { key: 'waste', label: t('Waste'), render: (batch) => batch.run ? `${quantity(batch.run.waste_qty)} ${units[batch.run.waste?.unit_id || batch.output_unit_id || batch.recipe?.item?.base_unit_id]?.symbol || ''}` : '—' },
            { key: 'waste_cost', label: t('Waste cost'), render: (batch) => batch.run?.waste_cost != null ? money(batch.run.waste_cost) : '—' },
            { key: 'started_at', label: t('Started'), render: (batch) => date(batch.started_at) },
            { key: 'completed_at', label: t('Completed'), render: (batch) => date(batch.completed_at) },
        ]} /><Pager paginator={orders} /></Card>
    </Shell>;
}
