import { useT } from '@/lib/ui';
import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Alert, Avatar, Box, CardActionArea, Chip, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, Divider, IconButton, InputAdornment, Stack, Tooltip, Typography } from '@mui/material';
import { CreditCard, Minus, Package, Plus, Search, ShoppingCart, Trash2, Wifi, WifiOff, X } from 'lucide-react';
import { amount, Button, Card, Errors, Input, money, quantity, Shell } from '@/lib/ui';
import { clearSales, pendingSales, queueSale } from '@/lib/offlineQueue';
import { allSalesSynced, hasInvalidQuantities, preparePayments, steppedQuantity } from '@/lib/checkout';
import { w } from '@/lib/paths';

export default function Terminal({ items, categories, customers, methods, drafts, offline, prices = [], defaultPriceListId }) {
    const t = useT();
    const { tenant, auth } = usePage().props;
    const defaultMethod = methods.find((row) => row.key === 'cash')?.key || methods[0]?.key || 'cash';
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('');
    const [origin, setOrigin] = useState('');
    const [cart, setCart] = useState([]);
    const [payments, setPayments] = useState([{ method: defaultMethod, amount: '' }]);
    const [customerId, setCustomerId] = useState('');
    const priceFor = (item, selectedCustomer = customerId) => {
        const listId = customers.find((customer) => customer.id === selectedCustomer)?.price_list_id || defaultPriceListId;
        return prices.find((price) => price.item_id === item.id && price.price_list_id === listId)?.price ?? item.default_price;
    };
    const chooseCustomer = (id) => {
        setCustomerId(id);
        setCart((current) => current.map((line) => ({ ...line, unit_price: priceFor(items.find((item) => item.id === line.item_id) || { id: line.item_id, default_price: line.unit_price }, id) })));
    };
    const [pin, setPin] = useState('');
    const [online, setOnline] = useState(navigator.onLine);
    const [queued, setQueued] = useState(0);
    const [busy, setBusy] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [message, setMessage] = useState(null);
    const [cartSaveFailed, setCartSaveFailed] = useState(false);
    const [confirmClear, setConfirmClear] = useState(false);
    const paymentInFlight = useRef(false);
    const form = useForm({ lines: [], payments: [], customer_id: '', approval_pin: '', idempotency_key: '' });
    const visible = items.filter((item) => (!origin || (origin === 'finished_good' ? item.is_produced : origin === 'trading_good' ? !item.is_produced && item.type !== 'service' : item.type === origin)) && (!category || String(item.category_id) === String(category)) && (!query || item.name.toLowerCase().includes(query.toLowerCase()) || item.sku?.toLowerCase().includes(query.toLowerCase())));
    const total = useMemo(() => cart.reduce((sum, line) => sum + Number(line.unit_price) * Number(line.qty) - Number(line.discount || 0), 0), [cart]);
    const invalidQuantity = hasInvalidQuantities(cart);
    const locked = busy || form.processing || syncing;

    useEffect(() => {
        const onStatus = () => setOnline(navigator.onLine);
        const onKey = (event) => {
            if (event.key === 'F2') { event.preventDefault(); document.getElementById('pos-search')?.focus(); }
            if (event.key === 'F8') { event.preventDefault(); document.getElementById('pos-pay')?.click(); }
        };
        window.addEventListener('online', onStatus);
        window.addEventListener('offline', onStatus);
        window.addEventListener('keydown', onKey);
        if (tenant?.id) pendingSales(tenant.id).then((rows) => setQueued(rows.length)).catch(() => setMessage({ severity: 'error', text: 'Could not read offline sales. Keep this device open and try again.' }));
        return () => { window.removeEventListener('online', onStatus); window.removeEventListener('offline', onStatus); window.removeEventListener('keydown', onKey); };
    }, [tenant?.id]);

    useEffect(() => {
        localStorage.setItem('makerly.pos.cartCount', String(cart.length));
        const controller = new AbortController();
        const handle = setTimeout(() => {
            fetch(w('/pos/cart'), { method: 'POST', signal: controller.signal, headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json' }, body: JSON.stringify({ cart }) })
                .then((response) => { if (!controller.signal.aborted) setCartSaveFailed(!response.ok); })
                .catch(() => { if (!controller.signal.aborted) setCartSaveFailed(true); });
        }, 400);
        return () => { clearTimeout(handle); controller.abort(); };
    }, [cart]);

    function add(item) {
        setCart((current) => {
            const found = current.find((line) => line.item_id === item.id);
            if (found) return current.map((line) => line.item_id === item.id ? { ...line, qty: steppedQuantity(line.qty, 1) ?? line.qty } : line);
            return [...current, { item_id: item.id, name: item.name, qty: '1', unit_price: priceFor(item), discount: '0' }];
        });
        form.clearErrors();
        setMessage(null);
    }

    function changeQuantity(itemId, delta) {
        setCart((current) => current.map((line) => {
            if (line.item_id !== itemId) {
                return line;
            }
            const next = steppedQuantity(line.qty, delta);
            return next === null ? line : { ...line, qty: next };
        }));
    }

    async function pay() {
        if (paymentInFlight.current || !cart.length || invalidQuantity) return;
        let prepared;
        try { prepared = preparePayments(payments, total); }
        catch (error) { setMessage({ severity: 'error', text: error.message }); return; }
        const payload = {
            lines: cart.map(({ item_id, qty, unit_price, discount }) => ({ item_id, qty, unit_price, discount })),
            payments: prepared,
            customer_id: customerId || null,
            approval_pin: pin,
            idempotency_key: crypto.randomUUID(),
        };
        paymentInFlight.current = true;
        setBusy(true);
        setMessage(null);
        if (!online && offline) {
            try {
                await queueSale({ tenant_id: tenant?.id, user_id: auth?.user?.id, sale: payload });
                setQueued((count) => count + 1);
                setCart([]);
                setPayments([{ method: defaultMethod, amount: '' }]);
                setPin('');
                setCustomerId('');
                setMessage({ severity: 'success', text: 'Sale saved on this device. Sync when you are back online.' });
            } catch {
                setMessage({ severity: 'error', text: 'The sale could not be saved on this device. Your cart has been kept. Try again.' });
            } finally { paymentInFlight.current = false; setBusy(false); }
            return;
        }
        form.transform(() => payload); form.post('/pos/sales', { onFinish: () => { paymentInFlight.current = false; setBusy(false); } });
    }

    async function sync() {
        if (syncing) return;
        setSyncing(true);
        setMessage(null);
        try {
            const rows = tenant?.id ? await pendingSales(tenant.id) : [];
            if (!rows.length) { setQueued(0); return; }
            const response = await fetch(w('/pos/sync'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json' }, body: JSON.stringify({ sales: rows.map((row) => row.sale) }) });
            if (!response.ok) throw new Error('Sales could not be synced. Please try again.');
            const payload = await response.json();
            if (!allSalesSynced(rows, payload.results)) throw new Error('Some sales need review. They remain saved on this device; resolve the conflicts before syncing again.');
            await clearSales(tenant.id);
            setQueued(0);
            setMessage({ severity: 'success', text: 'All offline sales synced.' });
            router.reload();
        } catch (error) { setMessage({ severity: 'error', text: error.message }); }
        finally { setSyncing(false); }
    }

    return (
        <Shell title={t("Point of sale")} actions={<Chip icon={online ? <Wifi size={15} /> : <WifiOff size={15} />} label={`${online ? 'Online' : 'Offline'}${queued ? ` · ${queued} waiting to sync` : ''}`} color={online ? 'success' : 'warning'} variant="outlined" size="small" />}>
            {!online && <Alert severity="warning" sx={{ mb: 2 }}>{offline ? 'You are offline. Sales will be saved on this device until you sync.' : 'Connect to the internet to complete a sale.'}</Alert>}
            {message && <Alert severity={message.severity} onClose={() => setMessage(null)} sx={{ mb: 2 }}>{message.text}</Alert>}
            {cartSaveFailed && cart.length > 0 && <Alert severity="warning" sx={{ mb: 2 }}>{t("Your cart could not be saved to the server. Keep this tab open.")}</Alert>}
            <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: 'minmax(0, 1fr)', lg: 'minmax(0, 1fr) 380px' }, alignItems: 'start' }}>
                <Box sx={{ minWidth: 0 }}>
                    <Input id="pos-search" fullWidth label={t("Search products or SKU (F2)")} value={query} onChange={(event) => setQuery(event.target.value)} slotProps={{ input: { startAdornment: <InputAdornment position="start"><Search size={18} /></InputAdornment> } }} />
                    <Stack direction="row" spacing={1} sx={{ mb: 1 }}>{[['', t("All origins")], ['finished_good', t("Produced products")], ['trading_good', t("Traded goods")], ['service', t("Services")]].map(([value, label]) => <Chip key={value} label={label} clickable color={origin === value ? 'primary' : 'default'} onClick={() => setOrigin(value)} />)}</Stack>
                    <Stack direction="row" spacing={1} sx={{ overflowX: 'auto', py: 2 }}><Chip label={t("All products")} clickable color={category === '' ? 'primary' : 'default'} onClick={() => setCategory('')} />{categories.map((row) => <Chip key={row.id} label={row.name} clickable color={category === row.id ? 'primary' : 'default'} onClick={() => setCategory(row.id)} />)}</Stack>
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 2 }}>{visible.length} {t("products · Select a product to add it to the order")}</Typography>
                    <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(2, minmax(0, 1fr))', sm: 'repeat(3, minmax(0, 1fr))' }, gap: 1.5 }}>
                        {visible.map((item) => <Card key={item.id} sx={{ overflow: 'hidden' }}><CardActionArea disabled={locked} onClick={() => add(item)} sx={{ height: '100%', p: 2, textAlign: 'left' }}><Avatar variant="rounded" sx={{ bgcolor: 'action.hover', color: 'primary.main', mb: 2 }}><Package size={22} strokeWidth={1.5} /></Avatar><Typography variant="body2" sx={{ fontWeight: 600, mb: 0.5 }}>{item.name}</Typography><Typography variant="caption" color="text.secondary">{item.sku || 'No SKU'}</Typography><Typography variant="caption" display="block" color="text.secondary">{item.type === 'service' ? t('Service') : item.is_produced ? t('Produced') : t('Traded')}{item.type !== 'service' ? ` / ${t('Stock')}: ${quantity(item.available_qty || 0)}` : ''}</Typography><Typography variant="body2" color="primary" sx={{ fontWeight: 650, mt: 1.5 }}>{money(priceFor(item))}</Typography></CardActionArea></Card>)}
                    </Box>
                    {!visible.length && <Card sx={{ p: 5, textAlign: 'center' }}><Package size={30} className="mx-auto mb-3 text-muted" /><Typography variant="h6">{t("No products found")}</Typography><Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>{t("Try a different name, SKU or category.")}</Typography><Button icon="reset" tone="ghost" onClick={() => { setQuery(''); setCategory(''); setOrigin(''); }} sx={{ mt: 2 }}>{t("Clear filters")}</Button></Card>}
                </Box>
                <Card sx={{ position: { lg: 'sticky' }, top: 96, overflow: 'hidden' }}>
                    <Stack direction="row" sx={{ alignItems: 'center', justifyContent: 'space-between', p: 2.5 }}><Typography variant="h6">{t("Current order")} <Chip label={cart.length} size="small" sx={{ ml: 1 }} /></Typography><Tooltip title={t("Clear order")}><span><IconButton aria-label={t("Clear order")} size="small" disabled={!cart.length || locked} onClick={() => setConfirmClear(true)}><Trash2 size={18} /></IconButton></span></Tooltip></Stack>
                    <Divider />
                    <Box sx={{ maxHeight: 340, overflowY: 'auto', p: 2.5 }}>
                        {!cart.length && <Stack spacing={1.5} sx={{ alignItems: 'center', py: 4, textAlign: 'center', color: 'text.secondary' }}><ShoppingCart size={32} strokeWidth={1.4} /><Typography variant="body2">{t("Your order is empty.")}</Typography><Typography variant="caption">{t("Select a product to get started.")}</Typography></Stack>}
                        {cart.map((line, index) => <Box key={line.item_id} sx={{ mb: 2, '&:last-child': { mb: 0 } }}><Stack direction="row" sx={{ alignItems: 'center', justifyContent: 'space-between', gap: 1 }}><Typography variant="body2" sx={{ fontWeight: 600 }}>{line.name}</Typography><IconButton size="small" aria-label={`Remove ${line.name}`} disabled={locked} onClick={() => setCart((current) => current.filter((row) => row.item_id !== line.item_id))}><X size={16} /></IconButton></Stack><Stack direction="row" sx={{ alignItems: 'center', gap: 0.5, mt: 1, flexWrap: 'wrap' }}><Tooltip title={t("Decrease quantity")}><span><IconButton size="small" aria-label={`${t("Decrease quantity")}: ${line.name}`} disabled={locked || steppedQuantity(line.qty, -1) === null} onClick={() => changeQuantity(line.item_id, -1)}><Minus size={16} /></IconButton></span></Tooltip><Input type="number" label={t("Qty")} aria-label={`Quantity for ${line.name}`} inputMode="decimal" min="0.01" step="0.01" value={line.qty} disabled={locked} error={!Number.isFinite(Number(line.qty)) || Number(line.qty) <= 0 ? 'Enter a quantity above zero.' : undefined} onChange={(event) => setCart((current) => current.map((row, i) => i === index ? { ...row, qty: event.target.value } : row))} sx={{ width: 96 }} /><Tooltip title={t("Increase quantity")}><span><IconButton size="small" aria-label={`${t("Increase quantity")}: ${line.name}`} disabled={locked} onClick={() => changeQuantity(line.item_id, 1)}><Plus size={16} /></IconButton></span></Tooltip><Typography variant="body2" sx={{ ml: 'auto', fontWeight: 600, textAlign: 'right' }}>{Number.isFinite(Number(line.qty)) ? money(Number(line.unit_price) * Number(line.qty) - Number(line.discount || 0)) : '—'}</Typography></Stack></Box>)}
                    </Box>
                    <Divider />
                    <Box sx={{ p: 2.5 }}>
                        <Stack direction="row" sx={{ alignItems: 'center', justifyContent: 'space-between', mb: 1 }}><Typography variant="body2" color="text.secondary">{t("Estimated total")}</Typography><Typography variant="h5">{Number.isFinite(total) ? money(total) : '—'}</Typography></Stack>
                        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 3 }}>{t("Final tax and payment totals are confirmed when the sale is completed.")}</Typography>
                        <Errors errors={form.errors} />
                        <Stack spacing={2}>
                            <Input select fullWidth label={t("Customer")} value={customerId} disabled={locked} onChange={(event) => chooseCustomer(event.target.value)}><option value="">{t("Walk-in customer")}</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</Input>
                            {payments.map((payment, index) => <Stack key={index} direction="row" spacing={1} sx={{ alignItems: 'flex-start' }}><Input select label={t("Payment method")} value={payment.method} disabled={locked} onChange={(event) => setPayments(payments.map((row, i) => i === index ? { ...row, method: event.target.value } : row))} sx={{ flex: 1, minWidth: 0 }}>{methods.map((method) => <option key={method.key} value={method.key}>{method.name}</option>)}</Input><Input type="number" min="0" step="any" inputMode="decimal" label={t("Amount (TZS)")} slotProps={{ inputLabel: { shrink: true } }} placeholder={payments.length === 1 ? (Number.isFinite(total) ? amount(total) : amount(0)) : 'Enter amount'} value={payment.amount} disabled={locked} onChange={(event) => setPayments(payments.map((row, i) => i === index ? { ...row, amount: event.target.value } : row))} sx={{ flex: 1, minWidth: 0 }} />{payments.length > 1 && <IconButton aria-label={`Remove payment ${index + 1}`} disabled={locked} size="small" onClick={() => setPayments(payments.filter((_, i) => i !== index))}><X size={16} /></IconButton>}</Stack>)}
                            <Button icon="add" tone="ghost" disabled={locked || !cart.length} onClick={() => setPayments([...payments, { method: defaultMethod, amount: '' }])}>{t("Add split payment")}</Button>
                            <Input fullWidth label={t("Manager PIN (if required)")} type="password" autoComplete="off" inputMode="numeric" value={pin} disabled={locked} onChange={(event) => setPin(event.target.value)} />
                            <Button id="pos-pay" fullWidth loading={locked} disabled={!cart.length || invalidQuantity || (!online && !offline)} onClick={pay} startIcon={<CreditCard size={18} />}>{!online && offline ? 'Save offline sale' : 'Take payment'} (F8)</Button>
                            <Stack direction="row" spacing={1}><Button icon="hold" tone="soft" fullWidth disabled={!cart.length || locked} onClick={() => router.post('/pos/drafts', { name: 'Held cart', cart }, { onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: (errors) => setMessage({ severity: 'error', text: Object.values(errors).flat().join(' ') || 'The order could not be held. Your cart has been kept.' }), onSuccess: () => { setCart([]); setPayments([{ method: defaultMethod, amount: '' }]); setPin(''); setCustomerId(''); } })}>{t("Hold order")}</Button>{queued > 0 && <Button icon="sync" tone="soft" loading={syncing} disabled={!online || busy || form.processing} onClick={sync}>Sync ({queued})</Button>}</Stack>
                            {drafts.length > 0 && <Input select fullWidth label={t("Resume a held order")} value="" disabled={locked || cart.length > 0} helperText={cart.length ? 'Hold or clear the current order before resuming another.' : undefined} onChange={(event) => { const draft = drafts.find((row) => row.id === event.target.value); if (draft) setCart(draft.cart || []); }}><option value="">{t("Choose an order")}</option>{drafts.map((draft) => <option key={draft.id} value={draft.id}>{draft.name}</option>)}</Input>}
                        </Stack>
                    </Box>
                </Card>
            </Box>
            <Dialog open={confirmClear} onClose={() => setConfirmClear(false)} aria-labelledby="clear-order-title"><DialogTitle id="clear-order-title">{t("Clear this order?")}</DialogTitle><DialogContent><DialogContentText>{t("All items will be removed from the current order. You can hold it instead if you want to return to it later.")}</DialogContentText></DialogContent><DialogActions><Button icon="back" tone="ghost" onClick={() => setConfirmClear(false)}>{t("Keep order")}</Button><Button icon="delete" tone="danger" onClick={() => { setCart([]); setPayments([{ method: defaultMethod, amount: '' }]); setPin(''); setCustomerId(''); form.clearErrors(); setConfirmClear(false); }}>{t("Clear order")}</Button></DialogActions></Dialog>
        </Shell>
    );
}
