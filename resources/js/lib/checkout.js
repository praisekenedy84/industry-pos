function roundMoney(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return number;
    }

    return Math.round((number + Number.EPSILON) * 100) / 100;
}

export function preparePayments(payments, total) {
    if (!payments.length) throw new Error('Choose a payment method.');
    return payments.map((payment) => {
        const blank = String(payment.amount ?? '').trim() === '';
        if (blank && payments.length > 1) throw new Error('Enter an amount for each split payment.');
        const amount = roundMoney(blank ? total : payment.amount);
        if (!Number.isFinite(amount) || amount < 0) throw new Error('Payment amounts must be zero or greater.');
        return { ...payment, amount };
    });
}

export function hasInvalidQuantities(cart) {
    return cart.some((line) => !Number.isFinite(Number(line.qty)) || Number(line.qty) <= 0);
}

export function steppedQuantity(qty, delta) {
    const current = Number(qty);
    if (!Number.isFinite(current)) {
        return delta > 0 ? '1' : null;
    }
    const next = current + delta;
    if (!Number.isFinite(next) || next <= 0) {
        return null;
    }

    return String(Number(next.toPrecision(12)));
}

export function allSalesSynced(sales, results) {
    return sales.length > 0 && Array.isArray(results) && sales.every((row) => results.some((result) => result.idempotency_key === row.sale.idempotency_key && result.status === 'synced'));
}
