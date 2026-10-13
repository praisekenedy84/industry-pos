import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { Button, Card, Field, inputClass, money, Shell } from '@/lib/ui';

export default function Show({ customer, payments, sales, lists, credit, canManage }) {
    const t = useT();
    const form = useForm({ name: customer.name, phone: customer.phone || '', email: customer.email || '', credit_limit: customer.credit_limit || '', notes: customer.notes || '', price_list_id: customer.price_list_id || '' });
    const pay = useForm({ amount: '', method: 'cash', reference: '' });
    return (
        <Shell title={customer.name}>
            <p className="mb-3 text-sm">{t("Balance")} {money(customer.balance)}{credit ? ` · limit ${money(customer.credit_limit)}` : ''}</p>
            <form className="mb-4 grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.put(`/customers/${customer.id}`); }}>
                <Field error={form.errors.name} label={t("Name")}><Input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <Field error={form.errors.phone} label={t("Phone")}><Input className={inputClass} value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} /></Field>
                <Field error={form.errors.email} label={t('Email')}><Input type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></Field>
                <Field error={form.errors.price_list_id} label={t("Price list")}><Input select className={inputClass} value={form.data.price_list_id} onChange={(event) => form.setData('price_list_id', event.target.value)}><option value="">{t("Default")}</option>{lists.map((list) => <option key={list.id} value={list.id}>{list.name}</option>)}</Input></Field>
                <Field error={form.errors.notes} label={t("Notes")}><Input className={inputClass} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} /></Field>
                {canManage && <Button icon="save" loading={form.processing} type="submit">{t("Save")}</Button>}
            </form>
            {credit && canManage && (
                <form className="mb-4 flex gap-2" onSubmit={(event) => { event.preventDefault(); pay.post(`/customers/${customer.id}/payments`); }}>
                    <Input className={inputClass} placeholder={t("Payment amount")} value={pay.data.amount} onChange={(event) => pay.setData('amount', event.target.value)} />
                    <Button icon="payment" loading={pay.processing} type="submit" tone="soft">{t("Record payment")}</Button>
                </form>
            )}
            <div className="grid gap-4 md:grid-cols-2">
                <Card className="p-4 text-sm"><h2 className="mb-2 font-medium">{t("Sales")}</h2>{sales.map((sale) => <p key={sale.id}>{sale.number} · {money(sale.total)}</p>)}</Card>
                <Card className="p-4 text-sm"><h2 className="mb-2 font-medium">{t("Payments")}</h2>{payments.map((row) => <p key={row.id}>{money(row.amount)} · {row.method}</p>)}</Card>
            </div>
        </Shell>
    );
}
