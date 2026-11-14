import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import { cn } from '@/lib/utils';

/**
 * Satu bagian di halaman Pengaturan akun: kartu lapang dengan judul & deskripsi.
 * `tone="danger"` untuk aksi yang tidak bisa dibatalkan.
 */
export function SettingsSection({
    title,
    description,
    tone = 'default',
    children,
}: {
    title: string;
    description?: string;
    tone?: 'default' | 'danger';
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'rounded-xl border bg-card p-5 shadow-xs sm:p-6',
                tone === 'danger' &&
                    'border-status-danger/25 bg-linear-to-b from-status-danger-bg/40 to-card',
            )}
        >
            <div className="mb-5">
                <Heading
                    variant="small"
                    title={title}
                    description={description}
                />
            </div>
            {children}
        </div>
    );
}
