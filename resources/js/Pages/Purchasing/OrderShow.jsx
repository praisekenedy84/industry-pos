import { useT } from '@/lib/ui';
import { TableContainer, Table as MuiTable, TableHead, TableRow, TableCell, TableBody } from '@mui/material';
import { Input } from '@/lib/ui';
import { router, useForm } from '@inertiajs/react';
import { Button, Card, money, quantity, Shell } from '@/lib/ui';

export default function OrderShow({ order, lines, supplier, items, receipts }) {
    const t = useT();
    const initial = Object.fromEntries(lines.map((line) => [line.id, { qty: line.qty, unit_cost: line.unit_cost, lot_code: '' }]));
    const form = useForm({ lines: initial, notes: '' });
    return (
        <Shell title={order.number} actions={order.status === 'draft' ? <Button icon="approve" onClick={() => router.post(`/purchasing/orders/${order.id}/approve`)}>{t("Approve")}</Button> : null}>
            <p className="mb-3 text-sm text-muted">{supplier?.name} · {order.status}</p>
            {['approved', 'partial'].includes(order.status) && (
                <form className="mb-4 space-y-2" onSubmit={(event) => { event.preventDefault(); form.post(`/purchasing/orders/${order.id}/receive`); }}>
                    {lines.map((line) => (
                        <div key={line.id} className="grid grid-cols-3 gap-2 text-sm">
                            <span className="self-center">{items[line.item_id]?.name}</span>
                            <Input type="number" min="0" step="any" label={t("Quantity")} className="" value={form.data.lines[line.id].qty} onChange={(event) => form.setData('lines', { ...form.data.lines, [line.id]: { ...form.data.lines[line.id], qty: event.target.value } })} />
                            <Input className="" placeholder={t("Lot")} value={form.data.lines[line.id].lot_code} onChange={(event) => form.setData('lines', { ...form.data.lines, [line.id]: { ...form.data.lines[line.id], lot_code: event.target.value } })} />
                        </div>
                    ))}
                    <Button icon="receive" loading={form.processing} type="submit">{t("Receive goods")}</Button>
                </form>
            )}
            <Card className="mb-4">
                <TableContainer tabIndex={0} role="region" aria-label={t("Scrollable records")}><MuiTable className="w-full text-left text-sm">
                    <TableHead className="text-muted"><TableRow><TableCell scope="col" className="px-4 py-2">{t("Item")}</TableCell><TableCell scope="col">{t("Ordered")}</TableCell><TableCell scope="col">{t("Received")}</TableCell><TableCell scope="col">{t("Cost")}</TableCell></TableRow></TableHead>
                    <TableBody>
                        {lines.map((line) => (
                            <TableRow key={line.id} className="border-t border-hairline">
                                <TableCell className="px-4 py-2">{items[line.item_id]?.name}</TableCell>
                                <TableCell>{quantity(line.qty)}</TableCell>
                                <TableCell>{quantity(line.qty_received || 0)}</TableCell>
                                <TableCell>{money(line.unit_cost)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </MuiTable></TableContainer>
            </Card>
            {receipts.length > 0 && <p className="mb-3 text-sm">{t("Receipts:")} {receipts.map((row) => row.number).join(', ')}</p>}
        </Shell>
    );
}
