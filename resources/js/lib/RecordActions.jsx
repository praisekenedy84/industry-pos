import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Dialog, DialogActions, DialogContent, DialogTitle } from '@mui/material';
import { Button, ConfirmButton, Errors, Field, Input, useT } from './ui';

export default function RecordActions({ record, path, fields = [], canEdit, canDelete, editHref, permanent = false, removalDescription }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({});
    const action = permanent ? 'Delete' : 'Archive';
    return <div className="space-y-2">
        <div className="flex flex-wrap gap-2">
            {record.deleted_at ? <><span className="text-sm text-muted">{t('Archived')}</span>{canDelete && <Button loading={form.processing} tone="soft" onClick={() => form.post(`${path}/restore`, { preserveScroll: true })}>{t('Restore')}</Button>}</> : <>
                {canEdit && (editHref ? <Button component={Link} href={editHref} tone="ghost">{t('Edit')}</Button> : <Button tone="ghost" onClick={() => { form.clearErrors(); form.setData(Object.fromEntries(fields.map((field) => [field.name, record[field.name] ?? '']))); setOpen(true); }}>{t('Edit')}</Button>)}
                {canDelete && <ConfirmButton tone="danger" disabled={form.processing} title={`${t(action)} ${record.name || t('record')}?`} description={t(removalDescription || (permanent ? 'Only unused records can be deleted. Records needed by existing products or transactions must be kept.' : 'Remove this record from active selections. Existing history remains saved, and you can restore it here.'))} onConfirm={() => form.delete(path, { preserveScroll: true })}>{t(action)}</ConfirmButton>}
            </>}
        </div>
        {!open && <Errors errors={form.errors} />}
        <Dialog open={open} onClose={() => { if (!form.processing) setOpen(false); }} fullWidth maxWidth="sm">
            <form onSubmit={(event) => { event.preventDefault(); form.put(path, { preserveScroll: true, onSuccess: () => setOpen(false) }); }}>
                <DialogTitle>{t('Edit')} {record.name}</DialogTitle>
                <DialogContent><div className="space-y-4 pt-2"><Errors errors={form.errors} />{fields.map((field) => <Field key={field.name} label={t(field.label)}><Input required={field.required} type={field.type || 'text'} min={field.min} step={field.step} value={form.data[field.name] ?? ''} onChange={(event) => form.setData(field.name, event.target.value)} /></Field>)}</div></DialogContent>
                <DialogActions><Button type="button" tone="ghost" disabled={form.processing} onClick={() => setOpen(false)}>{t('Cancel')}</Button><Button type="submit" loading={form.processing}>{t('Save changes')}</Button></DialogActions>
            </form>
        </Dialog>
    </div>;
}
