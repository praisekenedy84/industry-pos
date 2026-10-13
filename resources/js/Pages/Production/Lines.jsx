import { useT } from '@/lib/ui';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Card, Field, Input, inputClass, quantity, Shell } from '@/lib/ui';

export default function Lines({ lines, recipes, stock, canManage }) {
    const t = useT();
    const [editing, setEditing] = useState(null);
    const defaults = { name: '', code: '', recipe_id: recipes[0]?.id || '', status: 'active', daily_capacity: '', notes: '' };
    const form = useForm(defaults);
    function edit(line) {
        setEditing(line.id);
        form.clearErrors();
        form.setData(Object.fromEntries(Object.keys(defaults).map((key) => [key, line[key] ?? ''])));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    return <Shell title={t("Production lines")} actions={<Button icon="settings" component={Link} tone="soft" href="/production/recipes">{t("Manage recipes")}</Button>}>
        <p className="mb-4 text-sm text-muted">{t("Define how a stocked good is made, assign its bill of materials to a line, and plan production. Goods without a bill are purchased or received directly.")}</p>
        {canManage && <form className="mb-6 grid gap-3 md:grid-cols-2" onSubmit={(event) => {
            event.preventDefault();
            const options = { onSuccess: () => { setEditing(null); form.setData(defaults); } };
            if (editing) form.put(`/production/lines/${editing}`, options); else form.post('/production/lines', options);
        }}>
            <Field label={t("Line name")} error={form.errors.name}><Input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
            <Field label={t("Line code")} error={form.errors.code}><Input className={inputClass} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} /></Field>
            <Field label={t("Product recipe")} error={form.errors.recipe_id}><Input select className={inputClass} value={form.data.recipe_id} onChange={(e) => form.setData('recipe_id', e.target.value)}><option value="">{t("Choose recipe")}</option>{recipes.map((recipe) => <option key={recipe.id} value={recipe.id}>{recipe.name} — {recipe.item?.name}</option>)}</Input></Field>
            <Field label={t("Daily capacity (optional, product units)")} error={form.errors.daily_capacity}><Input type="number" className={inputClass} value={form.data.daily_capacity} onChange={(e) => form.setData('daily_capacity', e.target.value)} /></Field>
            <Field label={t("Status")} error={form.errors.status}><Input select className={inputClass} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}><option value="active">{t("Active")}</option><option value="inactive">{t("Inactive")}</option></Input></Field>
            <Field label={t("Notes")} error={form.errors.notes}><Input className={inputClass} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
            <div className="flex flex-wrap items-center gap-2"><Button icon={editing ? "save" : "add"} type="submit" loading={form.processing} disabled={!recipes.length}>{editing ? t("Save line") : t("Create line")}</Button>{editing && <Button icon="cancel" type="button" tone="ghost" onClick={() => { setEditing(null); form.setData(defaults); }}>{t("Cancel edit")}</Button>}</div>
            {!recipes.length && <p className="text-sm text-muted">{t("Create a produced product and its recipe first.")}</p>}
        </form>}
        <div className="grid gap-4 md:grid-cols-2">{lines.map((line) => <Card key={line.id} className="space-y-3 p-4">
            <div className="flex justify-between gap-3"><h2 className="font-semibold">{line.name}</h2><span className="text-sm">{t(`status.${line.status}`, line.status)}</span></div>
            <p className="text-sm text-muted">{line.code} · {line.recipe?.item?.name} · {line.recipe?.name}</p>
            <p className="text-sm">{t("Planned:")} {line.planned_count} / {t("Running:")} {line.running_count} / {t("Completed:")} {line.completed_count}</p>
            <p className="text-sm">{t("Product stock at this branch:")} {quantity(stock[line.recipe?.item_id] || 0)}{line.daily_capacity ? ` / ${t("Daily capacity:")} ${quantity(line.daily_capacity)}` : ''}</p>
            <div className="flex flex-wrap items-center gap-2">{canManage && line.status === 'active' && <Button icon="add" component={Link} size="small" tone="soft" href={`/production/orders?line=${line.id}`}>{t("Plan batch")}</Button>}<Button icon="production" component={Link} size="small" tone="ghost" href="/production/orders">{t("View batches")}</Button>{canManage && <Button icon="edit" size="small" tone="ghost" onClick={() => edit(line)}>{t("Edit")}</Button>}</div>
        </Card>)}</div>
        {!lines.length && <Card className="p-6 text-sm text-muted">{t("No production lines yet. Configure your first line above.")}</Card>}
    </Shell>;
}

