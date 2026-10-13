import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Errors, Field, Input, money, useT } from '@/lib/ui';
import { w } from '@/lib/paths';
import AddUnit from './AddUnit';

export default function NewProductRecipe({ data, onChange, ingredients, units, outputUnit, canCreate = false }) {
    const t = useT();
    const http = useHttp({});
    const [estimate, setEstimate] = useState(null);
    const [failure, setFailure] = useState('');
    const signature = JSON.stringify(data);
    const result = estimate?.signature === signature ? estimate.result : null;
    const changeLine = (index, changes) => onChange({ ...data, lines: data.lines.map((line, i) => i === index ? { ...line, ...changes } : line) });
    const calculate = async () => {
        setFailure('');
        setEstimate(null);
        http.transform(() => data);
        try {
            const response = await http.post(w('/catalog/recipe-estimate'));
            setEstimate({ signature, result: response });
        } catch {
            setFailure('Unable to calculate cost. Check the ingredient quantities and try again.');
        }
    };
    return <section className="min-w-0 space-y-4 rounded-lg border border-hairline p-4 sm:col-span-2">
        <h3 className="text-lg font-semibold">{t('Recipe / mixture')}</h3>
        <p className="text-sm text-muted">{t('Enter the ingredients for one mixture and how much finished product it makes.')}</p>
        <Field fieldWidth="medium" label={`${t('Expected yield')} (${outputUnit || t('choose stock unit above')})`}><Input required type="number" min="0.0001" step="0.0001" value={data.yield_qty} onChange={(event) => onChange({ ...data, yield_qty: event.target.value })} helperText={t('For example: this mixture produces 20 loaves.')} /></Field>
        {data.lines.map((line, index) => <div key={index} className="grid items-start gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
            <Field className="sm:col-span-2 lg:col-span-1" label={t('Raw material / ingredient')}><Input select required value={line.item_id} onChange={(event) => changeLine(index, { item_id: event.target.value, unit_id: ingredients.find((row) => row.id === event.target.value)?.base_unit_id || '' })}><option value="">{t('Choose ingredient')}</option>{ingredients.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Input></Field>
            <Field label={t('Quantity in mixture')}><Input required type="number" min="0.0001" step="0.0001" value={line.qty} onChange={(event) => changeLine(index, { qty: event.target.value })} /></Field>
            <div><Field label={t('Unit')}><Input select required value={line.unit_id} onChange={(event) => changeLine(index, { unit_id: event.target.value })}><option value="">{t('Choose unit')}</option>{units.map((unit) => <option key={unit.id} value={unit.id}>{unit.symbol}</option>)}</Input></Field>{canCreate && <AddUnit units={units} onCreated={(id) => changeLine(index, { unit_id: id })} />}</div>
            <Button className="justify-self-start sm:col-span-2 lg:col-span-1" type="button" tone="ghost" disabled={data.lines.length === 1} onClick={() => onChange({ ...data, lines: data.lines.filter((_, i) => i !== index) })}>{t('Remove')}</Button>
        </div>)}
        <Button type="button" tone="soft" onClick={() => onChange({ ...data, lines: [...data.lines, { item_id: '', qty: '', unit_id: '' }] })}>{t('Add ingredient')}</Button>
        {!ingredients.length && <p className="text-sm text-muted">{t('First add your raw materials using New product → Ingredient, then Add stock with their purchase costs. They will appear here.')}</p>}
        <details><summary className="cursor-pointer">{t('Labour and overhead (optional)')}</summary><div className="mt-3 grid max-w-2xl gap-3 sm:grid-cols-2">
            <Field label={t('Labour for this mixture')}><Input type="number" min="0" step="0.0001" value={data.labor} onChange={(event) => onChange({ ...data, labor: event.target.value })} /></Field>
            <Field label={t('Overhead for this mixture')}><Input type="number" min="0" step="0.0001" value={data.overhead} onChange={(event) => onChange({ ...data, overhead: event.target.value })} /></Field>
        </div></details>
        <Button type="button" tone="soft" loading={http.processing} onClick={calculate}>{t('Calculate production cost')}</Button>
        <Errors errors={http.errors} />
        {failure && <p role="alert" className="text-sm">{t(failure)}</p>}
        {result && <div aria-live="polite" className="space-y-2 rounded-lg bg-canvas p-3">
            <h4 className="font-semibold">{t('Estimated production cost')}</h4>
            {result.total === null ? <><p>{t('Cost pending')}</p><ul className="list-disc pl-5 text-sm">{result.issues.map((issue, index) => <li key={index}>{issue}</li>)}</ul></> : <dl className="grid gap-3 sm:grid-cols-2">{[[t('Ingredients'), result.material], [t('Labour'), result.labor], [t('Overhead'), result.overhead], [t('Total mixture cost'), result.total], [t('Cost per produced unit'), result.unit_cost]].map(([label, value]) => <div key={label}><dt className="text-sm text-muted">{label}</dt><dd className="font-semibold">{money(value)}</dd></div>)}</dl>}
            <p className="text-sm text-muted">{t('Based on current branch inventory costs and the expected yield. Actual batch usage and waste determine the final cost. Set your selling price separately.')}</p>
        </div>}
    </section>;
}
