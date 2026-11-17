// `npm audit --audit-level=high` dengan pengecualian tertulis (SEC-066). Gagal bila ada advisory
// high/critical yang TIDAK ada di npm-audit-allowlist.json, atau bila entri allowlist sudah lewat
// tanggal tinjau (`review_by`) — pengecualian harus ditinjau ulang, bukan dibiarkan selamanya.
// Justifikasi tiap entri wajib tercatat di docs/12-OPEN-QUESTIONS.md. Tanpa dependency tambahan.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const BLOCKING = new Set(['high', 'critical']);
const allowlist = JSON.parse(
    readFileSync(
        new URL('../npm-audit-allowlist.json', import.meta.url),
        'utf8',
    ),
);
const allowed = new Map(allowlist.map((entry) => [entry.advisory, entry]));
const today = new Date().toISOString().slice(0, 10);

let raw;
try {
    raw = execFileSync('npm', ['audit', '--json'], {
        encoding: 'utf8',
        shell: process.platform === 'win32',
    });
} catch (error) {
    // npm audit keluar dengan kode ≠ 0 bila ada temuan; JSON tetap ada di stdout.
    raw = error.stdout;
}
const report = JSON.parse(raw);

const blocking = [];
const excused = [];
for (const [name, vulnerability] of Object.entries(
    report.vulnerabilities ?? {},
)) {
    for (const via of vulnerability.via) {
        // Entri string = turunan dari paket lain; advisory aslinya diperiksa di paket itu.
        if (typeof via !== 'object' || !BLOCKING.has(via.severity)) {
            continue;
        }
        const entry = allowed.get(via.url);
        (entry ? excused : blocking).push(
            `${name}: ${via.title} (${via.severity}) ${via.url}`,
        );
    }
}

const expired = allowlist.filter((entry) => entry.review_by < today);

for (const line of new Set(excused)) {
    console.log(`DIKECUALIKAN (lihat npm-audit-allowlist.json): ${line}`);
}
for (const entry of expired) {
    console.error(
        `KEDALUWARSA: pengecualian ${entry.advisory} melewati review_by ${entry.review_by}; tinjau ulang.`,
    );
}
for (const line of new Set(blocking)) {
    console.error(`MEMBLOKIR: ${line}`);
}

if (blocking.length > 0 || expired.length > 0) {
    process.exit(1);
}
console.log(
    'npm audit: tidak ada temuan high/critical di luar pengecualian tertulis.',
);
