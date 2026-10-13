function isWordChar(char) {
    return char !== undefined && /[\p{L}\p{N}]/u.test(char);
}

function matchCase(original, label) {
    const upper = original.toLocaleUpperCase();
    const lower = original.toLocaleLowerCase();
    if (original === upper && original !== lower) {
        return label.toLocaleUpperCase();
    }
    if (original === lower) {
        return label.toLocaleLowerCase();
    }

    return label;
}

export function applyTerminology(text, terminology) {
    const phrases = terminology?.phrases;
    if (!phrases?.length || typeof text !== 'string' || text === '') {
        return text;
    }
    const lower = text.toLocaleLowerCase();
    let result = '';
    let index = 0;
    while (index < text.length) {
        let matched = false;
        for (const phrase of phrases) {
            const from = phrase.from;
            if (!from || !lower.startsWith(from, index)) {
                continue;
            }
            const end = index + from.length;
            if (isWordChar(lower[index - 1]) || isWordChar(lower[end])) {
                continue;
            }
            result += matchCase(text.slice(index, end), phrase.to);
            index = end;
            matched = true;
            break;
        }
        if (!matched) {
            result += text[index];
            index += 1;
        }
    }

    return result;
}
