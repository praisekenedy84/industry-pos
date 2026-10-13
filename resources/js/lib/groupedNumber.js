export function plainNumber(value, { allowDecimal = true, allowNegative = true, maxDecimals = 2 } = {}) {
    let text = String(value ?? '').replace(/,/g, '').replace(/\s/g, '');
    const negative = allowNegative && text.startsWith('-');
    text = text.replace(/-/g, '');
    if (!allowDecimal) {
        text = text.split('.')[0];
    }
    text = text.replace(/[^\d.]/g, '');
    const firstDot = text.indexOf('.');
    if (firstDot !== -1) {
        text = `${text.slice(0, firstDot + 1)}${text.slice(firstDot + 1).replace(/\./g, '')}`;
    }
    const dot = text.indexOf('.');
    let whole = dot === -1 ? text : text.slice(0, dot);
    let fraction = dot === -1 ? '' : text.slice(dot);
    if (fraction.startsWith('.') && Number.isInteger(maxDecimals) && maxDecimals >= 0) {
        fraction = maxDecimals === 0 ? '' : `.${fraction.slice(1, 1 + maxDecimals)}`;
    }
    whole = whole.replace(/^0+(?=\d)/, '');
    if (whole === '' && fraction !== '') {
        whole = '0';
    }
    if (text === '') {
        return negative ? '-' : '';
    }

    return `${negative ? '-' : ''}${whole}${fraction}`;
}

export function groupDigits(value) {
    const text = String(value ?? '');
    if (text === '' || text === '-') {
        return text;
    }
    const negative = text.startsWith('-');
    const body = negative ? text.slice(1) : text;
    const dot = body.indexOf('.');
    const whole = dot === -1 ? body : body.slice(0, dot);
    const fraction = dot === -1 ? null : body.slice(dot + 1);
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const signed = `${negative ? '-' : ''}${grouped}`;

    return fraction === null ? signed : `${signed}.${fraction}`;
}

function significantPrefixLength(text) {
    return String(text).replace(/[^\d.-]/g, '').length;
}

function indexAfterSignificant(formatted, count) {
    if (count <= 0) {
        return 0;
    }
    let seen = 0;
    for (let index = 0; index < formatted.length; index += 1) {
        if (/[\d.-]/.test(formatted[index])) {
            seen += 1;
            if (seen === count) {
                return index + 1;
            }
        }
    }

    return formatted.length;
}

export function editGroupedNumber(raw, caret, options = {}) {
    const plain = plainNumber(raw, options);
    const formatted = groupDigits(plain);
    const source = String(raw ?? '');
    let count = significantPrefixLength(source.slice(0, caret ?? source.length));
    const compact = source.replace(/,/g, '').replace(/\s/g, '');
    if ((plain.startsWith('0.') || plain.startsWith('-0.')) && !/^-?0/.test(compact)) {
        count += 1;
    }

    return { plain, formatted, caret: indexAfterSignificant(formatted, count) };
}
