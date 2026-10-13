import { router } from '@inertiajs/react';
import { Card, Input, money, Pager, quantity, rowsOf, Shell, Table, useT } from '@/lib/ui';

const changeLabels = {
    opening_balance: 'Opening balance',
    purchase_receipt: 'Stock received',
    adjustment: 'Stock adjustment',
    wastage: 'Stock removed',
    sale: 'Sale',
    sale_return: 'Sale return',
    sale_void: 'Sale voided',
    transfer_out: 'Transfer out',
    transfer_in: 'Transfer in',
    production_consume: 'Used in production',
    production_output: 'Produced',
    production_reversal: 'Production reversal',
    production_release: 'Released from production',
    direct_receipt: 'Direct receipt',
};

export default function Movements({ movements, items, itemId = '', branchId = '' }) {
    const t = useT();
    return (
        <Shell title={t('Stock history')}>
            <p className="mb-4 text-sm text-muted">{t('Every change to stock volume and stock price, and who made it.')}</p>
            <Input select label={t('Filter by item')} className="mb-3 w-auto" value={itemId} onChange={(event) => router.get('/inventory/movements', { item_id: event.target.value || undefined, branch_id: branchId || undefined }, { preserveState: true })}>
                <option value="">{t('All items')}</option>
                {items.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
            </Input>
            <Card>
                <Table rows={rowsOf(movements)} empty={t('No stock changes yet.')} columns={[
                    { key: 'created_at', label: 'When', render: (row) => new Date(row.created_at).toLocaleString() },
                    { key: 'item_name', label: 'Item', render: (row) => <span>{row.item_name}<span className="block text-muted">{row.item_sku}</span></span> },
                    { key: 'type', label: 'Change', render: (row) => t(changeLabels[row.type] || row.type) },
                    { key: 'signed_qty', label: 'Volume change', render: (row) => formatVolume(row.signed_qty) },
                    { key: 'qty_after', label: 'Volume on hand', render: (row) => quantity(row.qty_after) },
                    { key: 'unit_cost', label: 'Stock price', render: (row) => money(row.unit_cost) },
                    { key: 'changed_by', label: 'Changed by', render: (row) => row.changed_by || t('System') },
                    { key: 'reason', label: 'Reason', render: (row) => row.reason || '—' },
                ]} />
                <Pager paginator={movements} />
            </Card>
        </Shell>
    );
}

function formatVolume(value) {
    const amount = Number(value);
    if (!Number.isFinite(amount)) {
        return String(value ?? '');
    }
    const formatted = quantity(Math.abs(amount));
    if (amount > 0) {
        return `+${formatted}`;
    }
    if (amount < 0) {
        return `-${formatted}`;
    }

    return formatted;
}
