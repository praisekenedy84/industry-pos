import { money, quantity, useT } from '@/lib/ui';

export default function ReceiptPaper({ sale, items, tenant, footer, children }) {
    const t = useT();

    return (
        <div className="receipt mx-auto w-[80mm] max-w-full p-4 text-sm">
            <h1 className="text-center text-lg font-semibold">{tenant?.name}</h1>
            <p className="text-center">{sale.receipt?.number}</p>
            <p className="text-center">{sale.number}</p>
            <p className="text-center">{t('Seller:')} {sale.seller?.name || t('Unavailable')}</p>
            <ul className="my-3 space-y-1">
                {sale.lines.map((line) => (
                    <li key={line.id} className="flex justify-between gap-3">
                        <span>{items[line.item_id]?.name} × {quantity(line.qty)}</span>
                        <span>{money(line.gross)}</span>
                    </li>
                ))}
            </ul>
            <p className="flex justify-between font-semibold"><span>{t('Total')}</span><span>{money(sale.total)}</span></p>
            {sale.payments.map((payment) => (
                <p key={payment.id} className="flex justify-between">
                    <span>{t(`payment.${payment.method}`, payment.method)}</span>
                    <span>{money(payment.amount)}</span>
                </p>
            ))}
            {footer ? <p className="mt-4 text-center">{footer}</p> : null}
            {children}
        </div>
    );
}
