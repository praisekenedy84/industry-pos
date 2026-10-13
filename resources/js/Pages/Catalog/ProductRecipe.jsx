import { Link, useForm } from '@inertiajs/react';
import { Button, Card, Errors, Field, Input, money, Table, useT } from '@/lib/ui';
import AddUnit from './AddUnit';

export default function ProductRecipe({ item, recipeVersion, recipeEstimate, recipeLines = [], recipeCosts = [], ingredients = [], units = [], recipeHistory = [], canRecipe, canCreate = false }) {
    const t = useT();
    const form = useForm({
        yield_qty: recipeVersion?.yield_qty ?? '',
        lines: recipeLines.length ? recipeLines.map((line) => ({ item_id: line.item_id, qty: line.qty, unit_id: line.unit_id || ingredients.find((row) => row.id === line.item_id)?.base_unit_id || '' })) : [{ item_id: '', qty: '', unit_id: '' }],
        labor: recipeCosts.filter((row) => row.type === 'labor').reduce((sum, row) => sum + Number(row.amount), 0),
        overhead: recipeCosts.filter((row) => row.type === 'overhead').reduce((sum, row) => sum + Number(row.amount), 0),
    });
    const changeLine = (index, changes) => form.setData('lines', form.data.lines.map((line, i) => i === index ? { ...line, ...changes } : line));
    return <Card className="mt-5 space-y-4 p-5" id="recipe">
        <h2 className="text-lg font-semibold">{t('Recipe')}</h2>
        <p className="text-sm text-muted">{t('Quantities and optional costs below are for this expected output. Selling prices are set in product details.')}</p>
        <Errors errors={form.errors} />
        <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); form.put(`/catalog/items/${item.id}/recipe`, { preserveScroll: true }); }}>
            <Field fieldWidth="short" label={t('Expected output quantity')}><Input required disabled={!canRecipe} type="number" min="0.0001" step="0.0001" value={form.data.yield_qty} onChange={(event) => form.setData('yield_qty', event.target.value)} helperText={units.find((unit) => unit.id === item.base_unit_id)?.symbol} /></Field>
            {form.data.lines.map((line, index) => <div key={index} className="grid items-start gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
                <Field className="sm:col-span-2 lg:col-span-1" label={t('Ingredient')}><Input select required disabled={!canRecipe} value={line.item_id} onChange={(event) => changeLine(index, { item_id: event.target.value, unit_id: ingredients.find((row) => row.id === event.target.value)?.base_unit_id || '' })}><option value="">{t('Choose ingredient')}</option>{ingredients.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Input></Field>
                <Field label={t('Quantity')}><Input required disabled={!canRecipe} type="number" min="0.0001" step="0.0001" value={line.qty} onChange={(event) => changeLine(index, { qty: event.target.value })} /></Field>
                <div><Field label={t('Unit')}><Input select required disabled={!canRecipe} value={line.unit_id} onChange={(event) => changeLine(index, { unit_id: event.target.value })}>{units.map((unit) => <option key={unit.id} value={unit.id}>{unit.symbol}</option>)}</Input></Field>{canCreate && canRecipe && <AddUnit units={units} onCreated={(id) => changeLine(index, { unit_id: id })} />}</div>
                {canRecipe && <Button className="justify-self-start sm:col-span-2 lg:col-span-1" type="button" tone="ghost" disabled={form.data.lines.length === 1} onClick={() => form.setData('lines', form.data.lines.filter((_, i) => i !== index))}>{t('Remove')}</Button>}
            </div>)}
            {canRecipe && <Button type="button" tone="soft" onClick={() => form.setData('lines', [...form.data.lines, { item_id: '', qty: '', unit_id: '' }])}>{t('Add ingredient')}</Button>}
            <p className="text-sm text-muted">{t('Missing an ingredient? Add it as an Ingredient in Products, with its stock unit. Material cost comes from received inventory.')}</p>
            <Button component={Link} href="/catalog/items?type=raw_material" tone="ghost">{t('Manage ingredients and stock')}</Button>
            <details><summary className="cursor-pointer">{t('Labour and overhead (optional)')}</summary><div className="mt-3 grid max-w-2xl gap-4 sm:grid-cols-2">
                <Field label={t('Labour for this recipe quantity')}><Input disabled={!canRecipe} type="number" min="0" step="0.0001" value={form.data.labor} onChange={(event) => form.setData('labor', event.target.value)} /></Field>
                <Field label={t('Overhead for this recipe quantity')}><Input disabled={!canRecipe} type="number" min="0" step="0.0001" value={form.data.overhead} onChange={(event) => form.setData('overhead', event.target.value)} /></Field>
            </div></details>
            {canRecipe && <Button type="submit" loading={form.processing}>{t('Save recipe')}</Button>}
        </form>
        {recipeEstimate && <section className="space-y-3 rounded-lg bg-canvas p-4">
            <h3 className="font-semibold">{t('Estimated cost of saved recipe')}</h3>
            <p className="text-sm text-muted">{t('Uses available ingredient stock at the current branch. Save recipe changes to refresh. Actual batch cost includes recorded usage and waste.')}</p>
            {recipeEstimate.total === null ? <><p className="font-medium">{t('Cost pending')}</p><ul className="list-disc pl-5 text-sm">{recipeEstimate.issues.map((issue, index) => <li key={index}>{issue}</li>)}</ul></> : <dl className="grid gap-3 sm:grid-cols-2">
                {[[t('Ingredients'), recipeEstimate.material], [t('Labour'), recipeEstimate.labor], [t('Overhead'), recipeEstimate.overhead], [t('Total recipe cost'), recipeEstimate.total], [t('Cost per produced unit'), recipeEstimate.unit_cost]].map(([label, amount]) => <div key={label}><dt className="text-sm text-muted">{label}</dt><dd className="font-semibold">{money(amount)}</dd></div>)}
            </dl>}
        </section>}
        {!!recipeHistory.length && <details><summary className="cursor-pointer text-sm text-muted">{t('Recipe history')}</summary><p className="my-3 text-sm">{t('Changes apply to new batches. Batches already started keep their original version.')}</p><Table rows={recipeHistory} columns={[{ key: 'version', label: t('Version') }, { key: 'yield_qty', label: t('Expected output') }, { key: 'created_at', label: t('Saved'), render: (row) => new Date(row.created_at).toLocaleString() }]} /></details>}
    </Card>;
}
