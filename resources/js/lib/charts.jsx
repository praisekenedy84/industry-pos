import { useT } from '@/lib/ui';
import { useEffect, useId, useRef, useState } from 'react';
import { Box, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Tooltip, Typography } from '@mui/material';
import { useTheme } from '@mui/material/styles';

const compact = (value) => new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value);

export function TrendChart({ title, rows, valueKey, formatValue, color = 'primary' }) {
    const t = useT();
    const theme = useTheme();
    const titleId = useId();
    const [selected, setSelected] = useState(null);
    const containerRef = useRef(null);
    const [width, setWidth] = useState(720);
    useEffect(() => {
        const observer = new ResizeObserver(([entry]) => setWidth(Math.max(280, entry.contentRect.width)));
        observer.observe(containerRef.current);
        return () => observer.disconnect();
    }, []);
    const values = rows.map((row) => Number(row[valueKey]) || 0);
    const maximum = Math.max(1, ...values);
    const minimum = Math.min(0, ...values);
    const x = (index) => 66 + index * (width - 100) / Math.max(1, rows.length - 1);
    const y = (value) => 208 - (value - minimum) / (maximum - minimum) * 176;
    const stroke = theme.palette[color].main;
    const dateLabel = (date) => new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
    const selectedRow = selected == null ? null : rows[selected];
    const hasActivity = values.some((value) => value !== 0);
    const ticks = [...new Set([minimum, valueKey === 'orders' ? Math.floor((minimum + maximum) / 2) : (minimum + maximum) / 2, maximum])];
    return <Box ref={containerRef} sx={{ minWidth: 0 }}>
        <Typography id={titleId} component="h3" variant="subtitle2" sx={{ mb: 1 }}>{title}</Typography>
        {!hasActivity && <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>{t("No completed sales in this period.")}</Typography>}
        <Box component="svg" viewBox={`0 0 ${width} 250`} role="group" aria-labelledby={titleId} sx={{ display: 'block', width: '100%', overflow: 'visible', '& circle:focus-visible': { outline: 'none', stroke: theme.palette.text.primary, strokeWidth: 3 } }}>
            {ticks.map((value) => {
                return <g key={value}><line x1="66" x2={width - 34} y1={y(value)} y2={y(value)} stroke={theme.palette.divider} strokeDasharray="4 4" /><text x="56" y={y(value) + 4} textAnchor="end" fill={theme.palette.text.secondary} fontSize="12">{compact(value)}</text></g>;
            })}
            <path d={values.map((value, index) => `${index ? 'L' : 'M'} ${x(index)} ${y(value)}`).join(' ')} fill="none" stroke={stroke} strokeWidth="3" strokeLinejoin="round" />
            {rows.map((row, index) => <Tooltip key={row.date} title={`${dateLabel(row.date)}: ${formatValue(values[index])}`} arrow><circle cx={x(index)} cy={y(values[index])} r={selected === index ? 6 : 4} fill={stroke} stroke={theme.palette.background.paper} strokeWidth="2" tabIndex={0} aria-label={`${dateLabel(row.date)}: ${formatValue(values[index])}`} onMouseEnter={() => setSelected(index)} onMouseLeave={() => setSelected(null)} onFocus={() => setSelected(index)} onBlur={() => setSelected(null)} /></Tooltip>)}
            {rows.map((row, index) => (index === 0 || index === rows.length - 1 || index === Math.floor(rows.length / 2)) && <text key={row.date} x={x(index)} y="238" textAnchor="middle" fill={theme.palette.text.secondary} fontSize="12">{dateLabel(row.date)}</text>)}
        </Box>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', minHeight: 20 }}>{selectedRow ? `${dateLabel(selectedRow.date)} · ${formatValue(selectedRow[valueKey])}` : 'Hover or focus a point for the exact value.'}</Typography>
    </Box>;
}

export function ChartData({ rows }) {
    const t = useT();
    return <Box component="details" sx={{ mt: 2 }}>
        <Box component="summary" sx={{ color: 'primary.main', fontSize: 13, cursor: 'pointer', py: 1 }}>{t("View chart data")}</Box>
        <TableContainer tabIndex={0} role="region" aria-label={t("Sales trend data")} sx={{ maxHeight: 300 }}><Table size="small" stickyHeader>
            <TableHead><TableRow><TableCell>{t("Date")}</TableCell><TableCell align="right">{t("Sales (TZS)")}</TableCell><TableCell align="right">{t("Orders")}</TableCell></TableRow></TableHead>
            <TableBody>{rows.map((row) => <TableRow key={row.date}><TableCell>{row.date}</TableCell><TableCell align="right">{new Intl.NumberFormat('en-TZ', { maximumFractionDigits: 2 }).format(Number(row.sales))}</TableCell><TableCell align="right">{row.orders}</TableCell></TableRow>)}</TableBody>
        </Table></TableContainer>
    </Box>;
}

export function RankingChart({ rows, formatValue, title, caption }) {
    const t = useT();
    const maximum = Math.max(1, ...rows.map((row) => Number(row.gross) || 0));
    return <Box component="figure" aria-label={title || t("Top sellers by gross sales in TZS")} sx={{ m: 0, p: 2.5 }}>
        <Typography component="figcaption" variant="caption" color="text.secondary" sx={{ display: 'block', mb: 2 }}>{caption || t("Gross sales · TZS")}</Typography>
        {rows.map((row, index) => <Box key={`${row.name}-${index}`} sx={{ mb: index === rows.length - 1 ? 0 : 2.5 }}>
            <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 2, mb: 0.75 }}><Typography variant="body2" sx={{ overflowWrap: 'anywhere' }}>{row.name}</Typography><Typography variant="body2" sx={{ fontWeight: 600, flexShrink: 0 }}>{formatValue(row.gross)}</Typography></Box>
            <Box sx={{ height: 12, borderRadius: 2, bgcolor: 'action.hover', overflow: 'hidden' }}><Box sx={{ height: '100%', width: `${Math.max(0, Number(row.gross) || 0) / maximum * 100}%`, bgcolor: index % 2 ? 'secondary.main' : 'primary.main', borderRadius: 2 }} /></Box>
        </Box>)}
    </Box>;
}
