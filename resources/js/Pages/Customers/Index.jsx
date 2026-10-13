import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { Link, useForm } from '@inertiajs/react';
import { Button, Card, Field, inputClass, money, Pager, rowsOf, Shell } from '@/lib/ui';
import RecordActions from '@/lib/RecordActions';

export default function Index({ customers, lists, credit, canManage }) {
    const t = useT();
    const form = useForm({ name: '', phone: '', email: '', credit_limit: '', price_list_id: '' });
    return (
        <Shell title={t("Customers")}>
            {canManage && <form className="mb-4 grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.post('/customers'); }}>
                <Field error={form.errors.name} label={t("Name")}><Input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <Field error={form.errors.phone} label={t("Phone")}><Input className={inputClass} value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} /></Field>
                <Field error={form.errors.email} label={t("Email")}><Input className={inputClass} value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></Field>
                <Field error={form.errors.price_list_id} label={t("Price list")}><Input select className={inputClass} value={form.data.price_list_id} onChange={(event) => form.setData('price_list_id', event.target.value)}><option value="">{t("Default")}</option>{lists.map((list) => <option key={list.id} value={list.id}>{list.name}</option>)}</Input></Field>
                {credit && <Field error={form.errors.credit_limit} label={t("Credit limit")}><Input className={inputClass} value={form.data.credit_limit} onChange={(event) => form.setData('credit_limit', event.target.value)} /></Field>}
                <Button icon="add" loading={form.processing} type="submit">{t("Add customer")}</Button>
            </form>}
            <Card className="mb-4 divide-y divide-hairline text-sm">
                {rowsOf(customers).map((customer) => (
                    <div key={customer.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        {customer.deleted_at ? <span>{customer.name}</span> : <Link href={`/customers/${customer.id}`}>{customer.name}<span className="block text-muted">{customer.phone}</span></Link>}
                        <span>{money(customer.balance)}</span><RecordActions record={customer} path={`/customers/${customer.id}`} canEdit={canManage} canDelete={canManage} editHref={`/customers/${customer.id}`} />
                    </div>
                ))}
                <Pager paginator={customers} />
            </Card>
        </Shell>
    );
}
