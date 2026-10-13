import { useT } from '@/lib/ui';

export default function ApprovalRequired({ tenant, reason, phones = [], email }) {
    const t = useT();

    return (
        <div className="flex min-h-screen flex-col items-center justify-center px-6 text-center">
            <h1 className="text-2xl font-semibold">{tenant}</h1>
            <p className="mt-3 max-w-lg whitespace-pre-line text-muted">{reason}</p>
            <p className="mt-4 text-sm">
                {t("Call")}{' '}
                {phones.map((phone, index) => (
                    <span key={phone}>
                        {index > 0 ? ` ${t("or")} ` : ''}
                        <a className="text-link" href={`tel:${phone}`}>{phone}</a>
                    </span>
                ))}
                {email ? <> {t("or email")} <a className="text-link" href={`mailto:${email}`}>{email}</a></> : null}
            </p>
        </div>
    );
}
