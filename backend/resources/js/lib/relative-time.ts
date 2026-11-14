const relative = new Intl.RelativeTimeFormat('id-ID', { numeric: 'auto' });

const dateTime = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

/**
 * "baru saja", "5 menit yang lalu", "2 jam yang lalu"; lebih dari seminggu → `3 Nov 2026 07.41`
 * (07 §7). `now` bisa diisi untuk test.
 */
export function formatRelativeTime(
    iso: string,
    now: Date = new Date(),
): string {
    const at = new Date(iso);
    if (Number.isNaN(at.getTime())) {
        return iso;
    }

    const seconds = Math.round((at.getTime() - now.getTime()) / 1000);
    const abs = Math.abs(seconds);

    if (abs < 60) {
        return 'baru saja';
    }
    if (abs < 3600) {
        return relative.format(Math.round(seconds / 60), 'minute');
    }
    if (abs < 86400) {
        return relative.format(Math.round(seconds / 3600), 'hour');
    }
    if (abs < 7 * 86400) {
        return relative.format(Math.round(seconds / 86400), 'day');
    }

    return formatDateTime(iso);
}

/** `3 Nov 2026 07.41` dalam zona waktu browser. */
export function formatDateTime(iso: string): string {
    const at = new Date(iso);

    return Number.isNaN(at.getTime())
        ? iso
        : dateTime.format(at).replace(',', '').replace(':', '.');
}
