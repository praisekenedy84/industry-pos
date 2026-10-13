import { useT } from '@/lib/ui';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Accordion, AccordionDetails, AccordionSummary, Box, Chip, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, InputAdornment, Stack, Typography } from '@mui/material';
import { Button, Card, ConfirmButton, Errors, Field, Input, money, Pager, rowsOf, Shell, Table } from '@/lib/ui';
import { ChevronDown, Plus, Package, Search, Upload, X } from 'lucide-react';
import AddUnit from './AddUnit';
import NewProductRecipe from './NewProductRecipe';

export default function Items({ items, filters, categories = [], units = [], ingredients = [], canRecipe, canCreate, canEdit, canArchive, canStart, canReceive }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', type: 'finished_good', default_price: '', wholesale_price: '', category_id: '', base_unit_id: '', track_stock: true, recipe: { yield_qty: '', lines: [{ item_id: '', qty: '', unit_id: '' }], labor: '', overhead: '' } });
    form.transform((data) => ({ ...data, wholesale_price: data.type === 'raw_material' ? null : data.wholesale_price, recipe: data.type === 'finished_good' && canRecipe ? data.recipe : null }));
    const rows = rowsOf(items);
    const close = () => { if (!form.processing) setOpen(false); };
    return (
        <Shell title={t("Products")} actions={canCreate && <><Button tone="soft" onClick={() => { form.reset(); form.setData('type', 'raw_material'); setOpen(true); }} startIcon={<Plus size={17} />}>{t('New ingredient')}</Button><Button onClick={() => { form.reset(); setOpen(true); }} startIcon={<Plus size={17} />}>{t("New product")}</Button></>}>
            <Typography variant="body2" color="text.secondary" sx={{ mt: -1, mb: 3 }}>{t("Manage the products, materials and services in your workspace.")}</Typography>
            <Card sx={{ mb: 3 }}>
                <Stack component="form" direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ p: 2.5, alignItems: { sm: 'center' } }} onSubmit={(event) => { event.preventDefault(); const values = new FormData(event.currentTarget); router.get('/catalog/items', { q: values.get('q'), type: values.get('type'), archived: values.get('archived') }, { preserveState: true }); }}>
                    <Input label={t("Search by name or SKU")} name="q" defaultValue={filters.q || ''} sx={{ flex: 1 }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search size={18} /></InputAdornment> } }} />
                    <Input select label={t("Type")} name="type" defaultValue={filters.type || ''} sx={{ minWidth: 185 }}><option value="">{t("All types")}</option><option value="raw_material">{t("Ingredient")}</option><option value="finished_good">{t("Produced")}</option><option value="trading_good">{t("Traded")}</option><option value="service">{t("Service")}</option></Input>
                    <Button icon="filter" type="submit" tone="soft">{t("Apply filters")}</Button>
                    <Input select label={t('Status')} name="archived" defaultValue={filters.archived ? '1' : '0'}><option value="0">{t('Active')}</option><option value="1">{t('Archived')}</option></Input>
                    {(filters.q || filters.type) && <Button icon="reset" component={Link} href="/catalog/items" tone="ghost">{t("Reset")}</Button>}
                </Stack>
                <Table rows={rows} empty={t("No items found. Try a different filter or add your first item.")} columns={[
                    { key: 'name', label: t("Item"), render: (item) => item.deleted_at ? item.name : <Link href={`/catalog/items/${item.id}/edit`} className="flex items-center gap-3 font-medium hover:text-link"><span className="rounded-lg bg-canvas p-2 text-muted"><Package size={17} /></span>{item.name}</Link> },
                    { key: 'sku', label: t("SKU"), render: (item) => <Typography variant="body2" color="text.secondary">{item.sku || '—'}</Typography> },
                    { key: 'type', label: t("Type"), render: (item) => <Chip label={t({ finished_good: 'Produced', trading_good: 'Traded', service: 'Service', raw_material: 'Ingredient' }[item.type] || item.type)} size="small" variant="outlined" /> },
                    { key: 'default_price', label: t("Price"), render: (item) => <Typography variant="body2" sx={{ whiteSpace: 'nowrap', fontWeight: 600 }}>{money(item.default_price)}</Typography> },
                    { key: 'action', label: t('Actions'), render: (item) => <div className="flex flex-wrap gap-2">{item.deleted_at ? canArchive && <Button tone="soft" onClick={() => router.post(`/catalog/items/${item.id}/restore`)}>{t('Restore')}</Button> : <>
                        <Button component={Link} href={`/catalog/items/${item.id}/edit`} tone="ghost">{t(canEdit ? 'Edit' : 'View')}</Button>
                        {item.type === 'finished_good' && canStart && <Button component={Link} href={`/production/batches?item_id=${item.id}`} tone="soft">{t('Start batch')}</Button>}
                        {['trading_good', 'raw_material'].includes(item.type) && canReceive && <Button component={Link} href={`/catalog/items/${item.id}/edit#stock`} tone="soft">{t('Add stock')}</Button>}
                        {canArchive && <ConfirmButton tone="danger" title={t('Archive this item?')} description={t('Remove it from active lists. Stock, recipes and sales history remain saved. You can restore it from Archived items.')} onConfirm={() => router.delete(`/catalog/items/${item.id}`)}>{t('Archive')}</ConfirmButton>}
                    </>}</div> },
                ]} />
                <Pager paginator={items} />
            </Card>
            <Dialog open={open} onClose={close} aria-labelledby="new-item-title" fullWidth maxWidth="md">
                <Box component="form" onSubmit={(event) => { event.preventDefault(); form.post('/catalog/items', { onSuccess: () => { setOpen(false); form.reset(); } }); }}>
                    <DialogTitle id="new-item-title">{t(form.data.type === 'raw_material' ? 'New ingredient' : 'New product')}</DialogTitle>
                    <IconButton aria-label={t("Close new item")} disabled={form.processing} onClick={close} sx={{ position: 'absolute', top: 10, right: 12 }}><X size={20} /></IconButton>
                    <DialogContent dividers>
                        <Errors errors={form.errors} />
                        <Box className="grid items-start gap-6 pt-1 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                            <Field label={t(form.data.type === 'raw_material' ? 'Ingredient name' : 'Product name')} error={form.errors.name}><Input autoFocus required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                            <Field label={t("Type")} error={form.errors.type}><Input select value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}><option value="raw_material">{t("Ingredient")}</option><option value="finished_good">{t("Produced")}</option><option value="trading_good">{t("Traded")}</option><option value="service">{t("Service")}</option></Input></Field>
                            <Field label={t('Category')}><Input select value={form.data.category_id} onChange={(event) => form.setData('category_id', event.target.value)}><option value="">{t('None')}</option>{categories.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</Input></Field>
                            {form.data.type !== 'service' && <div><Field label={t('Stock unit')} error={form.errors.base_unit_id}><Input select required value={form.data.base_unit_id} onChange={(event) => form.setData('base_unit_id', event.target.value)}><option value="">{t('Choose unit')}</option>{units.map((row) => <option key={row.id} value={row.id}>{row.name} ({row.symbol})</option>)}</Input></Field>{canCreate && <AddUnit units={units} onCreated={(id) => form.setData('base_unit_id', id)} />}</div>}
                            {form.data.type === 'finished_good' && (canRecipe ? <NewProductRecipe data={form.data.recipe} onChange={(recipe) => form.setData('recipe', recipe)} ingredients={ingredients} units={units} canCreate={canCreate} outputUnit={units.find((unit) => unit.id === form.data.base_unit_id)?.symbol} /> : <p className="text-sm text-muted sm:col-span-2">{t('Recipe setup requires recipe management access and the production module. Ask your administrator to enable them.')}</p>)}
                            {form.data.type === 'raw_material' ? <Field label={t('Price')} error={form.errors.default_price}><Input type="number" min="0" step="0.0001" inputMode="decimal" value={form.data.default_price} onChange={(event) => form.setData('default_price', event.target.value)} /></Field> : <Field label={t("Selling price (TZS)")} error={form.errors.default_price}><Input required type="number" min="0" step="0.0001" inputMode="decimal" value={form.data.default_price} onChange={(event) => form.setData('default_price', event.target.value)} /></Field>}
                            {form.data.type === 'raw_material' && <p className="text-sm text-muted sm:col-span-2">{t('Save this ingredient, then use Add stock to record its quantity and purchase cost. Select it in a produced product’s recipe to calculate production cost.')}</p>}
                            {form.data.type !== 'raw_material' && <details><summary className="cursor-pointer">{t('Wholesale price (optional)')}</summary><Stack spacing={2} sx={{ mt: 2 }}><Field label={t('Wholesale price')}><Input type="number" min="0" step="0.0001" value={form.data.wholesale_price} onChange={(event) => form.setData('wholesale_price', event.target.value)} /></Field></Stack></details>}
                        </Box>
                    </DialogContent>
                    <DialogActions sx={{ p: 2.5 }}><Button icon="cancel" tone="ghost" disabled={form.processing} onClick={close}>{t("Cancel")}</Button><Button icon="save" type="submit" loading={form.processing}>{t(form.data.type === 'raw_material' ? 'Save ingredient' : 'Save product')}</Button></DialogActions>
                </Box>
            </Dialog>
            {canCreate && <Import />}
            <details className="mt-5"><summary className="cursor-pointer text-sm text-muted">{t('Product settings')}</summary><div className="mt-3 flex flex-wrap gap-3"><Button component={Link} href="/catalog/categories" tone="ghost">{t('Categories')}</Button><Button component={Link} href="/catalog/units" tone="ghost">{t('Units and conversions')}</Button></div></details>
        </Shell>
    );
}

function Import() {
    const t = useT();
    const form = useForm({ csv: 'name,type,sku,price\n', dry_run: true });
    return (
        <Accordion variant="outlined" disableGutters sx={{ borderRadius: '12px !important', '&:before': { display: 'none' } }}>
            <AccordionSummary expandIcon={<ChevronDown size={18} />}><Upload size={18} className="mr-3" /><Typography variant="body2" sx={{ fontWeight: 600 }}>{t("Import items from CSV")}</Typography></AccordionSummary>
            <AccordionDetails>
                <Stack component="form" spacing={2.5} onSubmit={(event) => { event.preventDefault(); form.post('/catalog/import'); }}>
                    <Typography variant="body2" color="text.secondary">{t("Paste your CSV below. Use a dry run to validate the data before importing.")}</Typography>
                    <Errors errors={form.errors} />
                    <Field label={t("CSV data")} error={form.errors.csv}><Input multiline rows={5} value={form.data.csv} onChange={(event) => form.setData('csv', event.target.value)} /></Field>
                    <label className="text-sm"><Input type="checkbox" checked={form.data.dry_run} onChange={(event) => form.setData('dry_run', event.target.checked)} />{t("Validate only (dry run)")}</label>
                    <Box><Button icon={form.data.dry_run ? "validate" : "import"} type="submit" tone="soft" loading={form.processing}>{form.data.dry_run ? 'Validate CSV' : 'Import items'}</Button></Box>
                </Stack>
            </AccordionDetails>
        </Accordion>
    );
}
