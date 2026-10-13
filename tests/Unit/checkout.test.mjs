import assert from 'node:assert/strict';
import test from 'node:test';
import { allSalesSynced, hasInvalidQuantities, preparePayments, steppedQuantity } from '../../resources/js/lib/checkout.js';

test('a single blank payment defaults to the displayed estimate', () => {
    assert.deepEqual(preparePayments([{ method: 'cash', amount: '' }], 12000), [{ method: 'cash', amount: 12000 }]);
});

test('split payments require explicit amounts instead of charging the full total twice', () => {
    assert.throws(() => preparePayments([{ method: 'cash', amount: 5000 }, { method: 'mobile', amount: '' }], 12000), /each split payment/);
    assert.deepEqual(preparePayments([{ method: 'cash', amount: '5000' }, { method: 'mobile', amount: '7000' }], 12000).map((row) => row.amount), [5000, 7000]);
});

test('invalid payment values are rejected while zero account amounts remain supported', () => {
    for (const amount of ['invalid', '-1', 'Infinity']) assert.throws(() => preparePayments([{ method: 'cash', amount }], 12000));
    assert.throws(() => preparePayments([], 12000));
    assert.equal(preparePayments([{ method: 'account', amount: '0' }], 12000)[0].amount, 0);
    assert.equal(preparePayments([{ method: 'cash', amount: '' }], 12000.239)[0].amount, 12000.24);
    assert.equal(preparePayments([{ method: 'cash', amount: '15.239' }], 20)[0].amount, 15.24);
});

test('quantities must be positive finite numbers, including fractional quantities', () => {
    for (const qty of ['', '0', '-2', 'abc', 'Infinity']) assert.equal(hasInvalidQuantities([{ qty }]), true);
    assert.equal(hasInvalidQuantities([{ qty: '1.5' }, { qty: '2' }]), false);
});

test('quantity buttons step by one and stop above zero', () => {
    assert.equal(steppedQuantity('1', 1), '2');
    assert.equal(steppedQuantity('2.5', -1), '1.5');
    assert.equal(steppedQuantity('1.1', 1), '2.1');
    assert.equal(steppedQuantity('', 1), '1');
    assert.equal(steppedQuantity('abc', 1), '1');
    assert.equal(steppedQuantity('1', -1), null);
    assert.equal(steppedQuantity('0.4', -1), null);
    assert.equal(steppedQuantity('', -1), null);
});

test('offline sales are retained if any sync result is missing or has a conflict', () => {
    const sales = [{ sale: { idempotency_key: 'a' } }, { sale: { idempotency_key: 'b' } }];
    const synced = { idempotency_key: 'a', status: 'synced' };
    assert.equal(allSalesSynced(sales, [synced]), false);
    assert.equal(allSalesSynced(sales, [synced, { idempotency_key: 'b', status: 'conflict' }]), false);
    assert.equal(allSalesSynced(sales, undefined), false);
    assert.equal(allSalesSynced([], []), false);
    assert.equal(allSalesSynced(sales, [synced, { idempotency_key: 'b', status: 'synced' }]), true);
});
