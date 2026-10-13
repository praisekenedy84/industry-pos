import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { amount, Button, Card, Field, inputClass, Shell } from '@/lib/ui';
import RecordActions from '@/lib/RecordActions';

export default function PriceLists({ lists, prices, items, canManage }) {
    const t = useT();
    const form = useForm({ name: '', channel: 'retail', item_id: '', price: '', price_list_id: '' });
    return (
        <Shell title={t("Price lists")}>
            {canManage && <form className="mb-4 grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.post('/catalog/price-lists'); }}>
                <Field error={form.errors.price_list_id} label={t("Existing list")}><Input select className={inputClass} value={form.data.price_list_id} onChange={(event) => form.setData('price_list_id', event.target.value)}><option value="">{t("New list")}</option>{lists.map((list) => <option key={list.id} value={list.id}>{list.name}</option>)}</Input></Field>
                <Field error={form.errors.name} label={t("New list name")}><Input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <Field error={form.errors.item_id} label={t("Item")}><Input select className={inputClass} value={form.data.item_id} onChange={(event) => form.setData('item_id', event.target.value)}><option value="">{t("Select")}</option>{items.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Input></Field>
                <Field error={form.errors.price} label={t("Price")}><Input type="number" min="0" step="0.01" className={inputClass} value={form.data.price} onChange={(event) => form.setData('price', event.target.value)} /></Field>
                <Button icon="save" loading={form.processing} type="submit">{t("Save price")}</Button>
            </form>}
            <Card className="mb-4 divide-y divide-hairline text-sm">
                {lists.map((list) => <div key={list.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"><span>{list.name} · {list.channel}</span><RecordActions record={list} path={`/catalog/price-lists/${list.id}`} canEdit={canManage} canDelete={canManage && !list.is_default} permanent fields={[{ name: 'name', label: 'Name', required: true }]} /></div>)}
            </Card>
            <p className="mb-3 text-sm text-muted">{prices.length} {t("prices stored")}</p>
            <Card className="divide-y divide-hairline">{prices.map((price) => <div key={price.id} className="flex flex-wrap items-center justify-between gap-3 p-4"><span>{items.find((item) => item.id === price.item_id)?.name || t('Archived product')} · {lists.find((list) => list.id === price.price_list_id)?.name} · {amount(price.price)}</span><RecordActions record={price} path={`/catalog/prices/${price.id}`} canEdit={canManage} canDelete={canManage} permanent removalDescription="Remove this price override for future sales. Completed sales keep their recorded prices." fields={[{ name: 'price', label: 'Price', type: 'number', min: 0, step: '0.01', required: true }]} /></div>)}</Card>
        </Shell>
    );
}
