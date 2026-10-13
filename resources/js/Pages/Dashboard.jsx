import { useT } from '@/lib/ui';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Avatar, Box, Chip, LinearProgress, List, ListItem, ListItemButton, ListItemIcon, ListItemText, Stack, ToggleButton, ToggleButtonGroup, Typography } from '@mui/material';
import { Button, Card, money, quantity, Shell } from '@/lib/ui';
import { ChartData, RankingChart, TrendChart } from '@/lib/charts';
import { Banknote, ShoppingBag, Factory, UsersRound, CreditCard, ChartNoAxesCombined, CircleCheck, Circle, TriangleAlert, TrendingUp } from 'lucide-react';

export default function Dashboard({ metrics, checklist, top, trend = null }) {
    const t = useT();
    const [period, setPeriod] = useState(7);
    const chartRows = trend?.slice(-period) || [];
    const cards = [
        [t("Production"), metrics.production ?? '—', Factory],
        [t("Sales today"), metrics.sales == null ? '—' : money(metrics.sales), Banknote],
        [t("Orders"), metrics.orders ?? '—', ShoppingBag],
        [t("Sellers today"), metrics.sellers ?? '—', UsersRound],
        [t("Receivables"), metrics.receivables == null ? '—' : money(metrics.receivables), CreditCard],
        [t("Margin"), metrics.margin == null ? '—' : money(metrics.margin), ChartNoAxesCombined],
    ];
    const completed = checklist.steps.filter((step) => step.done).length;
    const lowStock = metrics.low_stock || [];
    return (
        <Shell title={t("Today at a glance")}>
            <Typography variant="body2" color="text.secondary" sx={{ mt: -1, mb: 3 }}>{t("A clear view of your sales, production and stock.")}</Typography>
            {!checklist.dismissed && <Card sx={{ mb: 3, p: { xs: 2.5, sm: 3 }, borderColor: 'primary.main' }}>
                <Stack direction="row" sx={{ alignItems: 'center', justifyContent: 'space-between', gap: 2 }}><Box><Typography variant="h6">{t("Get your workspace ready")}</Typography><Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>{completed} {t("of")} {checklist.steps.length} {t("steps complete")}</Typography></Box><Button icon="cancel" tone="ghost" onClick={() => router.post('/onboarding')}>{t("Dismiss")}</Button></Stack>
                <LinearProgress variant="determinate" value={completed / (checklist.steps.length || 1) * 100} aria-label={t("Workspace setup progress")} sx={{ my: 2, height: 6, borderRadius: 3 }} />
                <List sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', lg: '1fr 1fr 1fr' }, gap: 0.5, p: 0 }}>{checklist.steps.map((step) => <ListItemButton key={step.key} component={Link} href={step.href} sx={{ borderRadius: 2, py: 1 }}><ListItemIcon sx={{ minWidth: 30, color: step.done ? 'success.main' : 'text.secondary' }}>{step.done ? <CircleCheck size={18} /> : <Circle size={18} />}</ListItemIcon><ListItemText primary={t(step.label, step.label.replace('checklist.', '').replaceAll('_', ' '))} slotProps={{ primary: { fontSize: 13, textTransform: 'capitalize', color: step.done ? 'text.secondary' : 'text.primary' } }} /></ListItemButton>)}</List>
            </Card>}
            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', lg: 'repeat(3, 1fr)' } }}>{cards.map(([label, value, Icon], index) => <Card key={label} sx={{ p: 3, ...(index === 0 ? { borderColor: 'primary.main' } : {}) }}><Stack direction="row" sx={{ alignItems: 'center', justifyContent: 'space-between' }}><Typography variant="body2" color="text.secondary">{label}</Typography><Avatar variant="rounded" sx={{ bgcolor: index === 0 ? 'primary.main' : 'action.hover', color: index === 0 ? 'primary.contrastText' : 'text.secondary', width: 38, height: 38 }}><Icon size={20} strokeWidth={1.7} /></Avatar></Stack><Typography variant="h4" component="p" sx={{ mt: 2.5, fontVariantNumeric: 'tabular-nums' }}>{value}</Typography></Card>)}</Box>
            {trend !== null && <Card sx={{ mt: 3, p: { xs: 2.5, sm: 3 } }}>
                <Stack direction={{ xs: 'column', sm: 'row' }} sx={{ justifyContent: 'space-between', alignItems: { xs: 'flex-start', sm: 'center' }, gap: 2, mb: 3 }}>
                    <Box><Typography component="h2" variant="h6">{t("Sales performance")}</Typography><Typography variant="body2" color="text.secondary">{t("Completed sales · Selected branch · East Africa Time · Today is in progress")}</Typography></Box>
                    <ToggleButtonGroup value={period} exclusive onChange={(_, value) => { if (value !== null) setPeriod(value); }} aria-label={t("Chart period")} size="small" sx={{ flexShrink: 0 }}><ToggleButton value={7} sx={{ minHeight: 44 }}>{t("7 days")}</ToggleButton><ToggleButton value={30} sx={{ minHeight: 44 }}>{t("30 days")}</ToggleButton></ToggleButtonGroup>
                </Stack>
                <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', lg: '1fr 1fr' }, gap: 3 }}>
                    <TrendChart title={t("Daily sales · TZS")} rows={chartRows} valueKey="sales" formatValue={money} />
                    <TrendChart title={t("Daily orders")} rows={chartRows} valueKey="orders" formatValue={(value) => `${value} orders`} color="secondary" />
                </Box>
                <ChartData rows={chartRows} />
            </Card>}
            <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', lg: '1fr 1fr' }, mt: 3 }}>
                <Card sx={{ overflow: 'hidden' }}>
                    <Stack direction="row" sx={{ alignItems: 'center', gap: 1, p: 2.5, borderBottom: 1, borderColor: 'divider' }}><TriangleAlert size={19} /><Typography variant="h6">{t("Stock needs attention")}</Typography><Chip label={lowStock.length} color={lowStock.length ? 'warning' : 'default'} size="small" sx={{ ml: 'auto' }} /></Stack>
                    {lowStock.length ? <List disablePadding>{lowStock.map((row) => <ListItem key={row.id} divider><ListItemText primary={row.name} secondary={`Reorder level: ${quantity(row.reorder_level)}`} slotProps={{ primary: { fontSize: 14 }, secondary: { fontSize: 12 } }} /><Chip label={`${quantity(row.qty)} left`} size="small" color="warning" variant="outlined" /></ListItem>)}</List> : <Stack spacing={1.5} sx={{ alignItems: 'center', textAlign: 'center', py: 5, px: 3 }}><Avatar sx={{ bgcolor: 'action.hover', color: 'success.main' }}><CircleCheck size={23} /></Avatar><Typography variant="body2" color="text.secondary">{t("No items below reorder level.")}</Typography></Stack>}
                </Card>
                <Card sx={{ overflow: 'hidden' }}>
                    <Stack direction="row" sx={{ alignItems: 'center', gap: 1, p: 2.5, borderBottom: 1, borderColor: 'divider' }}><TrendingUp size={19} /><Typography variant="h6">{t("Top sellers")}</Typography><Typography variant="caption" color="text.secondary" sx={{ ml: 'auto' }}>{t("Today")}</Typography></Stack>
                    {top?.length ? <RankingChart rows={top} formatValue={money} /> : <Stack spacing={1.5} sx={{ alignItems: 'center', textAlign: 'center', py: 5, px: 3 }}><Avatar sx={{ bgcolor: 'action.hover', color: 'text.secondary' }}><ShoppingBag size={23} /></Avatar><Typography variant="body2" color="text.secondary">{t("Completed sales will appear here.")}</Typography></Stack>}
                </Card>
            </Box>
        </Shell>
    );
}
