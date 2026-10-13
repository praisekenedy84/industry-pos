import { useT } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { Box, Chip, FormControlLabel, Stack, Switch, Typography } from '@mui/material';
import { Button, Card, Errors, Field, Input, Shell } from '@/lib/ui';

export default function Index({ settings, taxes, methods, tenant, templates = [] }) {
    const t = useT();
    const value = (key, fallback) => {
        const raw = settings[key];
        if (raw && typeof raw === 'object' && 'value' in raw) return raw.value;
        return raw ?? fallback;
    };
    const form = useForm({
        prices_include_tax: !!value('prices_include_tax', true),
        negative_stock: value('negative_stock', 'block'),
        valuation: value('valuation', 'weighted_average'),
        production_track_materials: !!value('production_track_materials', true),
        production_waste_treatment: value('production_waste_treatment', 'absorbed'),
        production_account_finished_inventory: value('production_account_finished_inventory', 'finished_inventory'),
        production_account_raw_material_inventory: value('production_account_raw_material_inventory', 'raw_material_inventory'),
        production_account_production_loss: value('production_account_production_loss', 'production_loss'),
        production_account_production_clearing: value('production_account_production_clearing', 'production_clearing'),
        idle_timeout: value('idle_timeout', 30),
        pos_idle_timeout: value('pos_idle_timeout', 15),
        receipt_footer: value('receipt_footer', ''),
        default_locale: tenant?.default_locale || 'en',
        receipt_language: tenant?.receipt_language || 'bilingual',
        industry_key: tenant?.industry_key || 'general',
    });
    return (
        <Shell title={t("Workspace settings")} actions={<Button icon="save" type="submit" form="workspace-settings" loading={form.processing}>{t("Save settings")}</Button>}>
            <Errors errors={form.errors} />
            <Box component="form" id="workspace-settings" onSubmit={(event) => { event.preventDefault(); form.put('/settings', { onSuccess: () => form.setDefaults() }); }} sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', lg: '1fr 1fr' }, gap: 3, alignItems: 'start' }}>
                <Card sx={{ p: 3 }}>
                    <Typography component="h2" variant="h6">{t("Language & receipts")}</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 3 }}>{t("Set the default experience for your workspace and customers.")}</Typography>
                    <Stack spacing={3}>
                        <Field label={t("Industry template")} error={form.errors.industry_key}><Input select value={form.data.industry_key} onChange={(event) => form.setData('industry_key', event.target.value)} helperText={t("The screens stay the same. Words such as ingredient, recipe, and batch change to match this industry.")}>{templates.map((template) => <option key={template.key} value={template.key}>{template.name}</option>)}</Input></Field>
                        <Field label={t("Workspace language")} error={form.errors.default_locale}><Input select value={form.data.default_locale} onChange={(event) => form.setData('default_locale', event.target.value)}><option value="en">{t("English")}</option><option value="sw">{t("Kiswahili")}</option></Input></Field>
                        <Field label={t("Receipt footer")} error={form.errors.receipt_footer}><Input multiline rows={3} helperText={t("Shown at the bottom of customer receipts.")} value={form.data.receipt_footer} onChange={(event) => form.setData('receipt_footer', event.target.value)} /></Field>
                    </Stack>
                </Card>
                <Card sx={{ p: 3 }}>
                    <Typography component="h2" variant="h6">{t("Inventory policies")}</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 3 }}>{t("Control how stock is valued and how shortages are handled.")}</Typography>
                    <Stack spacing={3}>
                        <Field label={t("When stock goes below zero")} error={form.errors.negative_stock}><Input select value={form.data.negative_stock} onChange={(event) => form.setData('negative_stock', event.target.value)}><option value="block">{t("Block the transaction")}</option><option value="warn">{t("Show a warning")}</option><option value="allow">{t("Allow the transaction")}</option></Input></Field>
                        <Field label={t("Stock valuation")} error={form.errors.valuation}><Input select value={form.data.valuation} onChange={(event) => form.setData('valuation', event.target.value)}><option value="weighted_average">{t("Weighted average")}</option><option value="fifo">{t("First in, first out (FIFO)")}</option></Input></Field>
                    </Stack>
                </Card>
                <Card sx={{ p: 3 }}>
                    <Typography component="h2" variant="h6">{t('Production costing')}</Typography>
                    <Stack spacing={3} sx={{ mt: 3 }}>
                        <FormControlLabel control={<Switch checked={form.data.production_track_materials} onChange={(event) => form.setData('production_track_materials', event.target.checked)} />} label={t('Deduct recipe materials from stock')} />
                        <Field label={t('Waste accounting treatment')} error={form.errors.production_waste_treatment}><Input select value={form.data.production_waste_treatment} onChange={(event) => form.setData('production_waste_treatment', event.target.value)}><option value="absorbed">{t('Absorb normal waste into finished inventory')}</option><option value="expensed">{t('Expense waste as production loss')}</option></Input></Field>
                        <Typography variant="body2" color="text.secondary">{t('An entirely wasted batch is always a production loss. Material tracking changes apply to new batches.')}</Typography>
                        {['finished_inventory', 'raw_material_inventory', 'production_loss', 'production_clearing'].map((account) => <Field key={account} label={t(`production.account.${account}`, account.replaceAll('_', ' '))} error={form.errors[`production_account_${account}`]}><Input required value={form.data[`production_account_${account}`]} onChange={(event) => form.setData(`production_account_${account}`, event.target.value)} /></Field>)}
                    </Stack>
                </Card>
                <Card sx={{ p: 3 }}>
                    <Typography component="h2" variant="h6">{t("Session security")}</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 3 }}>{t("Choose when inactive sessions lock automatically.")}</Typography>
                    <Stack spacing={3}>
                        <Field label={t("Workspace idle timeout (minutes)")} error={form.errors.idle_timeout}><Input type="number" min="5" max="240" helperText={t("Between 5 and 240 minutes.")} value={form.data.idle_timeout} onChange={(event) => form.setData('idle_timeout', event.target.value)} /></Field>
                        <Field label={t("POS idle timeout (minutes)")} error={form.errors.pos_idle_timeout}><Input type="number" min="1" max="60" helperText={t("Between 1 and 60 minutes.")} value={form.data.pos_idle_timeout} onChange={(event) => form.setData('pos_idle_timeout', event.target.value)} /></Field>
                    </Stack>
                </Card>
                <Card sx={{ p: 3 }}>
                    <Typography component="h2" variant="h6">{t("Tax & payments")}</Typography>
                    <FormControlLabel sx={{ mt: 1, mb: 2 }} control={<Switch checked={form.data.prices_include_tax} onChange={(event) => form.setData('prices_include_tax', event.target.checked)} />} label={<Typography variant="body2">{t("Prices include VAT")}</Typography>} />
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>{t("Configured taxes")}</Typography>
                    <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap', mb: 3 }}>{taxes.length ? taxes.map((tax) => <Chip key={tax.id || tax.name} label={tax.name} size="small" variant="outlined" />) : <Typography variant="body2" color="text.secondary">{t("No taxes configured.")}</Typography>}</Stack>
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>{t("Payment methods")}</Typography>
                    <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>{methods.map((method) => <Chip key={method.key || method.id} label={method.name} size="small" variant="outlined" />)}</Stack>
                </Card>
                <Stack direction="row" sx={{ gridColumn: '1 / -1', justifyContent: 'flex-end', alignItems: 'center', gap: 2 }}><Typography variant="caption" color="text.secondary">{form.isDirty ? 'You have unsaved changes.' : 'Settings are up to date.'}</Typography><Button icon="save" type="submit" loading={form.processing}>{t("Save settings")}</Button></Stack>
            </Box>
        </Shell>
    );
}
