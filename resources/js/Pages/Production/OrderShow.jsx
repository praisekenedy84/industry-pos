import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Alert, Typography } from '@mui/material';
import { Button, Card, ConfirmButton, Errors, Field, Input, money, quantity, Shell, Table, useT } from '@/lib/ui';

export default function OrderShow({ order, recipe, recipeVersion, recipeLines = [], recipeCosts = [], items, units = {}, canManage, canRecord, run, output, waste = [], journal, reversal, journalLines = [], history, people, recordedInputs }) {
    const t = useT();
    const finished = ['completed', 'released', 'reversed'].includes(order.status);
    const unfinished = ['draft', 'planned', 'in_progress', 'on_hold'].includes(order.status);
    const product = order.item || order.recipe?.item || items[output?.item_id] || {};
    const unitId = order.output_unit_id || product.base_unit_id;
    const unit = units[unitId]?.symbol || '';
    const form = useForm({ good_qty: '', waste_qty: '0', waste_unit_id: unitId || '', waste_reason: '', notes: '', actual_usage: recipeLines.map((line) => ({ recipe_line_id: line.id, qty: '' })), labor_cost: '', overhead_cost: '', waste_cost: '' });
    const scale = form.data.waste_unit_id === unitId && Number(recipeVersion?.yield_qty) > 0 ? (Number(form.data.good_qty) + Number(form.data.waste_qty)) / Number(recipeVersion.yield_qty) : null;
    const defaultCost = (type) => scale === null ? '' : (recipeCosts.filter((row) => row.type === type).reduce((sum, row) => sum + Number(row.amount), 0) * scale).toFixed(2);
    const correction = useForm({ reason: '' });
    const { errors } = usePage().props;
    const status = (value) => t(value === 'in_progress' ? 'In production' : value === 'completed' || value === 'released' ? 'Completed' : `status.${value}`, value || '—');
    const date = (value) => value ? new Date(value).toLocaleString() : '—';
    return <Shell title={t('Production · Batches')} actions={<Button icon="back" component={Link} tone="ghost" href="/production/batches">{t('All batches')}</Button>}>
        <Card className="mb-5 space-y-2 p-5">
            <Typography variant="h6" component="h2">{order.number} · {product.name || recipe?.name}</Typography>
            <p>{status(order.status)}</p>
            <p className="text-sm text-muted">{t('Started')}: {date(order.started_at)} {finished && <> / {t('Completed')}: {date(order.completed_at)}</>}</p>
            {order.planned_qty != null && <p>{t('Target quantity')}: {quantity(order.planned_qty)} {unit}</p>}
            {order.notes && <p>{order.notes}</p>}
        </Card>
        <Errors errors={{ ...errors, ...form.errors, ...correction.errors }} />
        {reversal && <Alert severity="info" sx={{ mb: 3 }}>{t('This batch was reversed. Its original results remain below for audit.')}: {reversal.number} / {reversal.reason}</Alert>}
        {canRecord && unfinished && <Card className="mb-5 p-5">
            <Typography variant="h6" component="h2" sx={{ mb: 1 }}>{t('Complete batch')}</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>{t('Good output is ready for sale. Enter waste separately; it will not be subtracted from good output.')}</Typography>
            <form className="grid gap-4 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.post(`/production/batches/${order.id}/complete`); }}>
                <Field label={`${t('Good output')} (${unit})`} error={form.errors.good_qty}><Input required type="number" min="0" step="0.0001" value={form.data.good_qty} onChange={(event) => form.setData('good_qty', event.target.value)} helperText={t('Enter zero if the entire batch was wasted.')} /></Field>
                <Field label={`${t('Waste')} (${units[form.data.waste_unit_id]?.symbol || unit})`} error={form.errors.waste_qty}><Input type="number" min="0" step="0.0001" value={form.data.waste_qty} onChange={(event) => form.setData('waste_qty', event.target.value)} /></Field>
                <details className="md:col-span-2"><summary className="cursor-pointer">{t('Waste reason and notes (optional)')}</summary><div className="mt-3 grid gap-4 md:grid-cols-2"><Field label={t('Waste reason')} error={form.errors.waste_reason}><Input value={form.data.waste_reason} onChange={(event) => form.setData('waste_reason', event.target.value)} /></Field><Field label={t('Notes')} error={form.errors.notes}><Input value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} /></Field></div></details>
                {recipeVersion && <details className="md:col-span-2"><summary className="cursor-pointer">{t('Adjust actual usage (optional)')}</summary><p className="my-3 text-sm text-muted">{t('Leave fields blank to use the scaled recipe. Enter zero when nothing was used.')} {t('Recipe version')}: {recipeVersion.version}</p><div className="grid gap-4 md:grid-cols-2">
                    {recipeLines.map((line, index) => <Field key={line.id} label={`${items[line.item_id]?.name} (${units[line.unit_id || items[line.item_id]?.base_unit_id]?.symbol || ''})`}><Input type="number" min="0" step="0.0001" placeholder={scale === null ? t('Actual quantity') : (Number(line.qty) * scale).toFixed(2)} value={form.data.actual_usage[index].qty} onChange={(event) => form.setData('actual_usage', form.data.actual_usage.map((row, i) => i === index ? { ...row, qty: event.target.value } : row))} /></Field>)}
                    <Field label={t('Actual labour cost')}><Input type="number" min="0" step="0.0001" placeholder={defaultCost('labor')} value={form.data.labor_cost} onChange={(event) => form.setData('labor_cost', event.target.value)} /></Field>
                    <Field label={t('Actual overhead cost')}><Input type="number" min="0" step="0.0001" placeholder={defaultCost('overhead')} value={form.data.overhead_cost} onChange={(event) => form.setData('overhead_cost', event.target.value)} /></Field>
                </div></details>}
                <details className="md:col-span-2"><summary className="mb-3 cursor-pointer text-sm text-muted">{t('Waste measured in a different unit?')}</summary><Field fieldWidth="short" label={t('Waste unit')} error={form.errors.waste_unit_id}><Input select value={form.data.waste_unit_id} onChange={(event) => form.setData('waste_unit_id', event.target.value)}>{Object.values(units).map((row) => <option key={row.id} value={row.id}>{row.name} ({row.symbol})</option>)}</Input></Field><p className="mt-2 text-sm text-muted">{t('Use a defined conversion, or enter full actual usage and allocate the waste cost.')}</p></details>
                <Button className="justify-self-start" icon="approve" loading={form.processing} type="submit">{t('Complete batch')}</Button>
                {form.data.waste_unit_id !== unitId && <Field label={t('Cost allocated to waste (only without a conversion)')}><Input type="number" min="0" step="0.0001" value={form.data.waste_cost} onChange={(event) => form.setData('waste_cost', event.target.value)} helperText={t('Without a conversion, enter all actual usage and costs above. This waste allocation is required when there is good output.')} /></Field>}
            </form>
        </Card>}
        {finished && run && <>
            <Card className="mb-5 grid gap-3 p-5 sm:grid-cols-2">
                <p>{t('Good output')}: <strong>{quantity(run.good_qty)} {unit}</strong></p>
                <p>{t('Waste')}: <strong>{quantity(run.waste_qty)} {units[waste[0]?.unit_id || unitId]?.symbol}</strong></p>
                <p>{t('Waste cost')}: {run.waste_cost != null ? money(run.waste_cost) : t('Not valued in historical record')}</p>
                {run.notes && <p className="sm:col-span-2">{run.notes}</p>}
                <details className="sm:col-span-2"><summary className="cursor-pointer text-sm text-muted">{t('Cost breakdown and inventory reference')}</summary><div className="mt-3 space-y-2 text-sm"><p>{t('Batch cost')}: {money(run.batch_cost)}</p><p>{t('Labour')}: {money(run.labor_cost)}</p><p>{t('Overhead')}: {money(run.overhead_cost)}</p><p>{t('Inventory value')}: {run.inventory_value != null ? money(run.inventory_value) : '—'}</p><p>{t('Production loss expense')}: {run.loss_value != null ? money(run.loss_value) : '—'}</p>{output && <p className="break-all">{t('Inventory receipt')}: {output.run_number || order.number} / {output.stock_movement_id}</p>}</div></details>
                {!output && <p>{t('No good output entered inventory.')}</p>}
            </Card>
            {run.waste_treatment === 'absorbed' && Number(run.waste_qty) > 0 && <Alert severity="info" sx={{ mb: 3 }}>{t('Waste cost is included in finished inventory. No separate loss expense was posted.')}</Alert>}
            <Alert severity="info" sx={{ mb: 3 }}>{t('Completed quantities are locked. Corrections require an auditable inventory or accounting adjustment.')}</Alert>
        </>}
        {waste.length > 0 && <Card className="mb-5"><Table rows={waste} columns={[
            { key: 'qty', label: t('Waste'), render: (row) => `${quantity(row.qty)} ${units[row.unit_id || unitId]?.symbol || ''}` },
            { key: 'cost_value', label: t('Waste cost'), render: (row) => row.cost_value != null ? money(row.cost_value) : '—' },
            { key: 'reason', label: t('Reason') },
            { key: 'recorded_by', label: t('Person'), render: (row) => people[row.recorded_by] || '—' },
            { key: 'recorded_at', label: t('When'), render: (row) => date(row.recorded_at) },
        ]} /></Card>}
        {recordedInputs.length > 0 && <><Typography component="h2" variant="h6" sx={{ mb: 2 }}>{t('Material usage')}</Typography>{order.track_materials === false && <p className="mb-3 text-sm text-muted">{t('Recipe quantities are shown for costing. Material stock tracking was off.')}</p>}<Card className="mb-5"><Table rows={recordedInputs} columns={[
            { key: 'item_id', label: t('Material'), render: (row) => items[row.item_id]?.name },
            { key: 'actual_qty', label: t('Quantity'), render: (row) => `${quantity(row.actual_qty)} ${units[items[row.item_id]?.base_unit_id]?.symbol || ''}` },
            { key: 'unit_cost', label: t('Unit cost'), render: (row) => money(row.unit_cost) },
        ]} /></Card></>}
        {journal && <details className="mb-5"><summary className="mb-3 cursor-pointer text-sm text-muted">{t('Accounting details')}</summary><Typography component="h2" variant="h6" sx={{ mb: 2 }}>{t('Accounting reference')}: {journal.number}</Typography><Card className="mb-5"><Table rows={journalLines} columns={[
            { key: 'production_journal_id', label: t('Reference'), render: (row) => row.production_journal_id === journal.id ? journal.number : reversal?.number },
            { key: 'account_code', label: t('Account') },
            { key: 'debit', label: t('Debit'), render: (row) => money(row.debit) },
            { key: 'credit', label: t('Credit'), render: (row) => money(row.credit) },
        ]} /></Card></details>}
        <Typography component="h2" variant="h6" sx={{ mb: 2 }}>{t('Status history')}</Typography>
        <Card className="mb-5"><Table rows={history} columns={[
            { key: 'created_at', label: t('When'), render: (event) => date(event.created_at) },
            { key: 'after', label: t('Status'), render: (event) => status(event.after?.status) },
            { key: 'actor_id', label: t('Person'), render: (event) => people[event.actor_id] || t('System') },
        ]} /></Card>
        {canManage && unfinished && <ConfirmButton icon="cancel" tone="ghost" title={t('Cancel this batch?')} description={t('Only unfinished batches can be cancelled. No finished stock will be added.')} onConfirm={() => router.post(`/production/batches/${order.id}/cancel`)}>{t('Cancel batch')}</ConfirmButton>}
        {canManage && canRecord && order.status === 'completed' && journal && <details className="mt-5"><summary className="mb-3 cursor-pointer text-sm text-muted">{t('Correct this batch')}</summary><Card className="space-y-4 p-5"><p className="text-sm">{t('Reversal removes the good output, restores consumed materials and reverses the accounting journal. Then start a new batch with corrected quantities. The original output must still be in stock.')}</p><Field label={t('Reason for correction')} error={correction.errors.reason}><Input required value={correction.data.reason} onChange={(event) => correction.setData('reason', event.target.value)} /></Field><ConfirmButton icon="reset" tone="danger" loading={correction.processing} disabled={!correction.data.reason.trim()} title={t('Reverse this batch?')} description={t('The stock and accounting postings will be reversed together. Historical quantities will be retained.')} onConfirm={() => correction.post(`/production/batches/${order.id}/reverse`)}>{t('Reverse batch')}</ConfirmButton></Card></details>}
    </Shell>;
}
