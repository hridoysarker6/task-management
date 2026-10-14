// Time estimates are typed as "1h 30m", "90m", "1.5h" or "2d" (a day is 8 hours) and stored as minutes.

/** "1h 30m" -> 90. Returns null for empty text and NaN for text that cannot be understood. */
export function parseDuration(text) {
    const s = String(text ?? '').trim().toLowerCase();
    if (s === '') return null;
    if (/^\d+$/.test(s)) return parseInt(s, 10); // a bare number means minutes

    const unit = { d: 480, h: 60, m: 1 };
    let total = 0;
    let used = '';
    for (const match of s.matchAll(/(\d+(?:\.\d+)?)\s*([dhm])/g)) {
        total += parseFloat(match[1]) * unit[match[2]];
        used += match[0];
    }
    if (!used || used.replace(/\s+/g, '') !== s.replace(/\s+/g, '')) return NaN;
    return Math.round(total);
}

/** 90 -> "1h 30m". */
export function formatDuration(minutes) {
    if (minutes === null || minutes === undefined) return '';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return [h ? `${h}h` : '', m || !h ? `${m}m` : ''].filter(Boolean).join(' ');
}
