import { Link } from '@inertiajs/react';
import { Palette, ShieldCheck, UserRound } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profil',
        href: edit(),
        icon: UserRound,
    },
    {
        title: 'Keamanan',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'Tampilan',
        href: editAppearance(),
        icon: Palette,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="px-4 py-6 md:px-6 md:py-8">
            <Heading
                title="Pengaturan akun"
                description="Kelola profil, keamanan, dan tampilan akun Anda."
            />

            <div className="flex flex-col gap-6 lg:flex-row lg:gap-10">
                <aside className="-mx-4 lg:mx-0 lg:w-52 lg:shrink-0">
                    {/* HP: baris tab gulir horizontal; desktop: daftar vertikal. */}
                    <nav
                        className="flex gap-1 overflow-x-auto px-4 pb-1 lg:sticky lg:top-24 lg:flex-col lg:overflow-visible lg:px-0 lg:pb-0"
                        aria-label="Pengaturan akun"
                    >
                        {sidebarNavItems.map((item, index) => {
                            const active = isCurrentOrParentUrl(item.href);

                            return (
                                <Link
                                    key={`${toUrl(item.href)}-${index}`}
                                    href={item.href}
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        'flex h-10 shrink-0 items-center gap-2.5 rounded-lg px-3 text-sm font-medium whitespace-nowrap transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        active
                                            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                                            : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                                    )}
                                >
                                    {item.icon && (
                                        <item.icon
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {item.title}
                                </Link>
                            );
                        })}
                    </nav>
                </aside>

                <div className="min-w-0 flex-1 lg:max-w-2xl">
                    <section className="space-y-6">{children}</section>
                </div>
            </div>
        </div>
    );
}
