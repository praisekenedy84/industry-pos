import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Card, Field, inputClass, Pager, rowsOf, Shell } from '@/lib/ui';

export default function Transfers({ transfers, items, branches }) {
    const t = useT();
    const [lines, setLines] = useState([{ item_id: items[0]?.id || '', qty: '1' }]);
    const form = useForm({ from_branch_id: branches[0]?.id || '', to_branch_id: branches[1]?.id || '', lines });
    return (
        <Shell title={t("Transfers")}>
            <form className="mb-4 space-y-3" onSubmit={(event) => { event.preventDefault(); form.transform((data) => ({ ...data, lines })); form.post('/inventory/transfers'); }}>
                <div className="grid gap-3 md:grid-cols-2">
                    <Field error={form.errors.from_branch_id} label={t("From")}><Input select className={inputClass} value={form.data.from_branch_id} onChange={(event) => form.setData('from_branch_id', event.target.value)}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</Input></Field>
                    <Field error={form.errors.to_branch_id} label={t("To")}><Input select className={inputClass} value={form.data.to_branch_id} onChange={(event) => form.setData('to_branch_id', event.target.value)}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</Input></Field>
                </div>
                {lines.map((line, index) => (
                    <div key={index} className="grid grid-cols-2 gap-2">
                        <Input select label={t("Item")} className={inputClass} value={line.item_id} onChange={(event) => setLines(lines.map((row, i) => i === index ? { ...row, item_id: event.target.value } : row))}>{items.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Input>
                        <Input type="number" min="0" step="any" label={t("Quantity")} className={inputClass} value={line.qty} onChange={(event) => setLines(lines.map((row, i) => i === index ? { ...row, qty: event.target.value } : row))} />
                    </div>
                ))}
                <Button icon="add" type="submit">{t("Draft transfer")}</Button>
            </form>
            <Card className="mb-4">
                {rowsOf(transfers).map((row) => (
                    <div key={row.id} className="flex items-center justify-between border-b border-hairline px-4 py-3 text-sm">
                        <span>{row.number} · {row.status}</span>
                        <span className="flex gap-2">
                            {row.status === 'draft' && <Button icon="send" onClick={() => router.post(`/inventory/transfers/${row.id}/send`)}>{t("Send")}</Button>}
                            {row.status === 'in_transit' && <Button icon="receive" onClick={() => router.post(`/inventory/transfers/${row.id}/receive`, { lines: {} })}>{t("Receive")}</Button>}
                        </span>
                    </div>
                ))}
                <Pager paginator={transfers} />
            </Card>
        </Shell>
    );
}
