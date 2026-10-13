import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Card, Shell } from '@/lib/ui';

export default function Counts({ counts, items }) {
    const t = useT();
    const [lines, setLines] = useState(items.slice(0, 8).map((item) => ({ item_id: item.id, name: item.name, counted_qty: '' })));
    const form = useForm({ type: 'cycle', blind: false, lines });
    return (
        <Shell title={t("Stock counts")}>
            <form className="mb-4 space-y-2" onSubmit={(event) => { event.preventDefault(); form.transform((data) => ({ ...data, lines })); form.post('/inventory/counts'); }}>
                <label className="text-sm"><Input type="checkbox" checked={form.data.blind} onChange={(event) => form.setData('blind', event.target.checked)} /> {t("Blind count")}</label>
                {lines.map((line, index) => (
                    <div key={line.item_id} className="grid grid-cols-2 gap-2 text-sm">
                        <span className="self-center">{line.name}</span>
                        <Input type="number" min="0" step="any" className="" placeholder={t("Counted")} value={line.counted_qty} onChange={(event) => setLines(lines.map((row, i) => i === index ? { ...row, counted_qty: event.target.value } : row))} />
                    </div>
                ))}
                <Button icon="save" type="submit">{t("Save count")}</Button>
            </form>
            <Card className="mb-4 divide-y divide-hairline text-sm">
                {counts.map((count) => (
                    <div key={count.id} className="flex items-center justify-between px-4 py-3">
                        <span>{count.number} · {count.status}</span>
                        {count.status === 'review' && <Button icon="post" onClick={() => router.post(`/inventory/counts/${count.id}/post`)}>{t("Post count")}</Button>}
                    </div>
                ))}
            </Card>
        </Shell>
    );
}
