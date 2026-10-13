import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { Button, Card, Field, inputClass, Shell } from '@/lib/ui';
import RecordActions from '@/lib/RecordActions';

export default function Categories({ categories, canCreate, canEdit, canDelete }) {
    const t = useT();
    const form = useForm({ name: '', name_alt: '', color: '#d3e5ef' });
    return (
        <Shell title={t("Categories")}>
            {canCreate && <form className="mb-4 grid gap-3 md:grid-cols-3" onSubmit={(event) => { event.preventDefault(); form.post('/catalog/categories'); }}>
                <Field error={form.errors.name} label={t("Name")}><Input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <Field error={form.errors.name_alt} label={t("Kiswahili")}><Input className={inputClass} value={form.data.name_alt} onChange={(event) => form.setData('name_alt', event.target.value)} /></Field>
                <Field error={form.errors.color} label={t("Color")}><Input className={inputClass} value={form.data.color} onChange={(event) => form.setData('color', event.target.value)} /></Field>
                <Button icon="add" loading={form.processing} type="submit">{t("Add category")}</Button>
            </form>}
            <Card className="mb-4 divide-y divide-hairline text-sm">
                {categories.map((row) => <div key={row.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"><span>{row.name}</span><RecordActions record={row} path={`/catalog/categories/${row.id}`} canEdit={canEdit} canDelete={canDelete} fields={[{ name: 'name', label: 'Name', required: true }, { name: 'name_alt', label: 'Kiswahili' }, { name: 'color', label: 'Color' }]} /></div>)}
            </Card>
        </Shell>
    );
}
