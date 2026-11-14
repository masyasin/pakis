import { Leaf } from 'lucide-react';
import type { LucideProps } from 'lucide-react';

/**
 * Placeholder logo PAKIS ONE: daun Lucide. Logo resmi belum ada sebagai berkas
 * (hanya foto Google Maps, jangan di-hotlink). Saat berkas SVG/PNG resmi tersedia
 * (T-008 `branding.logo_*`), ganti isi komponen ini saja; semua pemakai ikut.
 */
export default function AppLogoIcon({
    strokeWidth = 2,
    ...props
}: LucideProps) {
    return <Leaf aria-hidden="true" strokeWidth={strokeWidth} {...props} />;
}
