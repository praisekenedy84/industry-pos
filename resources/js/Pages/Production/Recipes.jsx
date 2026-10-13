import { useT } from '@/lib/ui';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Box, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, Stack, Typography } from '@mui/material';
import { X } from 'lucide-react';
import { Button, Card, Errors, Field, Input, money, Shell } from '@/lib/ui';
import AddUnit from '@/Pages/Catalog/AddUnit';

function lineAmount(qty, unitCost) {
    const quantity = Number(qty);
    const cost = Number(unitCost);
    if (!Number.isFinite(quantity) || !Number.isFinite(cost)) {
        return 0;
    }

    return quantity * cost;
}

function knownCost(item) {
    return Number(item?.unit_cost) > 0 ? String(item.unit_cost) : '';
}

export default function Recipes({ recipes, items, units, canCreateItems = false, canSetPrices = false }) {
    const t = useT();
    const finished = items.filter((item) => item.track_stock && item.type !== 'service' && item.base_unit_id);
    const materials = items.filter((item) => item.track_stock && item.type === 'raw_material' && item.base_unit_id);
    const blankLine = () => ({
        mode: materials[0] ? 'existing' : 'new',
        item_id: materials[0]?.id || '',
        name: '',
        base_unit_id: units[0]?.id || '',
        qty: '1',
        unit_cost: knownCost(materials[0]),
    });
    const [open, setOpen] = useState(false);
    const [outputMode, setOutputMode] = useState(canCreateItems ? 'new' : 'existing');
    const [lines, setLines] = useState([blankLine()]);
    const form = useForm({
        name: '',
        item_id: finished[0]?.id || '',
        item_name: '',
        base_unit_id: units[0]?.id || '',
        yield_qty: '1',
        labor: '',
        retail_price: '',
        wholesale_price: '',
    });
    const itemName = outputMode === 'new' ? form.data.item_name : (finished.find((item) => item.id === form.data.item_id)?.name || '');
    const mixtureCost = lines.reduce((total, line) => total + lineAmount(line.qty, line.unit_cost), 0);
    const laborCost = Number(form.data.labor) || 0;
    const expectedOutput = Number(form.data.yield_qty);
    const costPerPiece = expectedOutput > 0 ? (mixtureCost + laborCost) / expectedOutput : null;
    const close = () => {
        if (form.processing) {
            return;
        }
        setOpen(false);
        setOutputMode(canCreateItems ? 'new' : 'existing');
        setLines([blankLine()]);
        form.reset();
        form.clearErrors();
    };
    const updateLine = (index, patch) => setLines(lines.map((row, i) => i === index ? { ...row, ...patch } : row));
    const submit = (event) => {
        event.preventDefault();
        const payload = {
            name: outputMode === 'new' ? form.data.item_name : form.data.name,
            yield_qty: form.data.yield_qty,
            lines: lines.map((line) => line.mode === 'new'
                ? { name: line.name, base_unit_id: line.base_unit_id, qty: line.qty, unit_cost: line.unit_cost || '0' }
                : { item_id: line.item_id, qty: line.qty, unit_cost: line.unit_cost || '0' }),
        };
        if (outputMode === 'new') {
            payload.item = { name: form.data.item_name, base_unit_id: form.data.base_unit_id };
        } else {
            payload.item_id = form.data.item_id;
        }
        if (form.data.labor !== '') {
            payload.labor = form.data.labor;
        }
        if (form.data.retail_price !== '') {
            payload.retail_price = form.data.retail_price;
        }
        if (form.data.wholesale_price !== '') {
            payload.wholesale_price = form.data.wholesale_price;
        }
        form.transform(() => payload);
        form.post('/production/recipes');
    };

    return (
        <Shell title={t("Recipes")} actions={<Button icon="add" onClick={() => setOpen(true)}>{t("New bill of materials")}</Button>}>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>{t("A bill of materials lists the inputs and expected output for a good made in-house.")}</Typography>
            <Card className="divide-y divide-hairline text-sm">
                {recipes.length ? recipes.map((recipe) => <Link key={recipe.id} href={`/production/recipes/${recipe.id}`} className="block px-4 py-3">{recipe.name} · v{recipe.current_version}</Link>) : <Typography variant="body2" color="text.secondary" sx={{ px: 3, py: 7, textAlign: 'center' }}>{t("No bills of materials yet.")}</Typography>}
            </Card>
            <Dialog open={open} onClose={close} maxWidth="md" aria-labelledby="bill-of-materials-title">
                <Box component="form" onSubmit={submit}>
                    <DialogTitle id="bill-of-materials-title">{t("New bill of materials")}</DialogTitle>
                    <IconButton type="button" aria-label={t("Close bill of materials")} disabled={form.processing} onClick={close} sx={{ position: 'absolute', top: 10, right: 12 }}><X size={20} /></IconButton>
                    <DialogContent dividers>
                        <Errors errors={form.errors} />
                        <Stack spacing={3} sx={{ pt: 1 }}>
                            <Stack spacing={2}>
                                <Typography variant="subtitle2">{t("Item being made")}</Typography>
                                <Field error={form.errors.item_id} label={t("Product")}>
                                    <Input select required value={outputMode === 'new' ? '__new__' : form.data.item_id} onChange={(event) => {
                                        if (event.target.value === '__new__') {
                                            setOutputMode('new');
                                            return;
                                        }
                                        const item = finished.find((row) => row.id === event.target.value);
                                        setOutputMode('existing');
                                        form.setData({ ...form.data, item_id: event.target.value, name: form.data.name || item?.name || '' });
                                    }}>
                                        {canCreateItems && <option value="__new__">{t("Create new item")}</option>}
                                        {finished.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                                    </Input>
                                </Field>
                                {outputMode === 'new' ? (
                                    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                                        <Field error={form.errors['item.name'] || form.errors.name} label={t("Item name")}><Input autoFocus required value={form.data.item_name} onChange={(event) => form.setData('item_name', event.target.value)} /></Field>
                                        <div><Field error={form.errors['item.base_unit_id']} label={t("Unit")}><Input select required value={form.data.base_unit_id} onChange={(event) => form.setData('base_unit_id', event.target.value)}>{units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name} ({unit.symbol})</option>)}</Input></Field>{canCreateItems && <AddUnit units={units} onCreated={(id) => form.setData('base_unit_id', id)} />}</div>
                                    </Stack>
                                ) : (
                                    <Field error={form.errors.name} label={t("Bill name")}><Input autoFocus required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                                )}
                            </Stack>
                            <Stack spacing={2}>
                                <Typography variant="subtitle2">{t("Mixture")}</Typography>
                                {lines.map((line, index) => (
                                    <Stack key={index} spacing={2} sx={{ p: 2, border: 1, borderColor: 'divider', borderRadius: 1 }}>
                                        <Field error={form.errors[`lines.${index}.item_id`]} label={t("Raw material")}>
                                            <Input select required value={line.mode === 'new' ? '__new__' : line.item_id} onChange={(event) => {
                                                if (event.target.value === '__new__') {
                                                    updateLine(index, { mode: 'new', item_id: '', unit_cost: '' });
                                                    return;
                                                }
                                                const material = materials.find((item) => item.id === event.target.value);
                                                updateLine(index, { mode: 'existing', item_id: event.target.value, name: '', unit_cost: knownCost(material) });
                                            }}>
                                                {canCreateItems && <option value="__new__">{t("New raw material")}</option>}
                                                {materials.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                                            </Input>
                                        </Field>
                                        {line.mode === 'new' && (
                                            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                                                <Field error={form.errors[`lines.${index}.name`]} label={t("Raw material name")}><Input required value={line.name} onChange={(event) => updateLine(index, { name: event.target.value })} /></Field>
                                                <div><Field error={form.errors[`lines.${index}.base_unit_id`]} label={t("Unit")}><Input select required value={line.base_unit_id} onChange={(event) => updateLine(index, { base_unit_id: event.target.value })}>{units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name} ({unit.symbol})</option>)}</Input></Field>{canCreateItems && <AddUnit units={units} onCreated={(id) => updateLine(index, { base_unit_id: id })} />}</div>
                                            </Stack>
                                        )}
                                        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ alignItems: { sm: 'flex-end' } }}>
                                            <Field error={form.errors[`lines.${index}.qty`]} label={t("Quantity")}><Input type="number" min="0" step="any" required value={line.qty} onChange={(event) => updateLine(index, { qty: event.target.value })} /></Field>
                                            <Field error={form.errors[`lines.${index}.unit_cost`]} label={t("Unit price")}><Input type="number" min="0" step="any" required value={line.unit_cost} onChange={(event) => updateLine(index, { unit_cost: event.target.value })} /></Field>
                                            <Typography variant="body2" sx={{ pb: { sm: 1 }, whiteSpace: 'nowrap' }}>{money(lineAmount(line.qty, line.unit_cost))}</Typography>
                                            {lines.length > 1 && <Button icon="delete" tone="ghost" onClick={() => setLines(lines.filter((_, i) => i !== index))}>{t("Remove")}</Button>}
                                        </Stack>
                                    </Stack>
                                ))}
                                {form.errors.lines && <Typography variant="body2" color="error">{form.errors.lines}</Typography>}
                                <Box><Button icon="add" type="button" tone="ghost" onClick={() => setLines([...lines, blankLine()])}>{t("Add ingredient")}</Button></Box>
                            </Stack>
                            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                                <Field error={form.errors.yield_qty} label={t("Expected output quantity")}><Input type="number" min="0" step="any" required value={form.data.yield_qty} onChange={(event) => form.setData('yield_qty', event.target.value)} /></Field>
                                <Field error={form.errors.labor} label={t("Labor (optional)")}><Input type="number" min="0" step="any" value={form.data.labor} onChange={(event) => form.setData('labor', event.target.value)} /></Field>
                            </Stack>
                            <Box sx={{ p: 2, borderRadius: 1, bgcolor: 'action.hover' }}>
                                <Typography variant="body2">{t("Item being made")}: {itemName || t("Not named yet")}</Typography>
                                <Typography variant="body2">{t("Money used in the mixture")}: {money(mixtureCost)}</Typography>
                                {laborCost > 0 && <Typography variant="body2">{t("Labor included in the piece cost")}: {money(laborCost)}</Typography>}
                                <Typography variant="body2" sx={{ fontWeight: 600 }}>{t("Cost price per piece")}: {costPerPiece === null ? '—' : money(costPerPiece)}</Typography>
                            </Box>
                            {canSetPrices && (
                                <Stack spacing={2}>
                                    <Typography variant="subtitle2">{t("Selling price")}</Typography>
                                    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                                        <Field error={form.errors.retail_price} label={t("Retail")}><Input type="number" min="0" step="any" value={form.data.retail_price} onChange={(event) => form.setData('retail_price', event.target.value)} /></Field>
                                        <Field error={form.errors.wholesale_price} label={t("Wholesale")}><Input type="number" min="0" step="any" value={form.data.wholesale_price} onChange={(event) => form.setData('wholesale_price', event.target.value)} /></Field>
                                    </Stack>
                                </Stack>
                            )}
                        </Stack>
                    </DialogContent>
                    <DialogActions sx={{ p: 2.5 }}>
                        <Button icon="cancel" tone="ghost" disabled={form.processing} onClick={close}>{t("Cancel")}</Button>
                        <Button icon="add" type="submit" loading={form.processing}>{t("Create bill of materials")}</Button>
                    </DialogActions>
                </Box>
            </Dialog>
        </Shell>
    );
}
