import { useT } from '@/lib/ui';
import { Link } from '@inertiajs/react';
import { Chip, Typography } from '@mui/material';
import { Building2 } from 'lucide-react';
import { Card, Shell, Table, useConsoleBase, words } from '@/lib/ui';

const statusColor = {
    active: 'success',
    suspended: 'warning',
    pending_deletion: 'error',
};

export default function Tenants({ tenants }) {
    const t = useT();
    const base = useConsoleBase();

    return (
        <Shell title={t("Workspaces")}>
            <Typography variant="body2" color="text.secondary" sx={{ mt: -1, mb: 3 }}>{t("Open a workspace to manage its profile, features, and limits.")}</Typography>
            <Card>
                <Table
                    rows={tenants}
                    empty={t("No workspaces yet. A new signup will appear here.")}
                    columns={[
                        { key: 'name', label: t("Workspace"), render: (tenant) => <Link href={`${base}/tenants/${tenant.id}`} className="flex items-center gap-3 font-medium hover:text-link"><span className="rounded-lg bg-canvas p-2 text-muted"><Building2 size={17} /></span><span>{tenant.name}<Typography component="span" variant="caption" color="text.secondary" sx={{ display: 'block' }}>{tenant.slug}</Typography></span></Link> },
                        { key: 'domain', label: 'Address', render: (tenant) => <Typography variant="body2" color="text.secondary">{tenant.domain}</Typography> },
                        { key: 'industry_key', label: t("Industry"), render: (tenant) => <Typography variant="body2">{tenant.industry_name || words(tenant.industry_key) || '—'}</Typography> },
                        { key: 'branches', label: t("Branches"), render: (tenant) => <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums' }}>{tenant.branches}{tenant.branch_limit ? ` / ${tenant.branch_limit}` : ''}</Typography> },
                        { key: 'status', label: t("Status"), render: (tenant) => <Chip label={words(tenant.status)} size="small" color={statusColor[tenant.status] || 'default'} variant="outlined" sx={{ textTransform: 'capitalize' }} /> },
                    ]}
                />
            </Card>
        </Shell>
    );
}
