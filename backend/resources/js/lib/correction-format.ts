import { formatDate } from '@/lib/employee-format';

/** Baris koreksi dari `CorrectionPayload` (+ label) untuk halaman web koreksi (T-041). */
export type CorrectionRow = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    work_date: string;
    type: string;
    type_label: string;
    proposed_check_in: string | null;
    proposed_check_out: string | null;
    reason: { code: string; name: string };
    note: string;
    has_attachment: boolean;
    employee: { id: number; employee_number: string; name: string };
    approver: { name: string } | null;
    is_manual: boolean;
    submitted_at: string | null;
    decided_at: string | null;
    decided_by: { name: string } | null;
    decision_note: string | null;
    can_decide: boolean;
};

/** `2026-11-16T08:05:00+07:00` → `16-11-2026 08:05` (server sudah mengirim zona kantor). */
export function localDateTime(iso: string | null): string {
    if (!iso) {
        return '–';
    }

    return `${formatDate(iso.slice(0, 10))} ${iso.slice(11, 16)}`;
}

/** Jam usulan `masuk → pulang`; sisi yang tidak dikoreksi ditulis `–`. */
export function proposedTimes(r: {
    proposed_check_in: string | null;
    proposed_check_out: string | null;
}): string {
    return `${r.proposed_check_in ?? '–'} → ${r.proposed_check_out ?? '–'}`;
}
