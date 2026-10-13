import { Button, useT } from '@/lib/ui';
import ReceiptPaper from '@/Pages/Pos/ReceiptPaper';

export default function Receipt({ sale, items, tenant, footer }) {
    const t = useT();

    return (
        <div className="my-6">
            <ReceiptPaper sale={sale} items={items} tenant={tenant} footer={footer}>
                <Button icon="print" tone="soft" className="no-print mt-4 w-full" onClick={() => window.print()}>{t('Print receipt')}</Button>
            </ReceiptPaper>
        </div>
    );
}
