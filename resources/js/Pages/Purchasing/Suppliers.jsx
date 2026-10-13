import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { Button, Card, Field, inputClass, money, Shell } from '@/lib/ui';
import RecordActions from '@/lib/RecordActions';

export default function Suppliers({ suppliers, items, canManage }) {
    const t = useT();
    const form = useForm({ name: '', phone: '', email: '', payment_terms: t("30 days"), item_id: '', price: '' });
    return (
        <Shell title={t("Suppliers")}>
            {canManage && <form className="mb-4 grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.post('/purchasing/suppliers'); }}>
                <Field error={form.errors.name} label={t("Name")}><Input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <Field error={form.errors.phone} label={t("Phone")}><Input className={inputClass} value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} /></Field>
                <Field error={form.errors.email} label={t("Email")}><Input className={inputClass} value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></Field>
                <Field error={form.errors.payment_terms} label={t("Terms")}><Input className={inputClass} value={form.data.payment_terms} onChange={(event) => form.setData('payment_terms', event.target.value)} /></Field>
                <Field error={form.errors.item_id} label={t("Price for item")}><Input select className={inputClass} value={form.data.item_id} onChange={(event) => form.setData('item_id', event.target.value)}><option value="">{t("Optional")}</option>{items.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Input></Field>
                <Field error={form.errors.price} label={t("Price")}><Input type="number" min="0" step="any" className={inputClass} value={form.data.price} onChange={(event) => form.setData('price', event.target.value)} /></Field>
                <Button icon="add" loading={form.processing} type="submit">{t("Add supplier")}</Button>
            </form>}
            <Card className="mb-4 divide-y divide-hairline text-sm">
                {suppliers.map((supplier) => <div key={supplier.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"><span>{supplier.name} · {money(supplier.balance)}</span><RecordActions record={supplier} path={`/purchasing/suppliers/${supplier.id}`} canEdit={canManage} canDelete={canManage} fields={[{ name: 'name', label: 'Name', required: true }, { name: 'phone', label: 'Phone' }, { name: 'email', label: 'Email', type: 'email' }, { name: 'payment_terms', label: 'Terms' }, { name: 'notes', label: 'Notes' }]} /></div>)}
            </Card>
        </Shell>
    );
}
