import { useForm } from '@inertiajs/react';
import { Button, Card, Field, Input, Shell, useT } from '@/lib/ui';
import RecordActions from '@/lib/RecordActions';
import AddUnit from './AddUnit';

function formatFactor(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return value;
    }

    return new Intl.NumberFormat('en-TZ', { maximumFractionDigits: 6 }).format(number);
}

export default function Units({ units, conversions, canCreate, canEdit, canDelete }) {
    const t = useT();
    const conversion = useForm({ from_unit_id: '', to_unit_id: '', factor: '' });
    const submitConversion = (event) => {
        event.preventDefault();
        conversion.post('/catalog/units/conversions', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => conversion.reset(),
        });
    };

    return (
        <Shell title={t('Units')}>
            <p className="mb-4 text-sm text-muted">{t('Add a unit of measure, then choose it when you create a product, ingredient, or recipe. A conversion lets those units be used together.')}</p>
            {canCreate && <AddUnit inline units={units} />}
            <Card className="mb-6 divide-y divide-hairline text-sm">
                {units.length ? units.map((unit) => <div key={unit.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"><span>{unit.name} ({unit.symbol})</span><RecordActions record={unit} path={`/catalog/units/${unit.id}`} canEdit={canEdit} canDelete={canDelete} permanent fields={[{ name: 'name', label: 'Name', required: true }, { name: 'symbol', label: 'Symbol', required: true }]} /></div>) : <p className="px-4 py-6 text-muted">{t('No units yet. Add one before creating a stocked product.')}</p>}
            </Card>
            <h2 className="mb-2 text-lg font-semibold">{t('Conversions')}</h2>
            <Card className="mb-4 divide-y divide-hairline text-sm">
                {conversions.length ? conversions.map((row) => <p key={row.id} className="px-4 py-3">1 {row.from_symbol} = {formatFactor(row.factor)} {row.to_symbol}</p>) : <p className="px-4 py-6 text-muted">{t('No conversions yet. Add one when a recipe quantity uses a different unit from the ingredient stock unit.')}</p>}
            </Card>
            {canCreate && units.length > 1 && <form className="grid gap-3 md:grid-cols-3" onSubmit={submitConversion}>
                <Field label={t('First unit')} error={conversion.errors.from_unit_id}><Input select required value={conversion.data.from_unit_id} onChange={(event) => conversion.setData('from_unit_id', event.target.value)}><option value="">{t('Choose unit')}</option>{units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name} ({unit.symbol})</option>)}</Input></Field>
                <Field label={t('Equals this many')} error={conversion.errors.factor}><Input required type="number" min="0.000001" step="0.000001" decimals={6} value={conversion.data.factor} onChange={(event) => conversion.setData('factor', event.target.value)} helperText={t('1 of the first unit equals this many of the second.')} /></Field>
                <Field label={t('Second unit')} error={conversion.errors.to_unit_id}><Input select required value={conversion.data.to_unit_id} onChange={(event) => conversion.setData('to_unit_id', event.target.value)}><option value="">{t('Choose unit')}</option>{units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name} ({unit.symbol})</option>)}</Input></Field>
                <Button icon="add" type="submit" loading={conversion.processing}>{t('Save conversion')}</Button>
            </form>}
        </Shell>
    );
}
