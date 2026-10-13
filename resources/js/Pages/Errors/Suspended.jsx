import { useT } from '@/lib/ui';
export default function Suspended({ tenant, reason }) {
    const t = useT();
    return (
        <div className="flex min-h-screen flex-col items-center justify-center px-6 text-center">
            <h1 className="text-2xl font-semibold">{tenant} {t("is paused")}</h1>
            <p className="mt-3 max-w-md text-muted">{reason || 'This workspace is suspended. Data is retained. Contact the workspace owner or Makerly support.'}</p>
        </div>
    );
}
