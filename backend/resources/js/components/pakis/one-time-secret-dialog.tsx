import { Check, Copy, KeyRound, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { FormDialog } from '@/components/pakis/form-dialog';
import { Button } from '@/components/ui/button';

/**
 * Menampilkan rahasia yang hanya bisa dilihat SEKALI, mis. password sementara dari admin (BR-014,
 * SEC-006). Nilainya hanya hidup di state komponen ini: tidak disimpan di URL, sesi, atau storage.
 * Dialog hanya bisa ditutup lewat tombol (bukan Esc / klik di luar), agar admin tidak kehilangan
 * nilai karena salah klik.
 */
export function OneTimeSecretDialog({
    secret,
    title,
    description,
    doneLabel = 'Selesai',
    onDone,
}: {
    /** null = dialog tertutup. */
    secret: string | null;
    title: string;
    description: string;
    doneLabel?: string;
    onDone: () => void;
}) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        if (!secret) {
            return;
        }
        try {
            await navigator.clipboard.writeText(secret);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard ditolak peramban (izin/HTTP): nilai tetap terlihat untuk disalin manual.
        }
    };

    return (
        <FormDialog
            open={secret !== null}
            onOpenChange={(open) => !open && onDone()}
            icon={KeyRound}
            title={title}
            description={description}
            onEscapeKeyDown={(e) => e.preventDefault()}
            onPointerDownOutside={(e) => e.preventDefault()}
            onInteractOutside={(e) => e.preventDefault()}
            footer={
                <Button type="button" onClick={onDone}>
                    {doneLabel}
                </Button>
            }
        >
            <div className="flex items-center gap-2 rounded-lg border bg-muted/40 p-3">
                <code
                    data-testid="one-time-secret"
                    className="min-w-0 flex-1 font-mono text-lg tracking-wider break-all select-all"
                >
                    {secret}
                </code>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={copy}
                    aria-label="Salin ke clipboard"
                >
                    {copied ? (
                        <Check aria-hidden="true" />
                    ) : (
                        <Copy aria-hidden="true" />
                    )}
                    {copied ? 'Tersalin' : 'Salin'}
                </Button>
            </div>

            <p className="flex items-start gap-2 rounded-lg bg-status-warning-bg px-3 py-2.5 text-sm text-status-warning">
                <TriangleAlert
                    className="mt-px size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    Nilai ini tidak bisa dilihat lagi setelah dialog ditutup.
                    Sampaikan langsung ke pemilik akun; jangan kirim lewat grup
                    chat.
                </span>
            </p>
        </FormDialog>
    );
}
