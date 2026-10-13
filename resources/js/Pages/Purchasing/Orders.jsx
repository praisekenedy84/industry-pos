import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Card, Field, inputClass, Pager, rowsOf, Shell } from '@/lib/ui';

export default function Orders({ orders, suppliers, items }) {
    const t = useT();
    const [lines, setLines] = useState([{ item_id: items[0]?.id || '', qty: '1', unit_cost: '0' }]);
    const form = useForm({ supplier_id: suppliers[0]?.id || '', notes: '', lines });
    return (
        <Shell title={t("Purchase orders")}>
            <form className="mb-4 space-y-3" onSubmit={(event) => { event.preventDefault(); form.transform((data) => ({ ...data, lines })); form.post('/purchasing/orders'); }}>
                <Field fieldWidth="medium" error={form.errors.supplier_id} label={t("Supplier")}><Input select className={inputClass} value={form.data.supplier_id} onChange={(event) => form.setData('supplier_id', event.target.value)}>{suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</Input></Field>
                {lines.map((line, index) => (
                    <div key={index} className="grid max-w-4xl gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
                        <Input select label={t("Item")} className={inputClass} value={line.item_id} onChange={(event) => setLines(lines.map((row, i) => i === index ? { ...row, item_id: event.target.value } : row))}>{items.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Input>
                        <Input type="number" min="0" step="any" label={t("Quantity")} className={inputClass} value={line.qty} onChange={(event) => setLines(lines.map((row, i) => i === index ? { ...row, qty: event.target.value } : row))} />
                        <Input type="number" min="0" step="any" label={t("Unit cost")} className={inputClass} value={line.unit_cost} onChange={(event) => setLines(lines.map((row, i) => i === index ? { ...row, unit_cost: event.target.value } : row))} />
                    </div>
                ))}
                <Button icon="add" type="button" tone="ghost" onClick={() => setLines([...lines, { item_id: items[0]?.id || '', qty: '1', unit_cost: '0' }])}>{t("Add line")}</Button>
                <Button icon="add" type="submit" loading={form.processing}>{t("Create purchase order")}</Button>
            </form>
            <Card className="mb-4 divide-y divide-hairline text-sm">
                {rowsOf(orders).map((order) => <Link key={order.id} href={`/purchasing/orders/${order.id}`} className="flex justify-between px-4 py-3"><span>{order.number}</span><span className="rounded bg-sticker-yellow px-2">{order.status}</span></Link>)}
                <Pager paginator={orders} />
            </Card>
        </Shell>
    );
}
