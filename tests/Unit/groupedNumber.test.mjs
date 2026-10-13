import assert from 'node:assert/strict';
import test from 'node:test';
import { editGroupedNumber, groupDigits, plainNumber } from '../../resources/js/lib/groupedNumber.js';

test('groups digits in threes while typing a currency amount', () => {
    assert.equal(groupDigits('1000'), '1,000');
    assert.equal(groupDigits('1234567.89'), '1,234,567.89');
    assert.equal(groupDigits('1.'), '1.');
    assert.equal(groupDigits('0.50'), '0.50');
    assert.equal(groupDigits('-2500000'), '-2,500,000');
    assert.equal(groupDigits(''), '');
});

test('stored values stay plain numbers so forms do not submit commas', () => {
    assert.equal(plainNumber('1,234,567.89'), '1234567.89');
    assert.equal(plainNumber('TZS 12,500.50'), '12500.50');
    assert.equal(plainNumber('1.2.3'), '1.23');
    assert.equal(plainNumber('0005'), '5');
    assert.equal(plainNumber('0.50'), '0.50');
    assert.equal(plainNumber('.'), '0.');
    assert.equal(plainNumber('-'), '-');
    assert.equal(plainNumber('-12.5'), '-12.5');
    assert.equal(plainNumber('5.5', { allowDecimal: false }), '5');
    assert.equal(plainNumber('-5', { allowNegative: false }), '5');
    assert.equal(plainNumber('1500.0000'), '1500.00');
    assert.equal(plainNumber('1.2399'), '1.23');
    assert.equal(plainNumber('10.'), '10.');
    assert.equal(plainNumber('0.000001', { maxDecimals: 6 }), '0.000001');
});

test('the cursor stays with the digits when a comma is inserted', () => {
    assert.deepEqual(editGroupedNumber('1000', 4), { plain: '1000', formatted: '1,000', caret: 5 });
    assert.deepEqual(editGroupedNumber('12,000', 2), { plain: '12000', formatted: '12,000', caret: 2 });
    assert.deepEqual(editGroupedNumber('.', 1), { plain: '0.', formatted: '0.', caret: 2 });
});
