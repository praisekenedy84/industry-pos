import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Card, Field, inputClass, Shell } from '@/lib/ui';

export default function RecipeForm({ recipe, version, lines, costs, stages, history, items, multiStage, units }) {
    const t = useT();
    const [nextLines, setNextLines] = useState(lines.map((line) => ({ item_id: line.item_id, qty: line.qty })));
    const [nextStages, setNextStages] = useState(stages.map((stage) => ({ name: stage.name, requires_quality: stage.requires_quality })));
    const labor = costs.find((row) => row.type === 'labor')?.amount || '';
    const overhead = costs.find((row) => row.type === 'overhead')?.amount || '';
    const form = useForm({ name: recipe.name, item_id: recipe.item_id, yield_qty: version?.yield_qty || '1', notes: recipe.notes || '', labor, overhead, lines: nextLines, stages: nextStages });
    return (
        <Shell title={recipe.name}>
            <p className="mb-3 text-sm text-muted">{t("Version")} {recipe.current_version}{t(". Saving creates a new version. Past runs keep this one.")}</p>

            <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); form.transform((data) => ({ ...data, lines: nextLines, stages: nextStages })); form.put(`/production/recipes/${recipe.id}`); }}>
                <Field error={form.errors.name} label={t("Name")}><Input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <Field error={form.errors.item_id} label={t("Output")}><Input select className={inputClass} value={form.data.item_id} onChange={(event) => form.setData('item_id', event.target.value)}>{items.filter((item) => item.track_stock && item.type !== 'service' && item.base_unit_id).map((item) => <option key={item.id} value={item.id}>{item.name} / {units[item.base_unit_id]?.symbol || units[item.base_unit_id]?.name}</option>)}</Input></Field>
                <Field error={form.errors.yield_qty} label={t("Expected output quantity")}><Input type="number" min="0" step="any" className={inputClass} value={form.data.yield_qty} onChange={(event) => form.setData('yield_qty', event.target.value)} /></Field>
                {nextLines.map((line, index) => (
                    <div key={index} className="grid grid-cols-2 gap-2">
                        <Input select label={t("Item")} className={inputClass} value={line.item_id} onChange={(event) => setNextLines(nextLines.map((row, i) => i === index ? { ...row, item_id: event.target.value } : row))}>{items.filter((item) => item.track_stock && item.type !== 'service').map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Input>
                        <Input type="number" min="0" step="any" label={t("Quantity")} className={inputClass} value={line.qty} onChange={(event) => setNextLines(nextLines.map((row, i) => i === index ? { ...row, qty: event.target.value } : row))} />
                    </div>
                ))}
                {multiStage && nextStages.map((stage, index) => (
                    <Input label={t("Name")} key={index} className={inputClass} value={stage.name} onChange={(event) => setNextStages(nextStages.map((row, i) => i === index ? { ...row, name: event.target.value } : row))} />
                ))}
                {multiStage && <Button icon="add" type="button" tone="ghost" onClick={() => setNextStages([...nextStages, { name: '', requires_quality: false }])}>{t("Add stage")}</Button>}
                <Button icon="add" type="submit">{t("Save new version")}</Button>
            </form>
            <Card className="mb-4 p-4 text-sm">
                <p>{t("History:")} {history.map((row) => `v${row.version}`).join(', ')}</p>
            </Card>
        </Shell>
    );
}
