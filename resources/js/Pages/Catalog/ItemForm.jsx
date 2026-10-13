import { Link, router, useForm } from '@inertiajs/react';
import { Button, Card, ConfirmButton, Errors, Field, Input, Shell, useT } from '@/lib/ui';
import ProductRecipe from './ProductRecipe';
import AddStock from './AddStock';
import AddUnit from './AddUnit';

export default function ItemForm(props) {
    const { item, variants, barcodes, categories, units, taxes, wholesalePrice, canEdit, canCreate = false, canStart, canArchive } = props;
    const t = useT();
    const form = useForm({
        name: item.name, name_alt: item.name_alt || '', type: item.type,
        category_id: item.category_id || '', base_unit_id: item.base_unit_id || '', tax_rate_id: item.tax_rate_id || '',
        default_price: item.default_price ?? '', wholesale_price: wholesalePrice ?? '',
        production_unit_cost: item.production_unit_cost ?? '', reorder_level: item.reorder_level ?? '',
        make_mode: item.make_mode || 'make_to_stock', negative_stock_policy: item.negative_stock_policy || 'inherit',
        description: item.description || '', track_stock: item.track_stock, variants: variants || [],
        barcodes: (barcodes || []).map((row) => row.barcode),
    });
    form.transform((data) => ({ ...data, wholesale_price: data.type === 'raw_material' ? null : data.wholesale_price }));
    return <Shell title={item.name} actions={<>
        <Button component={Link} href="/catalog/items" tone="ghost">{t('Products')}</Button>
        {item.type === 'finished_good' && canStart && <Button component={Link} href={`/production/batches?item_id=${item.id}`}>{t('Start batch')}</Button>}
    </>}>
        <Card className="p-5">
            <Errors errors={form.errors} />
            <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); form.put(`/catalog/items/${item.id}`); }}>
                <div className="grid gap-4 md:grid-cols-2">
                    <Field label={t('Name')}><Input required disabled={!canEdit} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                    <Field label={t('SKU')}><Input disabled value={item.sku} /></Field>
                    <Field label={t('Type')}><Input select disabled={!canEdit} value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}><option value="finished_good">{t('Produced')}</option><option value="trading_good">{t('Traded')}</option><option value="service">{t('Service')}</option><option value="raw_material">{t('Ingredient')}</option></Input></Field>
                    <Field label={t('Category')}><Input select disabled={!canEdit} value={form.data.category_id} onChange={(event) => form.setData('category_id', event.target.value)}><option value="">{t('None')}</option>{categories.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Input></Field>
                    {form.data.type !== 'service' && <div><Field label={t('Stock unit')}><Input select required disabled={!canEdit} value={form.data.base_unit_id} onChange={(event) => form.setData('base_unit_id', event.target.value)}><option value="">{t('Choose unit')}</option>{units.map((row) => <option key={row.id} value={row.id}>{row.name} ({row.symbol})</option>)}</Input></Field>{canCreate && canEdit && <AddUnit units={units} onCreated={(id) => form.setData('base_unit_id', id)} />}</div>}
                    {form.data.type === 'raw_material' ? <Field label={t('Price')}><Input disabled={!canEdit} type="number" min="0" step="0.0001" value={form.data.default_price} onChange={(event) => form.setData('default_price', event.target.value)} /></Field> : <Field label={t('Selling price')}><Input required disabled={!canEdit} type="number" min="0" step="0.0001" value={form.data.default_price} onChange={(event) => form.setData('default_price', event.target.value)} /></Field>}
                    {form.data.type === 'raw_material' && <p className="text-sm text-muted">{t('Record ingredient purchase costs with Add stock below. Recipes use these inventory costs to calculate the cost of produced goods.')}</p>}
                </div>
                <details><summary className="cursor-pointer">{t(form.data.type === 'raw_material' ? 'More ingredient details' : 'More product details and pricing')}</summary><div className="mt-4 grid gap-4 md:grid-cols-2">
                    {form.data.type !== 'raw_material' && <Field label={t('Wholesale price (optional)')}><Input disabled={!canEdit} type="number" min="0" step="0.0001" value={form.data.wholesale_price} onChange={(event) => form.setData('wholesale_price', event.target.value)} helperText={t('Uses the existing wholesale price list and customer pricing rules.')} /></Field>}
                    <Field label={t('Tax')}><Input select disabled={!canEdit} value={form.data.tax_rate_id} onChange={(event) => form.setData('tax_rate_id', event.target.value)}><option value="">{t('Default')}</option>{taxes.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Input></Field>
                    <Field label={t('Reorder level')}><Input disabled={!canEdit} type="number" min="0" step="0.0001" value={form.data.reorder_level} onChange={(event) => form.setData('reorder_level', event.target.value)} /></Field>
                    <Field label={t('Description')}><Input disabled={!canEdit} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} /></Field>
                    {props.legacyCostNeeded && <Field label={t('Unit cost for an existing batch without a recipe')}><Input disabled={!canEdit} type="number" min="0.0001" step="0.0001" value={form.data.production_unit_cost} onChange={(event) => form.setData('production_unit_cost', event.target.value)} helperText={t('Used only to finish historical batches started without a recipe. New batches use recipes.')} /></Field>}
                    {item.make_mode === 'make_to_order' && <p className="text-sm text-muted">{t('This existing product uses make to order. Sales remain Pending costing until production cost is assigned.')}</p>}
                </div></details>
                {canEdit && <Button type="submit" loading={form.processing}>{t(form.data.type === 'raw_material' ? 'Save ingredient' : 'Save product')}</Button>}
            </form>
        </Card>
        {item.type === 'finished_good' && props.canViewRecipe && <ProductRecipe {...props} canCreate={canCreate} />}
        {['trading_good', 'raw_material'].includes(item.type) && props.canViewStock && <AddStock {...props} />}
        {canArchive && <div className="mt-5"><ConfirmButton tone="danger" title={t(item.type === 'raw_material' ? 'Archive this ingredient?' : 'Archive this product?')} description={t('Existing stock movements, recipes and sales history will be kept.')} onConfirm={() => router.delete(`/catalog/items/${item.id}`)}>{t('Archive')}</ConfirmButton></div>}
    </Shell>;
}
