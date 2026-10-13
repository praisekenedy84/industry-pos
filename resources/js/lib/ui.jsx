import { Link, router, usePage } from '@inertiajs/react';
import { Children, cloneElement, isValidElement, useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import {
    Alert, AppBar, Autocomplete, Avatar, Badge, Box, Breadcrumbs, Button as MuiButton,
    Checkbox, CircularProgress, Collapse, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, Divider, Drawer, FormControl, FormHelperText,
    FormLabel, IconButton, InputAdornment, LinearProgress, List, ListItemButton,
    ListItemIcon, ListItemText, Paper, Popover, Radio, Stack,
    Table as MuiTable, TableBody, TableCell, TableContainer, TableHead, TableRow,
    TextField, Toolbar, Tooltip, Typography,
} from '@mui/material';
import { editGroupedNumber, groupDigits, plainNumber } from '@/lib/groupedNumber';
import { applyTerminology } from '@/lib/terminology';
import { clearSales } from '@/lib/offlineQueue';
import { w } from '@/lib/paths';
import { Bell, Eye, EyeOff, Search, Package, LayoutDashboard, Warehouse, Factory, Truck, ShoppingCart, Users, ChartNoAxesCombined, Settings, ChevronRight, Inbox, Layers, Menu as MenuIcon, PanelLeftClose, PanelLeftOpen, X, LogOut, Monitor, Download, Building2, SlidersHorizontal, LayoutTemplate } from 'lucide-react';
import { Plus, Save, Pencil, Trash2, Archive, Check, CircleCheck, CircleX, Ban, Pause, Play, Send, PackageCheck, ArrowRight, ArrowLeft, ArrowRightLeft, RotateCcw, RefreshCw, Copy, Upload, Printer, ReceiptText, CreditCard, LogIn, LockKeyhole, KeyRound, UserPlus, ShieldCheck, Mail, FileCheck, ClipboardCheck } from 'lucide-react';

const buttonIcons = {
    add: Plus, save: Save, edit: Pencil, delete: Trash2, archive: Archive,
    confirm: Check, approve: CircleCheck, reject: CircleX, cancel: X, suspend: Ban,
    hold: Pause, start: Play, send: Send, receive: PackageCheck, next: ArrowRight,
    back: ArrowLeft, transfer: ArrowRightLeft, reset: RotateCcw, sync: RefreshCw,
    copy: Copy, import: Upload, export: Download, print: Printer, receipt: ReceiptText,
    payment: CreditCard, login: LogIn, logout: LogOut, unlock: LockKeyhole,
    key: KeyRound, invite: UserPlus, security: ShieldCheck, mail: Mail,
    validate: FileCheck, post: ClipboardCheck, filter: SlidersHorizontal,
    monitor: Monitor, settings: Settings, production: Factory, inventory: Warehouse,
};

export function useT() {
    const { translations = {}, fallbackTranslations = {}, terminology = null } = usePage().props;
    return (key, fallback) => {
        const resolved = translations[key] || fallbackTranslations[key] || fallback || key;
        return typeof resolved === 'string' ? applyTerminology(resolved, terminology) : resolved;
    };
}

export function amount(value) {
    return new Intl.NumberFormat('en-TZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0));
}

export function money(value) {
    return `${amount(value)} TZS`;
}

export function quantity(value) {
    if (value == null || value === '') {
        return '';
    }
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return String(value);
    }

    return new Intl.NumberFormat('en-TZ', { maximumFractionDigits: 2 }).format(number);
}

export function rowsOf(value) {
    if (!value) return [];
    if (Array.isArray(value)) return value;
    if (Array.isArray(value.data)) return value.data;
    return Object.values(value);
}

export const inputClass = 'w-full';

function groupedPlaceholder(placeholder) {
    if (placeholder == null || placeholder === '') {
        return placeholder;
    }
    const text = String(placeholder).replace(/,/g, '');
    if (!/^-?\d+(\.\d+)?$/.test(text)) {
        return placeholder;
    }

    return groupDigits(plainNumber(text));
}

function PasswordVisibilityToggle({ visible, disabled, onToggle }) {
    const t = useT();
    const label = visible ? t('Hide password') : t('Show password');

    return (
        <InputAdornment position="end">
            <Tooltip title={label}>
                <IconButton type="button" aria-label={label} aria-pressed={visible} disabled={disabled} edge="end" onClick={onToggle} onMouseDown={(event) => event.preventDefault()}>
                    {visible ? <EyeOff aria-hidden="true" size={18} /> : <Eye aria-hidden="true" size={18} />}
                </IconButton>
            </Tooltip>
        </InputAdornment>
    );
}

export function Input({ label, error, helperText, type = 'text', select = false, multiline = false, multiple = false, children, className = '', inputMode, min, max, step, decimals, pattern, maxLength, 'aria-label': ariaLabel, slotProps = {}, value, onChange, placeholder, inputRef, fieldWidth, sx = [], ...props }) {
    const t = useT();
    const [passwordVisible, setPasswordVisible] = useState(false);
    const numberRef = useRef(null);
    const pendingCaret = useRef(null);
    const grouped = type === 'number' && !select && !multiline;
    const allowDecimal = inputMode !== 'numeric' && !(step !== undefined && step !== 'any' && Number.isInteger(Number(step)) && Number(step) >= 1);
    const numberOptions = {
        allowNegative: !(min !== undefined && min !== '' && Number(min) >= 0),
        allowDecimal,
        maxDecimals: allowDecimal ? (decimals ?? 2) : 0,
    };
    const nativeProps = {
        'aria-label': ariaLabel,
        inputMode: grouped ? (inputMode || (numberOptions.allowDecimal ? 'decimal' : 'numeric')) : inputMode,
        min: grouped ? undefined : min,
        max: grouped ? undefined : max,
        step: grouped ? undefined : step,
        pattern,
        maxLength,
    };
    useLayoutEffect(() => {
        const node = numberRef.current;
        const caret = pendingCaret.current;
        if (!node || caret == null || document.activeElement !== node) {
            return;
        }
        node.setSelectionRange(caret, caret);
        pendingCaret.current = null;
    });
    function assignInputRef(node) {
        numberRef.current = node;
        if (typeof inputRef === 'function') {
            inputRef(node);
        } else if (inputRef) {
            inputRef.current = node;
        }
    }
    function handleGroupedChange(event) {
        const edited = editGroupedNumber(event.target.value, event.target.selectionStart, numberOptions);
        pendingCaret.current = edited.caret;
        if (numberRef.current) {
            numberRef.current.value = edited.formatted;
            numberRef.current.setSelectionRange(edited.caret, edited.caret);
        }
        onChange?.({
            target: { value: edited.plain, name: event.target.name },
            currentTarget: { value: edited.plain, name: event.target.name },
        });
    }
    if (type === 'hidden') return <input type="hidden" value={value} onChange={onChange} {...props} />;
    if (type === 'checkbox' || type === 'radio') {
        const Control = type === 'checkbox' ? Checkbox : Radio;
        return <Control size="small" value={value} onChange={onChange} {...props} slotProps={{ input: nativeProps }} />;
    }
    const displayedValue = grouped && value != null ? groupDigits(plainNumber(value, numberOptions)) : value;
    const revealPassword = type === 'password' && !select && !multiline;
    const preferredWidth = { short: '16rem', medium: '24rem', full: '100%' }[fieldWidth]
        || ({ date: '13rem', 'datetime-local': '18rem', time: '10rem' }[type]);
    const inputSlot = {
        ...slotProps.input,
        ...(grouped ? { sx: [{ fontVariantNumeric: 'tabular-nums' }, slotProps.input?.sx] } : {}),
        ...(revealPassword ? {
            endAdornment: (
                <>
                    {slotProps.input?.endAdornment}
                    <PasswordVisibilityToggle visible={passwordVisible} disabled={Boolean(props.disabled)} onToggle={() => setPasswordVisible((current) => !current)} />
                </>
            ),
        } : {}),
    };
    return (
        <TextField
            {...props}
            type={grouped || (revealPassword && passwordVisible) ? 'text' : type}
            select={select}
            multiline={multiline}
            value={displayedValue}
            placeholder={grouped ? groupedPlaceholder(placeholder) : placeholder}
            onChange={grouped ? handleGroupedChange : onChange}
            inputRef={grouped ? assignInputRef : inputRef}
            label={label || ariaLabel || placeholder}
            error={Boolean(error)}
            helperText={error ? t(error) : helperText}
            className={className}
            sx={[{ minWidth: 0, maxWidth: '100%', ...(preferredWidth ? { width: { xs: '100%', sm: preferredWidth }, flexGrow: 0 } : {}) }, ...(Array.isArray(sx) ? sx : [sx])]}
            slotProps={{
                ...slotProps,
                htmlInput: { ...nativeProps, ...slotProps.htmlInput },
                ...((grouped || revealPassword) ? { input: inputSlot } : {}),
                inputLabel: { ...(select || ['date', 'datetime-local', 'time'].includes(type) ? { shrink: true } : {}), ...slotProps.inputLabel },
                ...(select ? { select: { native: true, multiple, ...slotProps.select } } : {}),
            }}
        >{children}</TextField>
    );
}

export function Field({ label, error, children, className = '', fieldWidth }) {
    const t = useT();
    const id = useId();
    if (Children.count(children) === 1 && isValidElement(children) && children.type === Input) {
        return cloneElement(children, { label, error, id: children.props.id || id, fullWidth: children.props.fullWidth ?? true, fieldWidth: fieldWidth ?? children.props.fieldWidth, className: `${children.props.className || ''} ${className}` });
    }
    return <FormControl fullWidth error={Boolean(error)}><FormLabel htmlFor={id} sx={{ mb: 1, fontSize: 14 }}>{label}</FormLabel>{isValidElement(children) ? cloneElement(children, { id }) : children}{error && <FormHelperText>{t(error)}</FormHelperText>}</FormControl>;
}

export function Button({ children, icon, startIcon, tone = 'primary', type = 'button', className = '', ...props }) {
    const Icon = buttonIcons[icon];
    return <MuiButton type={type} variant={tone === 'primary' || tone === 'danger' ? 'contained' : tone === 'soft' ? 'outlined' : 'text'} color={tone === 'danger' ? 'error' : tone === 'ghost' ? 'inherit' : 'primary'} startIcon={startIcon ?? (Icon ? <Icon aria-hidden="true" focusable="false" /> : undefined)} className={className} {...props}>{children}</MuiButton>;
}

export function ConfirmButton({ title, description, children, onConfirm, icon, ...props }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const id = useId();
    return <>
        <Button {...props} icon={icon} onClick={() => setOpen(true)}>{children}</Button>
        <Dialog open={open} onClose={() => setOpen(false)} aria-labelledby={`${id}-title`} aria-describedby={`${id}-description`}>
            <DialogTitle id={`${id}-title`}>{title}</DialogTitle>
            <DialogContent><DialogContentText id={`${id}-description`}>{description}</DialogContentText></DialogContent>
            <DialogActions><Button icon="cancel" tone="ghost" autoFocus onClick={() => setOpen(false)}>{t("Cancel")}</Button><Button icon={icon} tone="danger" onClick={() => { setOpen(false); onConfirm(); }}>{children}</Button></DialogActions>
        </Dialog>
    </>;
}

export function Card({ children, className = '', sx = {}, ...props }) {
    return <Paper component="section" variant="outlined" className={className} sx={{ borderRadius: '12px', ...sx }} {...props}>{children}</Paper>;
}

export function Table({ columns, rows, empty = 'Nothing here yet.' }) {
    const t = useT();
    if (!rows?.length) return <Stack spacing={2} sx={{ px: 3, py: 7, textAlign: 'center', color: 'text.secondary', alignItems: 'center'}}><Avatar variant="rounded" sx={{ width: 56, height: 56, bgcolor: 'action.hover', color: 'text.secondary' }}><Inbox size={26} /></Avatar><Typography variant="body2" sx={{ maxWidth: 360 }}>{t(empty)}</Typography></Stack>;
    return (
        <TableContainer tabIndex={0} role="region" aria-label={t("Records table. Scroll horizontally to see more columns.")} sx={{ borderRadius: '12px 12px 0 0' }}>
            <MuiTable aria-label={t("Records")}>
                <TableHead><TableRow>{columns.map((column) => <TableCell key={column.key} scope="col" sx={{ whiteSpace: 'nowrap' }}>{t(column.label)}</TableCell>)}</TableRow></TableHead>
                <TableBody>{rows.map((row, index) => <TableRow hover key={row.id || index} sx={{ '&:last-child td': { borderBottom: 0 } }}>{columns.map((column) => <TableCell key={column.key}>{column.render ? column.render(row) : String(row[column.key] ?? '')}</TableCell>)}</TableRow>)}</TableBody>
            </MuiTable>
        </TableContainer>
    );
}

export function Pager({ paginator }) {
    const t = useT();
    if (!paginator?.links) return null;
    return <Stack component="nav" aria-label={t("Pagination")} direction="row"    sx={{ borderTop: 1, borderColor: 'divider', p: 2, alignItems: 'center', flexWrap: 'wrap', gap: 1}}>
        {paginator.total != null && <Typography variant="caption" color="text.secondary" sx={{ mr: 'auto' }}>{paginator.from || 0}–{paginator.to || 0} {t("of")} {paginator.total}</Typography>}
        {paginator.links.map((link, index) => <Button key={index} icon={index === 0 ? 'back' : index === paginator.links.length - 1 ? 'next' : undefined} component={link.url ? Link : 'button'} href={link.url || undefined} disabled={!link.url} variant={link.active ? 'contained' : 'text'} size="small" aria-current={link.active ? 'page' : undefined} sx={{ minWidth: 44 }}><span dangerouslySetInnerHTML={{ __html: link.label }} /></Button>)}
    </Stack>;
}

export function Flash() {
    const t = useT();
    const { flash } = usePage().props;
    const message = flash?.success || flash?.error || flash?.status;
    if (!message) return null;
    return <Alert severity={flash.error ? 'error' : flash.success ? 'success' : 'info'} role={flash.error ? 'alert' : 'status'} sx={{ mb: 3 }}>{t(message)}</Alert>;
}

const sidebarWidth = 248;

const hiddenScrollbar = {
    scrollbarWidth: 'none',
    msOverflowStyle: 'none',
    '&::-webkit-scrollbar': { display: 'none', width: 0, height: 0 },
};

export function Shell({ title, actions, children }) {
    const page = usePage();
    const { auth = {}, nav = [], tenant, impersonation, notifications = [], locale, theme } = page.props;
    const currentPath = page.url.split('?')[0].replace(/\/$/, '') || '/';
    const platform = Boolean(auth.admin) && !auth.user;
    const consoleBase = currentPath === '/admin' || currentPath.startsWith('/admin/') ? '/admin' : '/platform';
    const icons = { dashboard: LayoutDashboard, catalog: Package, inventory: Warehouse, production: Factory, purchasing: Truck, pos: ShoppingCart, sales: ReceiptText, customers: Users, expenses: ChartNoAxesCombined, reports: ChartNoAxesCombined, settings: Settings, workspaces: Building2, features: SlidersHorizontal, templates: LayoutTemplate };
    const activeItem = nav.filter((item) => {
        const section = item.href.replace(/\/$/, '').replace(/\/(items|orders|batches)$/, '');
        return currentPath === section || currentPath.startsWith(section + '/');
    }).sort((a, b) => b.href.length - a.href.length)[0];
    const t = useT();
    const activeChild = activeItem?.children?.filter((item) => currentPath === item.href || currentPath.startsWith(item.href + '/')).sort((a, b) => b.href.length - a.href.length)[0];
    const [expandedSections, setExpandedSections] = useState({});
    useEffect(() => {
        if (activeItem) setExpandedSections((previous) => ({ ...previous, [activeItem.label]: true }));
    }, [activeItem?.label, currentPath]);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [searchState, setSearchState] = useState('idle');
    const [mobileOpen, setMobileOpen] = useState(false);
    const [desktopOpen, setDesktopOpen] = useState(() => {
        if (typeof window === 'undefined') {
            return true;
        }
        try {
            return localStorage.getItem('makerly.sidebar') !== 'closed';
        } catch {
            return true;
        }
    });
    const [accountAnchor, setAccountAnchor] = useState(null);
    const [notesAnchor, setNotesAnchor] = useState(null);
    const [installEvent, setInstallEvent] = useState(null);
    const [navigating, setNavigating] = useState(false);
    const searchRef = useRef(null);
    const standalone = window.matchMedia('(display-mode: standalone)').matches;
    const accountName = auth.user?.name || auth.admin?.name || 'Account';

    useEffect(() => {
        const removeStart = router.on('start', () => setNavigating(true));
        const removeFinish = router.on('finish', () => setNavigating(false));
        const onKey = (event) => {
            if (!platform && (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                searchRef.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => { removeStart(); removeFinish(); window.removeEventListener('keydown', onKey); };
    }, [platform]);

    useEffect(() => {
        setMobileOpen(false);
        setAccountAnchor(null);
        setNotesAnchor(null);
        setQuery('');
    }, [page.url]);

    useEffect(() => {
        const onPrompt = (event) => {
            event.preventDefault();
            if (Date.now() - Number(localStorage.getItem('makerly.install.dismissed') || 0) < 30 * 24 * 60 * 60 * 1000 || window.matchMedia('(display-mode: standalone)').matches) return;
            setInstallEvent(event);
        };
        window.addEventListener('beforeinstallprompt', onPrompt);
        return () => window.removeEventListener('beforeinstallprompt', onPrompt);
    }, []);

    useEffect(() => {
        if (query.trim().length < 2) { setResults([]); setSearchState('idle'); return; }
        const controller = new AbortController();
        setSearchState('loading');
        setResults([]);
        const handle = setTimeout(() => {
            fetch(`${w('/search')}?q=${encodeURIComponent(query.trim())}`, { signal: controller.signal, headers: { Accept: 'application/json' } })
                .then((response) => response.ok ? response.json() : Promise.reject())
                .then((payload) => { if (!controller.signal.aborted) { setResults(payload.results || []); setSearchState('ready'); } })
                .catch(() => { if (!controller.signal.aborted) { setResults([]); setSearchState('error'); } });
        }, 250);
        return () => { clearTimeout(handle); controller.abort(); };
    }, [query]);

    function applyAppearance(choice) {
        try {
            localStorage.setItem('makerly.theme', choice);
        } catch {
            /* Private browsing can block storage. */
        }
        document.cookie = `theme=${choice}; Path=/; Max-Age=31536000; SameSite=Lax`;
        const dark = choice === 'dark' || (choice !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
        if (!platform) {
            router.post('/theme', { theme: choice });
        }
    }

    function toggleDesktopSidebar() {
        setDesktopOpen((open) => {
            const next = !open;
            try {
                localStorage.setItem('makerly.sidebar', next ? 'open' : 'closed');
            } catch {
                /* Private browsing can block storage. */
            }
            return next;
        });
    }

    async function signOut(event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (tenant?.id) await clearSales(tenant.id).catch(() => {});
        form.submit();
    }

    const sidebar = (navigationId) => <Stack className="makerly-sidebar" sx={{ flex: '1 1 auto', alignSelf: 'stretch', height: '100%', maxHeight: '100%', minHeight: 0, overflowX: 'hidden', overflowY: 'auto', overscrollBehavior: 'contain', bgcolor: '#211b43', color: '#f8f6ff', ...hiddenScrollbar }}>
        <Stack direction="row" sx={{ p: 3, alignItems: 'center', gap: 1.5, flexShrink: 0 }}>
            <Avatar variant="rounded" sx={{ bgcolor: '#3b2f70', color: '#f6c965' }}><Layers size={22} /></Avatar>
            <Box sx={{ minWidth: 0, flex: 1 }}><Typography variant="h6" sx={{ fontSize: 20 }}>Makerly</Typography><Typography noWrap variant="caption" sx={{ color: '#bcb3db' }}>{tenant?.name || t("Platform")}</Typography></Box>
            {navigationId === 'mobile' && <IconButton aria-label={t("Close navigation")} onClick={() => setMobileOpen(false)} sx={{ color: 'inherit' }}><X size={20} /></IconButton>}
            {navigationId === 'desktop' && <IconButton aria-label={t("Collapse navigation")} onClick={toggleDesktopSidebar} sx={{ color: 'inherit' }}><PanelLeftClose size={20} /></IconButton>}
        </Stack>
        <Typography variant="overline" sx={{ px: 3, pb: 1, color: '#bcb3db', fontSize: 10, letterSpacing: 2, flexShrink: 0 }}>{platform ? t("Platform") : t("Workspace")}</Typography>
        <List id={`${navigationId}-navigation`} component="nav" aria-label={t("Main navigation")} sx={{ px: 1.5, flexShrink: 0 }}>
            {nav.map((item) => {
                const Icon = icons[item.label.replace('nav.', '')] || Layers;
                const active = activeItem?.href === item.href;
                const fallback = item.label.replace('nav.', '').replace(/^./, (letter) => letter.toUpperCase());
                const primary = !platform && ['nav.catalog', 'nav.production', 'nav.pos'].includes(item.label);
                const hasChildren = !primary && item.children?.length > 0;
                const expanded = expandedSections[item.label] ?? active;
                const submenuId = `${navigationId}-submenu-${item.label.replace('.', '-')}`;
                return <Box key={item.label}>
                    <ListItemButton component={hasChildren ? 'button' : Link} {...(hasChildren ? { type: 'button', onClick: () => setExpandedSections((previous) => ({ ...previous, [item.label]: !expanded })), 'aria-expanded': expanded, 'aria-controls': submenuId } : { href: item.href, 'aria-current': active ? 'page' : undefined })} selected={active} sx={{ width: '100%', borderRadius: 2, mb: 0.5, py: 1.25, color: '#cec7e5', '&:hover': { bgcolor: '#342b5c', color: '#fff' }, '&.Mui-selected': { bgcolor: '#e7dfff', color: '#392578', '&:hover': { bgcolor: '#dacdff' } } }}>
                        <ListItemIcon sx={{ minWidth: 36, color: 'inherit' }}><Icon size={19} strokeWidth={1.7} /></ListItemIcon><ListItemText primary={t(item.label, fallback)} slotProps={{ primary: { fontSize: 14, fontWeight: active ? 650 : 450 } }} />
                        {hasChildren && <ChevronRight size={16} style={{ transform: expanded ? 'rotate(90deg)' : undefined }} />}
                    </ListItemButton>
                    {hasChildren && <Collapse in={expanded} unmountOnExit><List id={submenuId} aria-label={`${t(item.label, fallback)} submenu`} disablePadding sx={{ ml: 3.5, pl: 1, mb: 1, borderLeft: '1px solid #443961' }}>
                        {item.children.map((child) => <ListItemButton key={child.href} component={Link} href={child.href} selected={activeChild?.href === child.href} aria-current={activeChild?.href === child.href ? 'page' : undefined} sx={{ minHeight: 44, borderRadius: 1.5, color: '#cec7e5', '&:hover': { bgcolor: '#342b5c', color: '#fff' }, '&.Mui-selected': { bgcolor: '#3b2f70', color: '#fff', '&:hover': { bgcolor: '#443780' } } }}><ListItemText primary={t(child.label, child.label)} slotProps={{ primary: { fontSize: 13, fontWeight: activeChild?.href === child.href ? 600 : 400 } }} /></ListItemButton>)}
                    </List></Collapse>}
                </Box>;
            })}
        </List>
        <Box sx={{ flexGrow: 1, flexShrink: 0 }} />
        <Box sx={{ m: 2, p: 2, border: '1px solid #443961', borderRadius: 2, flexShrink: 0 }}><Typography sx={{ fontWeight: 600 }} variant="body2">{platform ? 'Every workspace, one console.' : 'One connected workspace.'}</Typography><Typography variant="caption" sx={{ color: '#bcb3db', display: 'block', mt: 0.5 }}>{platform ? 'Features, limits, and templates' : 'Production, inventory & point of sale'}</Typography></Box>
    </Stack>;

    return (
        <Box sx={{ display: 'flex', minHeight: '100vh' }}>
            <a href="#main-content" className="sr-only z-50 rounded-lg bg-primary p-3 text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4">{t("Skip to content")}</a>
            <Box component="aside" sx={{ width: { xs: 0, md: desktopOpen ? sidebarWidth : 0 }, flexShrink: 0, overflow: 'hidden', transition: (theme) => theme.transitions.create('width', { easing: theme.transitions.easing.sharp, duration: desktopOpen ? theme.transitions.duration.enteringScreen : theme.transitions.duration.leavingScreen }) }}>
                <Drawer variant="temporary" open={mobileOpen} onClose={() => setMobileOpen(false)} slotProps={{ paper: { className: 'makerly-sidebar', sx: { width: 280, overflow: 'hidden', ...hiddenScrollbar } } }} sx={{ display: { xs: 'block', md: 'none' } }}>{sidebar('mobile')}</Drawer>
                <Drawer variant="persistent" open={desktopOpen} slotProps={{ paper: { className: 'makerly-sidebar', sx: { width: sidebarWidth, border: 0, overflow: 'hidden', ...hiddenScrollbar } } }} sx={{ display: { xs: 'none', md: 'block' }, overflow: 'hidden' }}>{sidebar('desktop')}</Drawer>
            </Box>
            <Box sx={{ flex: 1, minWidth: 0 }}>
                {impersonation && <Alert severity="warning" sx={{ borderRadius: 0 }} action={<form method="post" action={w('/impersonation/leave')}><Csrf /><Button icon="logout" type="submit" tone="ghost">{t("Leave")}</Button></form>}>{t("You are viewing this workspace as support. Session ends")} {impersonation.expires_at}.</Alert>}
                {!platform && tenant?.trial && <Alert severity="info" sx={{ borderRadius: 0 }}>{t("You can use this workspace until")} {new Date(tenant.trial.ends_at).toLocaleString()}. {t("After that, an admin must approve it. Call +255743463710 or +255715148503, or email info@mernettechnologies.com.")}</Alert>}
                <AppBar position="sticky" color="inherit" elevation={0} sx={{ borderBottom: 1, borderColor: 'divider' }}>
                    <Toolbar sx={{ gap: 1.5, minHeight: { xs: 72 }, px: { xs: 2, lg: 4 } }}>
                        <IconButton aria-label={t("Open navigation")} aria-controls="mobile-navigation" aria-expanded={mobileOpen} onClick={() => setMobileOpen(true)} sx={{ display: { md: 'none' } }}><MenuIcon size={22} /></IconButton>
                        <Tooltip title={desktopOpen ? t("Collapse navigation") : 'Expand navigation'}><IconButton aria-label={desktopOpen ? t("Collapse navigation") : 'Expand navigation'} aria-controls="desktop-navigation" aria-expanded={desktopOpen} onClick={toggleDesktopSidebar} sx={{ display: { xs: 'none', md: 'inline-flex' } }}>{desktopOpen ? <PanelLeftClose size={22} /> : <PanelLeftOpen size={22} />}</IconButton></Tooltip>
                        {platform ? <Box sx={{ flex: 1 }} /> : <Autocomplete
                            sx={{ flex: 1, maxWidth: 460, mr: 'auto' }}
                            options={results}
                            getOptionLabel={(option) => option.label}
                            isOptionEqualToValue={(a, b) => a.href === b.href}
                            filterOptions={(options) => options}
                            inputValue={query}
                            onInputChange={(_, value) => setQuery(value)}
                            onChange={(_, result) => { if (result) router.visit(result.href); }}
                            loading={searchState === 'loading'}
                            loadingText="Searching…"
                            noOptionsText={searchState === 'error' ? 'Search unavailable. Try again.' : query.trim().length < 2 ? 'Type at least 2 characters to search.' : 'No matching items or sales.'}
                            renderOption={(props, option) => { const { key, ...optionProps } = props; return <li key={option.href} {...optionProps}>{option.label}</li>; }}
                            renderInput={(params) => <TextField {...params} inputRef={searchRef} label={t('search.placeholder', 'Search items or sales')} slotProps={{ ...params.slotProps, input: { ...params.slotProps.input, startAdornment: <InputAdornment position="start"><Search size={18} /></InputAdornment>, endAdornment: <>{searchState === 'loading' && <CircularProgress size={16} />}{params.slotProps.input.endAdornment}</> }, htmlInput: { ...params.slotProps.htmlInput, 'aria-label': 'Search items or sales (Ctrl+K)' } }} />}
                        />}
                        {!platform && <Tooltip title={t("Notifications")}><IconButton aria-label={`Notifications (${notifications.length})`} aria-haspopup="dialog" aria-expanded={Boolean(notesAnchor)} onClick={(event) => setNotesAnchor(event.currentTarget)}><Badge badgeContent={notifications.length} color="error"><Bell size={21} /></Badge></IconButton></Tooltip>}
                        <Tooltip title={t("Account & preferences")}><IconButton aria-label={t("Account & preferences")} aria-haspopup="dialog" aria-expanded={Boolean(accountAnchor)} onClick={(event) => setAccountAnchor(event.currentTarget)} sx={{ p: 0.5 }}><Avatar sx={{ width: 36, height: 36, bgcolor: 'primary.main', color: 'primary.contrastText', fontSize: 14 }}>{accountName.split(' ').map((word) => word[0]).slice(0, 2).join('').toUpperCase()}</Avatar></IconButton></Tooltip>
                    </Toolbar>
                    {navigating && <LinearProgress aria-label={t("Loading page")} sx={{ position: 'absolute', bottom: 0, width: '100%', height: 2 }} />}
                </AppBar>
                <Popover open={Boolean(notesAnchor)} anchorEl={notesAnchor} onClose={() => setNotesAnchor(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }} transformOrigin={{ vertical: 'top', horizontal: 'right' }} slotProps={{ paper: { role: 'dialog', 'aria-label': t("Notifications"), sx: { width: 360, maxWidth: 'calc(100vw - 32px)', mt: 1, p: 2, border: 1, borderColor: 'divider' } } }}>
                    <Typography variant="h6" sx={{ mb: 2 }}>{t("Notifications")}</Typography>
                    {!notifications.length && <Stack spacing={1} sx={{ py: 3, color: 'text.secondary', alignItems: 'center'}}><Bell size={26} /><Typography variant="body2">{t("You're all caught up.")}</Typography></Stack>}
                    {notifications.map((note) => <Box key={note.id} component={note.data?.url ? Link : 'div'} href={note.data?.url} sx={{ display: 'block', textDecoration: 'none', color: 'inherit', py: 1.5, borderTop: 1, borderColor: 'divider' }}><Typography sx={{ fontWeight: 600 }} variant="body2">{t(note.title)}</Typography><Typography variant="body2" color="text.secondary">{note.type === 'production_follow_up' ? `${note.body.split(' / ')[0]} / ${t(`status.${note.data?.status}`, note.data?.status)}` : note.body}</Typography></Box>)}
                </Popover>
                <Popover open={Boolean(accountAnchor)} anchorEl={accountAnchor} onClose={() => setAccountAnchor(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }} transformOrigin={{ vertical: 'top', horizontal: 'right' }} slotProps={{ paper: { role: 'dialog', 'aria-label': t("Account & preferences"), sx: { width: 320, maxWidth: 'calc(100vw - 32px)', mt: 1, p: 2.5, border: 1, borderColor: 'divider' } } }}>
                    <Stack spacing={2}>
                        <Box><Typography sx={{ fontWeight: 650 }} >{accountName}</Typography><Typography variant="caption" color="text.secondary">{platform ? 'Platform admin' : `${tenant?.name || t("Workspace")} · Workspace & preferences`}</Typography></Box>
                        <Divider />
                        {!platform && auth.workspaces?.length > 0 && <Input select label={t("Switch workspace")} defaultValue="" onChange={(event) => { if (event.target.value) window.location.href = event.target.value; }}><option value="">{t("Choose a workspace")}</option>{auth.workspaces.map((workspace) => <option key={workspace.slug} value={workspace.href}>{workspace.name}</option>)}</Input>}
                        {!platform && auth.branches?.length > 0 && <Input select label={t("Branch")} value={auth.branch_id || ''} onChange={(event) => router.post('/branch', { branch_id: event.target.value })}>{auth.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</Input>}
                        {!platform && <Input select label={t("Language")} value={locale || 'en'} onChange={(event) => router.post('/locale', { locale: event.target.value })}><option value="en">{t("English")}</option><option value="sw">{t("Kiswahili")}</option></Input>}
                        <Input select label={t("Appearance")} value={theme || 'system'} onChange={(event) => applyAppearance(event.target.value)}><option value="system">{t("System")}</option><option value="light">{t("Light")}</option><option value="dark">{t("Dark")}</option></Input>
                        {!platform && <Button icon="monitor" component={Link} tone="ghost" href={w('/account/sessions')}>{t("Manage sessions")}</Button>}
                        {!platform && installEvent && !standalone && <Button icon="export" tone="soft" onClick={async () => { installEvent.prompt(); const choice = await installEvent.userChoice; if (choice.outcome === 'dismissed') localStorage.setItem('makerly.install.dismissed', String(Date.now())); setInstallEvent(null); }}>{t("Install")} {tenant?.name || 'app'}</Button>}
                        {!platform && !installEvent && !standalone && /iPhone|iPad/.test(navigator.userAgent) && <Typography variant="caption" color="text.secondary">{t("Share, then Add to Home Screen to install")} {tenant?.name}</Typography>}
                        <Divider />
                        <form method="post" action={platform ? `${consoleBase}/logout` : w('/logout')} onSubmit={signOut}>
                            <Csrf />
                            {!platform && <Input select fullWidth label={t("Sign out from")} name="scope" defaultValue="device"><option value="workspace">{t("This workspace")}</option><option value="device">{t("This device")}</option><option value="everywhere">{t("Everywhere")}</option></Input>}
                            <Button icon="logout" type="submit" tone="ghost" fullWidth sx={{ mt: platform ? 0 : 1 }}>{t('auth.logout', 'Sign out')}</Button>
                        </form>
                    </Stack>
                </Popover>
                <Box component="main" id="main-content" tabIndex={-1} sx={{ maxWidth: 1440, mx: 'auto', p: { xs: 2, sm: 3, lg: 4 } }}>
                    <Breadcrumbs separator={<ChevronRight size={13} />} aria-label={t("Breadcrumb")} sx={{ mb: 1.5, fontSize: 12 }}><Typography variant="caption" color="text.secondary">{platform ? t("Platform") : (tenant?.name || t("Workspace"))}</Typography><Typography variant="caption">{activeItem ? t(activeItem.label, 'Overview') : t("Overview")}</Typography>{activeChild && <Typography variant="caption">{t(activeChild.label, activeChild.label)}</Typography>}</Breadcrumbs>
                    <Stack direction={{ xs: 'column', sm: 'row' }}    sx={{ mb: 3, justifyContent: 'space-between', alignItems: { xs: 'flex-start', sm: 'center' }, gap: 2}}><Typography component="h1" variant="h4">{title}</Typography>{actions && <Stack sx={{ flexWrap: 'wrap', gap: 1 }} direction="row">{actions}</Stack>}</Stack>
                    <Flash />
                    {children}
                </Box>
            </Box>
        </Box>
    );
}

export function AuthCard({ title, kicker = 'Your connected workspace', children }) {
    const t = useT();
    return <Stack spacing={3} sx={{ minHeight: '100vh', px: 2, py: 5, background: 'radial-gradient(ellipse at top, var(--color-sticker-blue), transparent 65%)', alignItems: 'center', justifyContent: 'center'}}>
        <Stack sx={{ alignItems: 'center', gap: 1.25 }} direction="row"><Avatar variant="rounded" sx={{ bgcolor: 'primary.main', color: 'primary.contrastText' }}><Layers size={23} /></Avatar><Typography variant="h5">Makerly</Typography></Stack>
        <Card sx={{ width: '100%', maxWidth: 460, p: { xs: 3, sm: 4 } }}><Typography variant="overline" color="primary" sx={{ fontSize: 10, letterSpacing: 2 }}>{t(kicker)}</Typography><Typography component="h1" variant="h5" sx={{ mt: 0.5, mb: 3 }}>{title}</Typography><Flash />{children}</Card>
        <Typography variant="caption" color="text.secondary">{t("Production & point of sale, in one workspace.")}</Typography>
    </Stack>;
}

export function useConsoleBase() {
    const page = usePage();
    const path = page.url.split('?')[0];
    const fromNav = page.props.nav?.[0]?.href;
    if (fromNav === '/admin' || fromNav === '/platform') {
        return fromNav;
    }

    return path === '/admin' || path.startsWith('/admin/') ? '/admin' : '/platform';
}

export function words(value) {
    return String(value || '').replaceAll('.', ' ').replaceAll('_', ' ').replace(/^module\s+/, '');
}

export function Csrf() {
    const shared = usePage().props.csrf;
    const token = shared || document.querySelector('meta[name="csrf-token"]')?.content || '';
    return <input type="hidden" name="_token" value={token} autoComplete="off" />;
}

export function Errors({ errors }) {
    const t = useT();
    const list = Object.values(errors || {}).flat();
    if (!list.length) return null;
    return <Alert severity="error" sx={{ mb: 2 }}><Typography variant="body2" sx={{ mb: 0.5, fontWeight: 600}}>{t("Please check the following:")}</Typography>{list.map((error, index) => <Typography variant="body2" key={index}>{error}</Typography>)}</Alert>;
}
