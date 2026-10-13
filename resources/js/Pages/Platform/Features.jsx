import { useT } from '@/lib/ui';
import { Chip, TableBody, TableCell, TableContainer, TableHead, TableRow, Table as MuiTable, Typography } from '@mui/material';
import { Card, Shell, words } from '@/lib/ui';

export default function Features({ features, tenants, overrides }) {
    const t = useT();
    return (
        <Shell title={t("Feature matrix")}>
            <Typography variant="body2" color="text.secondary" sx={{ mt: -1, mb: 3 }}>{t("Compare each workspace with the default for that feature. A filled chip is an override.")}</Typography>
            <Card>
                <TableContainer tabIndex={0} role="region" aria-label={t("Feature matrix. Scroll horizontally to see more workspaces.")}>
                    <MuiTable>
                        <TableHead>
                            <TableRow>
                                <TableCell scope="col">{t("Feature")}</TableCell>
                                {tenants.map((tenant) => <TableCell scope="col" key={tenant.id} sx={{ whiteSpace: 'nowrap' }}>{tenant.name}</TableCell>)}
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {features.map((feature) => (
                                <TableRow key={feature.key} hover>
                                    <TableCell>
                                        <Typography variant="body2" sx={{ fontWeight: 600, textTransform: 'capitalize' }}>{words(feature.key)}</Typography>
                                        {feature.description && <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{feature.description}</Typography>}
                                    </TableCell>
                                    {tenants.map((tenant) => {
                                        const override = overrides.find((row) => row.tenant_id === tenant.id && row.feature_key === feature.key);
                                        return <TableCell key={tenant.id}><FeatureFlag value={override ? override.value : feature.default_value} overridden={Boolean(override)} /></TableCell>;
                                    })}
                                </TableRow>
                            ))}
                            {!features.length && (
                                <TableRow>
                                    <TableCell colSpan={tenants.length + 1}>
                                        <Typography variant="body2" color="text.secondary" sx={{ py: 3, textAlign: 'center' }}>{t("No features are defined yet.")}</Typography>
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </MuiTable>
                </TableContainer>
            </Card>
        </Shell>
    );
}

function FeatureFlag({ value, overridden }) {
    const enabled = flag(value);
    if (enabled === null) {
        return <Typography variant="body2">{typeof value === 'object' ? JSON.stringify(value) : String(value ?? '—')}</Typography>;
    }

    return <Chip label={enabled ? 'On' : 'Off'} size="small" color={enabled ? 'success' : 'default'} variant={overridden ? 'filled' : 'outlined'} />;
}

function flag(value) {
    if (value === true || value === 1 || value === '1' || value === 'true') {
        return true;
    }
    if (value === false || value === 0 || value === '0' || value === 'false' || value == null) {
        return false;
    }
    if (value && typeof value === 'object' && 'enabled' in value) {
        return Boolean(value.enabled);
    }

    return null;
}
