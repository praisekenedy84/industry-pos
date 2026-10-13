import { useT } from '@/lib/ui';
import { Link } from '@inertiajs/react';
import { Alert, Chip, Typography } from '@mui/material';
import { Building2 } from 'lucide-react';
import { Card, Shell, Table, useConsoleBase, words } from '@/lib/ui';

const statusColor = {
    active: 'success',
    trial: 'info',
    suspended: 'warning',
    pending_deletion: 'error',
};

export default function Tenants({ tenants }) {
    const t = useT();
    const base = useConsoleBase();

    return (
        <Shell title={t("Workspaces")}>
            <Typography variant="body2" color="text.secondary" sx={{ mt: -1, mb: 3 }}>{t("Open a workspace to manage its profile, features, and limits. New signups appear here for approval.")}</Typography>
            {tenants.some((tenant) => tenant.status === 'trial') && <Alert severity="info" sx={{ mb: 3 }}>{t("New signups can use the system for 3 days. Approve them in the workspace page. After the trial they are asked to call +255743463710 or +255715148503, or email info@mernettechnologies.com.")}</Alert>}
            <Card>
                <Table
                    rows={tenants}
                    empty={t("No workspaces yet. A new signup will appear here.")}
                    columns={[
                        { key: 'name', label: t("Workspace"), render: (tenant) => <Link href={`${base}/tenants/${tenant.id}`} className="flex items-center gap-3 font-medium hover:text-link"><span className="rounded-lg bg-canvas p-2 text-muted"><Building2 size={17} /></span><span>{tenant.name}<Typography component="span" variant="caption" color="text.secondary" sx={{ display: 'block' }}>{tenant.slug}</Typography></span></Link> },
                        { key: 'domain', label: 'Address', render: (tenant) => <Typography variant="body2" color="text.secondary">{tenant.domain}</Typography> },
                        { key: 'industry_key', label: t("Industry"), render: (tenant) => <Typography variant="body2">{tenant.industry_name || words(tenant.industry_key) || '—'}</Typography> },
                        { key: 'branches', label: t("Branches"), render: (tenant) => <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums' }}>{tenant.branches === null ? '—' : tenant.branches}{tenant.branch_limit ? ` / ${tenant.branch_limit}` : ''}</Typography> },
                        { key: 'status', label: t("Status"), render: (tenant) => <span><Chip label={tenant.awaiting_approval ? t("Needs approval") : words(tenant.status)} size="small" color={tenant.awaiting_approval ? 'warning' : (statusColor[tenant.status] || 'default')} variant="outlined" sx={{ textTransform: 'capitalize' }} />{tenant.trial_ends_at && <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>{t("Trial ends")} {when(tenant.trial_ends_at)}</Typography>}</span> },
                    ]}
                />
            </Card>
        </Shell>
    );
}

function when(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
}
