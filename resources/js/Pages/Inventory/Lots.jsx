import { useT } from '@/lib/ui';
import { Card, quantity, Shell } from '@/lib/ui';

export default function Lots({ lots, items }) {
    const t = useT();
    return (
        <Shell title={t("Lots and expiry")}>
            <Card className="divide-y divide-hairline text-sm">
                {lots.map((lot) => (
                    <div key={lot.id} className="flex justify-between px-4 py-3">
                        <span>{items[lot.item_id]?.name || lot.item_id} · {lot.lot_code}</span>
                        <span>{quantity(lot.qty)} · {lot.expiry_date || 'No expiry'}</span>
                    </div>
                ))}
                {lots.length === 0 && <p className="px-4 py-6 text-muted">{t("No lots recorded.")}</p>}
            </Card>
        </Shell>
    );
}
