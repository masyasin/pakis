import { CircleAlert, CircleCheck } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Pesan di atas form auth (galat umum atau status sukses dari server).
 * `role` membuat pembaca layar mengumumkan pesan tanpa memindah fokus.
 */
export function FormStatus({
    tone,
    message,
    className,
}: {
    tone: 'error' | 'success';
    message?: string;
    className?: string;
}) {
    if (!message) {
        return null;
    }

    const Icon = tone === 'error' ? CircleAlert : CircleCheck;

    return (
        <div
            role={tone === 'error' ? 'alert' : 'status'}
            className={cn(
                'flex items-start gap-2.5 rounded-lg px-3.5 py-3 text-sm leading-snug',
                tone === 'error'
                    ? 'bg-status-danger-bg text-status-danger'
                    : 'bg-status-success-bg text-status-success',
                className,
            )}
        >
            <Icon className="mt-px size-4 shrink-0" aria-hidden="true" />
            <span>{message}</span>
        </div>
    );
}
