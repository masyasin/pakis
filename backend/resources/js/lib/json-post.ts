import { xsrfToken } from '@/lib/approval-format';

/** Hasil POST JSON ke endpoint admin (cek duplikat, OTP kios): data atau galat siap tampil. */
export type JsonPostResult<T> =
    | { ok: true; data: T }
    | {
          ok: false;
          status: number;
          /** Kode galat 05 §2.3 bila server mengirim format 05 §2.2 (mis. `OTP_INVALID`). */
          code: string | null;
          message: string;
          /** Galat per field (pesan pertama tiap field). */
          fields: Record<string, string>;
      };

type ErrorBody = {
    error?: {
        code?: string;
        message?: string;
        details?: Record<string, unknown>;
    };
    errors?: Record<string, string[]>;
    message?: string;
};

/**
 * Pesan ramah per status bila server tidak mengirim format 05 §2.2. Contoh: batas laju middleware
 * `throttle` di rute web menjawab 429 dengan pesan bawaan Laravel berbahasa Inggris.
 */
const STATUS_MESSAGES: Record<number, string> = {
    419: 'Sesi berakhir. Muat ulang halaman lalu coba lagi.',
    429: 'Terlalu sering dicoba. Tunggu sebentar lalu coba lagi.',
    503: 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.',
};

function firstMessages(
    details: Record<string, unknown>,
): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [key, value] of Object.entries(details)) {
        if (typeof value === 'string' && value !== '') {
            out[key] = value;
        } else if (Array.isArray(value) && typeof value[0] === 'string') {
            out[key] = value[0];
        }
    }

    return out;
}

/**
 * POST JSON di luar Inertia (pola `approval-chain-preview`): cookie XSRF Laravel + `Accept:
 * application/json` agar galat datang sebagai JSON, bukan redirect. Tidak pernah melempar.
 */
export async function postJson<T>(
    url: string,
    body: unknown,
    signal?: AbortSignal,
): Promise<JsonPostResult<T>> {
    let response: Response;
    try {
        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            signal,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(body),
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }

        return {
            ok: false,
            status: 0,
            code: null,
            message:
                'Tidak bisa terhubung ke server. Periksa koneksi lalu coba lagi.',
            fields: {},
        };
    }

    const parsed = (await response.json().catch(() => ({}))) as
        | { data?: T }
        | ErrorBody;
    if (response.ok && 'data' in parsed && parsed.data !== undefined) {
        return { ok: true, data: parsed.data };
    }

    const err = parsed as ErrorBody;
    const fields = firstMessages(err.error?.details ?? err.errors ?? {});

    return {
        ok: false,
        status: response.status,
        code: err.error?.code ?? null,
        message:
            err.error?.message ??
            STATUS_MESSAGES[response.status] ??
            'Permintaan gagal. Coba lagi.',
        fields,
    };
}
