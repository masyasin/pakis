import type { LucideIcon } from 'lucide-react';
import type { ComponentProps, FormEventHandler, ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { DialogSize } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

type ContentProps = Omit<
    ComponentProps<typeof DialogContent>,
    'title' | 'children' | 'onSubmit' | 'size'
>;

export type FormDialogProps = ContentProps & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    description?: ReactNode;
    icon: LucideIcon;
    /**
     * `danger` untuk aksi yang mencabut akses / menolak (nonaktifkan pegawai, tolak koreksi):
     * kotak ikon merah muda agar beda dengan aksi biasa sekilas pandang.
     */
    tone?: 'default' | 'danger';
    /** `sm` = konfirmasi & 1–3 isian, `lg` = isian dua kolom / konten lebar. */
    size?: DialogSize;
    /** Tombol pembuka opsional (Radix `DialogTrigger asChild`). */
    trigger?: ReactNode;
    /**
     * Tombol di kaki. Bila diisi, `children` dibungkus `FormDialogBody` dan tombol ini dibungkus
     * `FormDialogFooter`. Bila kosong, `children` disusun sendiri oleh pemanggil (mis. form yang
     * baru di-mount saat dialog dibuka memakai `FormDialogForm` + `FormDialogBody` + `FormDialogFooter`).
     */
    footer?: ReactNode;
    /** Bila diisi, badan + kaki dibungkus `<form>` sehingga Enter & tombol `type="submit"` bekerja. */
    onSubmit?: FormEventHandler<HTMLFormElement>;
    noValidate?: boolean;
    bodyClassName?: string;
    children: ReactNode;
};

/**
 * Kerangka popup baku PAKIS ONE (keputusan Mas 16 Nov 2026): kartu putih `rounded-xl`, kepala berikon
 * seperti `DetailCard`, badan yang bisa digulir, dan kaki bergaris atas yang selalu terlihat. Di HP
 * tampil sebagai panel dari bawah (bottom sheet, dari `DialogContent`).
 */
export function FormDialog({
    open,
    onOpenChange,
    title,
    description,
    icon: Icon,
    tone = 'default',
    size = 'sm',
    trigger,
    footer,
    onSubmit,
    noValidate,
    bodyClassName,
    className,
    children,
    ...contentProps
}: FormDialogProps) {
    const composed = footer !== undefined;
    const sections = composed ? (
        <>
            <FormDialogBody className={bodyClassName}>
                {children}
            </FormDialogBody>
            <FormDialogFooter>{footer}</FormDialogFooter>
        </>
    ) : (
        children
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            {trigger !== undefined && (
                <DialogTrigger asChild>{trigger}</DialogTrigger>
            )}
            <DialogContent
                size={size}
                // Tanpa deskripsi, Radix meminta aria-describedby={undefined} agar tidak memberi peringatan.
                {...(description === undefined
                    ? { 'aria-describedby': undefined }
                    : {})}
                {...contentProps}
                className={cn(
                    // Gulir dipindah dari kartu ke badan supaya kepala & kaki tetap terlihat.
                    'flex flex-col gap-0 overflow-hidden p-0 sm:p-0',
                    className,
                )}
            >
                <DialogHeader className="shrink-0 flex-row items-start gap-3 border-b px-5 py-4 pr-12 sm:px-6">
                    <span
                        className={cn(
                            'grid size-7 shrink-0 place-items-center rounded-lg',
                            tone === 'danger'
                                ? 'bg-destructive/10 text-destructive'
                                : 'bg-sidebar-accent text-primary',
                        )}
                    >
                        <Icon className="size-4" aria-hidden="true" />
                    </span>
                    <div className="grid min-w-0 gap-1">
                        <DialogTitle>{title}</DialogTitle>
                        {description !== undefined && (
                            <DialogDescription>{description}</DialogDescription>
                        )}
                    </div>
                </DialogHeader>

                {onSubmit ? (
                    <FormDialogForm onSubmit={onSubmit} noValidate={noValidate}>
                        {sections}
                    </FormDialogForm>
                ) : (
                    sections
                )}
            </DialogContent>
        </Dialog>
    );
}

/** `<form>` yang meneruskan tata letak kolom popup agar badan tetap bisa digulir. */
export function FormDialogForm({
    className,
    ...props
}: ComponentProps<'form'>) {
    return (
        <form
            className={cn('flex min-h-0 flex-1 flex-col', className)}
            {...props}
        />
    );
}

/** Badan popup: jarak isian sama dengan kartu form halaman (`grid gap-4`). */
export function FormDialogBody({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            data-slot="form-dialog-body"
            className={cn(
                'grid min-h-0 flex-1 content-start gap-4 overflow-y-auto overscroll-contain px-5 py-5 sm:px-6',
                className,
            )}
            {...props}
        />
    );
}

/** Kaki popup: garis atas, "Batal" lalu tombol utama di kanan; di HP bertumpuk penuh lebar. */
export function FormDialogFooter({
    className,
    ...props
}: ComponentProps<typeof DialogFooter>) {
    return (
        <DialogFooter
            className={cn(
                // Margin negatif DialogFooter dasar hanya untuk kartu berpadding; di sini kartu p-0.
                'static mx-0 mb-0 shrink-0 px-5 sm:mx-0 sm:mb-0 sm:px-6',
                className,
            )}
            {...props}
        />
    );
}
