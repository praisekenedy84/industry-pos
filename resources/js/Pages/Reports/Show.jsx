import { useT } from '@/lib/ui';
import { TableContainer, Table as MuiTable, TableHead, TableRow, TableCell, TableBody } from '@mui/material';
import { Input } from '@/lib/ui';
import { router, useForm } from '@inertiajs/react';
import { Button, Card, inputClass, Shell } from '@/lib/ui';
import { RankingChart } from '@/lib/charts';
import { money } from '@/lib/ui';
import { Alert } from '@mui/material';

export default function Show({ report, result, filters }) {
    const t = useT();
    const form = useForm({ frequency: 'weekly', name: '' });
    const rows = result.rows || [];
    return (
        <Shell title={t(report.name)} actions={<Button icon="export" component="a" tone="soft" href={`/reports/${report.key}/export?from=${filters.from}&to=${filters.to}&branch_id=${filters.branch_id || ''}`}>{t("Export CSV")}</Button>}>
            <form className="mb-4 flex flex-wrap items-start gap-3" onSubmit={(event) => { event.preventDefault(); const data = new FormData(event.target); router.get(`/reports/${report.key}`, Object.fromEntries(data), { preserveState: true }); }}>
                <Input label={t("From date")} type="date" name="from" defaultValue={filters.from?.slice(0, 10)} />
                <Input label={t("To date")} type="date" name="to" defaultValue={filters.to?.slice(0, 10)} />
                <Button icon="filter" type="submit" tone="soft">{t("Apply filters")}</Button>
            </form>
            <form className="mb-4 flex flex-wrap items-center gap-2" onSubmit={(event) => { event.preventDefault(); form.post(`/reports/${report.key}/subscribe`); }}>
                <Input select label={t("Frequency")} fieldWidth="short" value={form.data.frequency} onChange={(event) => form.setData('frequency', event.target.value)}><option value="daily">{t("Daily")}</option><option value="weekly">{t("Weekly")}</option><option value="monthly">{t("Monthly")}</option></Input>
                <Input fieldWidth="medium" placeholder={t("Save view name")} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                <Button icon="mail" loading={form.processing} type="submit">{t("Subscribe")}</Button>
            </form>
            {result.note && <Alert severity="info" sx={{ mb: 3 }}>{t(result.note)}</Alert>}
            {report.key === 'expenses.categories' && rows.length > 0 && <Card sx={{ mb: 3 }}><RankingChart rows={rows.map((row) => ({ name: t(row.category), gross: row.amount }))} formatValue={money} title={t('Expenses by category')} caption={t('Paid operating expenses · TZS')} /></Card>}
            <Card>
                <div className="overflow-x-auto">
                    <TableContainer tabIndex={0} role="region" aria-label={t("Scrollable records")}><MuiTable className="w-full text-left text-sm">
                        <TableHead className="text-muted"><TableRow>{result.columns.map((column) => <TableCell scope="col" key={column} className="px-3 py-2">{t(`column.${column}`, column.replaceAll('_', ' '))}</TableCell>)}</TableRow></TableHead>
                        <TableBody>
                            {rows.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={result.columns.length} align="center" sx={{ py: 6 }}>
                                        <p role="status" className="font-medium">{t("No data to show")}</p>
                                        <p className="mt-1 text-sm text-muted">{t("No records match the selected filters. Try a different date range.")}</p>
                                    </TableCell>
                                </TableRow>
                            )}
                            {rows.map((row, index) => (
                                <TableRow key={index} className="border-t border-hairline">
                                    {result.columns.map((column) => <TableCell key={column} className="px-3 py-2">{report.key === 'sales.origin' && column === 'label' ? t(row[column]) : String(row[column] ?? '')}</TableCell>)}
                                </TableRow>
                            ))}
                        </TableBody>
                    </MuiTable></TableContainer>
                </div>
            </Card>
        </Shell>
    );
}
