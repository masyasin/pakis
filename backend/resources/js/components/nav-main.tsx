import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Building2,
    CalendarClock,
    CalendarDays,
    CalendarOff,
    ChevronRight,
    ClipboardCheck,
    ClipboardList,
    Clock3,
    FileUp,
    Hash,
    History,
    KeyRound,
    LayoutDashboard,
    ListTree,
    Luggage,
    Map,
    MapPin,
    MapPinned,
    Network,
    Settings,
    ShieldAlert,
    ShoppingCart,
    Smartphone,
    UserRound,
    UsersRound,
    BadgeCheck,
    BadgeDollarSign,
    BadgePercent,
    BriefcaseBusiness,
    CircleDot,
    Factory,
    FileBadge,
    Handshake,
    ShoppingBag,
    Package,
    Stamp,
    Store,
    Tags,
    Truck,
    Warehouse,
    Workflow,
} from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
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
    trip: Luggage,
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
    approval: Stamp,
    approval_rule: Workflow,
    category: Tags,
    product: Package,
    registration: FileBadge,
    purchase_order: ClipboardList,
    supplier: Handshake,
    principal: Factory,
    warehouse: Warehouse,
    vehicle: Truck,
    customer: Store,
    customer_verification: BadgeCheck,
    sales_area: MapPinned,
    price_list: BadgeDollarSign,
    promotion: BadgePercent,
};

/**
 * Angka di ujung kanan item (mis. persetujuan menunggu, 15 §6.5). Nol tidak ditampilkan agar menu
 * tidak ramai; saat sidebar diciutkan ikut terpotong bersama judul.
 */
function MenuCount({ count }: { count?: number }) {
    if (!count) {
        return null;
    }

    return (
        <span
            className="ml-auto inline-flex min-w-5 items-center justify-center rounded-full bg-status-warning-bg px-1.5 text-[11px] leading-5 font-bold text-status-warning tabular-nums"
            aria-label={`${count} menunggu`}
        >
            {count > 99 ? '99+' : count}
        </span>
    );
}

/** Ikon grup menu (judul grup 06 §8). Grup lain memakai ikon item pertamanya. */
const GROUP_ICONS: Record<string, LucideIcon> = {
    // Ikon grup sendiri agar tidak sama dengan item pertamanya (Pelanggan = Store).
    Penjualan: ShoppingCart,
    Pembelian: ShoppingBag,
    'Unit Kerja': Building2,
    Kepegawaian: UsersRound,
    Presensi: ClipboardCheck,
    'Pengguna & Akses': KeyRound,
    Pengaturan: Settings,
    Audit: History,
};

/**
 * Grup berurutan dengan seksi yang sama dikumpulkan jadi satu blok (06 §8): satu judul seksi
 * kecil (HRIS, Sistem) lalu grup-grupnya. Urutan dari server dipertahankan.
 */
export function bySection(
    groups: MenuGroup[],
): { section: string | null; groups: MenuGroup[] }[] {
    const blocks: { section: string | null; groups: MenuGroup[] }[] = [];
    for (const group of groups) {
        const last = blocks[blocks.length - 1];
        if (last && last.section === group.section) {
            last.groups.push(group);
        } else {
            blocks.push({ section: group.section, groups: [group] });
        }
    }

    return blocks;
}

/** Status buka/tutup grup diingat per browser (hanya kenyamanan; gagal = pakai bawaan). */
const STORAGE_KEY = 'sidebar.groups';

function readStoredGroups(): Record<string, boolean> {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const parsed: unknown = raw ? JSON.parse(raw) : {};

        return parsed !== null && typeof parsed === 'object'
            ? (parsed as Record<string, boolean>)
            : {};
    } catch {
        return {};
    }
}

function storeGroup(title: string, open: boolean): void {
    try {
        window.localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ ...readStoredGroups(), [title]: open }),
        );
    } catch {
        // Penyimpanan diblokir (mode privat): status hanya bertahan selama halaman terbuka.
    }
}

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

/** Gaya item ala tab prototipe: sudut 10 px, aktif = tint hijau + teks tebal + garis tipis. */
const ITEM_CLASS =
    'h-9 gap-2.5 rounded-[10px] px-3 text-muted-foreground transition-colors duration-150 hover:bg-muted hover:text-sidebar-foreground data-[active=true]:font-bold data-[active=true]:ring-1 data-[active=true]:ring-sidebar-primary/30 [&>svg]:text-muted-foreground data-[active=true]:[&>svg]:text-sidebar-accent-foreground';

/**
 * Menu sidebar dari `auth.menu` (sudah difilter server menurut permission & modul yang ada,
 * 06 §8/§10). Grup dikelompokkan per seksi berjudul (HRIS, Sistem). Grup bisa dibuka/tutup (grup berisi halaman aktif selalu terbuka saat dimuat);
 * saat sidebar diciutkan, tiap grup menjadi ikon yang membuka menu melayang berisi itemnya
 * sehingga semua menu tetap bisa diklik. Item aktif juga untuk halaman turunan.
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
            {bySection(groups).map((block, blockIndex) => (
                <SidebarGroup
                    key={block.section ?? `tanpa-seksi-${blockIndex}`}
                    className="px-2 py-0 pt-1"
                >
                    {block.section && (
                        <SidebarGroupLabel className="h-7 px-3 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            {block.section}
                        </SidebarGroupLabel>
                    )}
                    <SidebarMenu className="gap-0.5">
                        {block.groups.map((group, index) =>
                            group.title ? (
                                <NavGroup
                                    key={group.title}
                                    group={
                                        group as MenuGroup & { title: string }
                                    }
                                    activeHref={activeHref}
                                />
                            ) : (
                                group.items.map((item) => {
                                    const Icon = ICONS[item.icon] ?? CircleDot;

                                    return (
                                        <SidebarMenuItem
                                            key={item.href ?? index}
                                        >
                                            <SidebarMenuButton
                                                asChild
                                                isActive={
                                                    item.href === activeHref
                                                }
                                                tooltip={{
                                                    children: item.title,
                                                }}
                                                className={ITEM_CLASS}
                                            >
                                                <Link href={item.href} prefetch>
                                                    <Icon />
                                                    <span>{item.title}</span>
                                                    <MenuCount
                                                        count={item.badge}
                                                    />
                                                </Link>
                                            </SidebarMenuButton>
                                        </SidebarMenuItem>
                                    );
                                })
                            ),
                        )}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}

function NavGroup({
    group,
    activeHref,
}: {
    group: MenuGroup & { title: string };
    activeHref: string | null;
}) {
    const { state, isMobile } = useSidebar();
    const containsActive = group.items.some((i) => i.href === activeHref);
    const GroupIcon =
        GROUP_ICONS[group.title] ??
        ICONS[group.items[0]?.icon ?? ''] ??
        CircleDot;

    // Grup berisi halaman aktif selalu terbuka; selain itu ikuti pilihan terakhir (bawaan tertutup
    // agar sidebar ringkas).
    const [open, setOpen] = useState<boolean>(
        () => containsActive || (readStoredGroups()[group.title] ?? false),
    );
    const toggle = (value: boolean) => {
        setOpen(value);
        storeGroup(group.title, value);
    };

    // Sidebar diciutkan (desktop): ikon grup → menu melayang ke kanan.
    if (state === 'collapsed' && !isMobile) {
        return (
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            isActive={containsActive}
                            tooltip={{ children: group.title }}
                            className={ITEM_CLASS}
                            aria-label={`Menu ${group.title}`}
                        >
                            <GroupIcon />
                            <span>{group.title}</span>
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        side="right"
                        align="start"
                        sideOffset={8}
                        className="min-w-52 rounded-xl"
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            {group.title}
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {group.items.map((item) => {
                            const Icon = ICONS[item.icon] ?? CircleDot;
                            const active = item.href === activeHref;

                            return (
                                <DropdownMenuItem
                                    key={item.href}
                                    asChild
                                    className={cn(
                                        'gap-2.5 rounded-lg py-2',
                                        active &&
                                            'bg-sidebar-accent font-bold text-sidebar-accent-foreground',
                                    )}
                                >
                                    <Link href={item.href} prefetch>
                                        <Icon className="size-4" />
                                        {item.title}
                                        <MenuCount count={item.badge} />
                                    </Link>
                                </DropdownMenuItem>
                            );
                        })}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        );
    }

    return (
        <Collapsible
            open={open}
            onOpenChange={toggle}
            asChild
            className="group/collapsible"
        >
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton
                        tooltip={{ children: group.title }}
                        className={cn(
                            ITEM_CLASS,
                            'font-semibold text-sidebar-foreground/80',
                            containsActive &&
                                !open &&
                                'text-sidebar-foreground',
                        )}
                    >
                        <GroupIcon />
                        <span>{group.title}</span>
                        <ChevronRight
                            aria-hidden="true"
                            className="ml-auto size-4 transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                        />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub className="mr-0 gap-0.5 pr-0">
                        {group.items.map((item) => {
                            const Icon = ICONS[item.icon] ?? CircleDot;

                            return (
                                <SidebarMenuSubItem key={item.href}>
                                    <SidebarMenuSubButton
                                        asChild
                                        isActive={item.href === activeHref}
                                        className={cn(ITEM_CLASS, 'h-8')}
                                    >
                                        <Link href={item.href} prefetch>
                                            <Icon />
                                            <span>{item.title}</span>
                                            <MenuCount count={item.badge} />
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            );
                        })}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}
