import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Dialog, DialogActions, DialogContent, DialogTitle } from '@mui/material';
import { Button, Errors, Field, Input, useT } from '@/lib/ui';

export default function AddUnit({ units, onCreated, inline = false }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', symbol: '', from_unit_id: '', factor: '' });
    const close = () => {
        if (!form.processing) {
            setOpen(false);
        }
    };
    const submit = (event) => {
        event.preventDefault();
        form.post('/catalog/units', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const id = page.props.flash?.created_unit_id;
                form.reset();
                setOpen(false);
                if (id) {
                    onCreated?.(id);
                }
            },
        });
    };
    const fields = (
        <div className="space-y-4">
            <Errors errors={form.errors} />
            <div className="grid gap-3 md:grid-cols-2">
                <Field label={t('Name')} error={form.errors.name}><Input required autoFocus={open} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <Field label={t('Symbol')} error={form.errors.symbol}><Input required maxLength={12} value={form.data.symbol} onChange={(event) => form.setData('symbol', event.target.value)} helperText={t('Short label shown on stock and recipes, such as kg or pc.')} /></Field>
            </div>
            <div className="grid gap-3 md:grid-cols-2">
                <Field label={t('One existing unit equals')} error={form.errors.from_unit_id}><Input select value={form.data.from_unit_id} onChange={(event) => form.setData('from_unit_id', event.target.value)}><option value="">{t('No conversion')}</option>{units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name} ({unit.symbol})</option>)}</Input></Field>
                <Field label={t('This many of the new unit')} error={form.errors.factor}><Input type="number" min="0.000001" step="0.000001" value={form.data.factor} onChange={(event) => form.setData('factor', event.target.value)} helperText={t('Example: choose kilogram and enter 1000 to add gram.')} /></Field>
            </div>
        </div>
    );
    if (inline) {
        return (
            <form className="mb-6 space-y-4" onSubmit={submit}>
                {fields}
                <Button icon="add" type="submit" loading={form.processing}>{t('Add unit')}</Button>
            </form>
        );
    }

    return (
        <>
            <div className="mt-2"><Button type="button" tone="ghost" onClick={() => { form.clearErrors(); setOpen(true); }}>{t('Add unit')}</Button></div>
            <Dialog open={open} onClose={close} fullWidth maxWidth="sm">
                <form onSubmit={submit}>
                    <DialogTitle>{t('Add unit')}</DialogTitle>
                    <DialogContent><div className="pt-2">{fields}</div></DialogContent>
                    <DialogActions>
                        <Button type="button" tone="ghost" disabled={form.processing} onClick={close}>{t('Cancel')}</Button>
                        <Button icon="add" type="submit" loading={form.processing}>{t('Add unit')}</Button>
                    </DialogActions>
                </form>
            </Dialog>
        </>
    );
}
