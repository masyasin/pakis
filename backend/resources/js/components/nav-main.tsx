import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Building2,
    CalendarClock,
    CalendarDays,
    CalendarOff,
    ClipboardCheck,
    ClipboardList,
    Clock3,
    FileUp,
    Hash,
    History,
    KeyRound,
    LayoutDashboard,
    ListTree,
    Map,
    MapPin,
    Network,
    Settings,
    ShieldAlert,
    Smartphone,
    UserRound,
    UsersRound,
    BriefcaseBusiness,
    CircleDot,
} from 'lucide-react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { Auth, MenuGroup } from '@/types';

/** Kunci ikon dari MenuBuilder (06 §8) → ikon. Kunci tak dikenal memakai titik netral. */
const ICONS: Record<string, LucideIcon> = {
    dashboard: LayoutDashboard,
    attendance: ClipboardCheck,
    recap: CalendarDays,
    correction: ClipboardList,
    schedule: CalendarClock,
    shift: Clock3,
    holiday: CalendarOff,
    employee: UsersRound,
    assignment: BriefcaseBusiness,
    import: FileUp,
    location: MapPin,
    department: Building2,
    position: Network,
    region: Map,
    user: UserRound,
    role: KeyRound,
    device: Smartphone,
    settings: Settings,
    sequence: Hash,
    reference: ListTree,
    audit: History,
    security: ShieldAlert,
};

/**
 * Href menu yang paling cocok dengan URL sekarang: sama persis, atau awalan per SEGMEN path
 * (`/role` cocok untuk `/role/3/ubah`, tidak untuk `/roleX`). Bila beberapa cocok, yang
 * terpanjang menang — `/pengaturan/penomoran` tidak ikut menandai `/pengaturan`.
 */
export function bestMatch(hrefs: string[], path: string): string | null {
    let best: string | null = null;
    for (const href of hrefs) {
        const matches =
            path === href ||
            path.startsWith(href.endsWith('/') ? href : `${href}/`);
        if (matches && (best === null || href.length > best.length)) {
            best = href;
        }
    }

    return best;
}

/**
 * Menu sidebar dari `auth.menu` (sudah difilter server menurut permission & modul yang ada,
 * 06 §8/§10). Item aktif juga untuk halaman turunan (mis. /role/baru menandai "Role & Hak Akses").
 */
export function NavMain() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { currentUrl } = useCurrentUrl();
    const groups: MenuGroup[] = auth.menu ?? [];
    const activeHref = bestMatch(
        groups.flatMap((g) => g.items.map((i) => i.href)),
        currentUrl,
    );

    return (
        <>
            {groups.map((group, index) => (
                <SidebarGroup
                    key={group.title ?? `grup-${index}`}
                    className="px-2 py-0"
                >
                    <SidebarGroupLabel>
                        {group.title ?? 'Menu'}
                    </SidebarGroupLabel>
                    <SidebarMenu>
                        {group.items.map((item) => {
                            const Icon = ICONS[item.icon] ?? CircleDot;

                            return (
                                <SidebarMenuItem key={item.href}>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={item.href === activeHref}
                                        tooltip={{ children: item.title }}
                                        // Item aktif (prototipe): tint hijau + teks hijau tua + garis tipis hijau. Item lain abu-abu.
                                        className="h-9 text-muted-foreground transition-colors duration-150 hover:text-sidebar-foreground data-[active=true]:font-semibold data-[active=true]:ring-1 data-[active=true]:ring-sidebar-primary/30 [&>svg]:text-muted-foreground data-[active=true]:[&>svg]:text-sidebar-accent-foreground"
                                    >
                                        <Link href={item.href} prefetch>
                                            <Icon />
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
