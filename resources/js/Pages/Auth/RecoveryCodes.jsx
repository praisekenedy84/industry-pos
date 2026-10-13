import { useT } from '@/lib/ui';
import { router } from '@inertiajs/react';
import { AuthCard, Button } from '@/lib/ui';

export default function RecoveryCodes({ codes, action = '/two-factor/continue' }) {
    const t = useT();
    return (
        <AuthCard title={t("Save your recovery codes")}>
            <p className="text-sm text-stone-600">{t("Each code works once if you lose your authenticator. This is the only time they are shown.")}</p>
            <ul className="my-3 space-y-1 font-mono text-sm">
                {codes.map((code) => <li key={code}>{code}</li>)}
            </ul>
            <Button icon="next" type="button" onClick={() => router.post(action)}>{t("Continue")}</Button>
        </AuthCard>
    );
}
