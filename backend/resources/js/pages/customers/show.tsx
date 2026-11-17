import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRightLeft,
    Camera,
    ChevronDown,
    Combine,
    CreditCard,
    FileText,
    History,
    Info,
    MapPin,
    MapPinned,
    Pencil,
    ShieldCheck,
    Smartphone,
    Store,
    UsersRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { ContactsTab } from '@/components/customer/contacts-tab';
import {
    CustomerNotices,
    MergeDialog,
    STATUS_ACTION_COPY,
    StatusDialog,
} from '@/components/customer/customer-actions';
import {
    CUSTOMER_STATUS_TONE,
    CustomerPhone,
    SignedPhoto,
} from '@/components/customer/customer-ui';
import type {
    ChangeState,
    CustomerStatus,
    StatusActionValue,
} from '@/components/customer/customer-ui';
import { LimitTab } from '@/components/customer/limit-tab';
import {
    PhoneChangeCard,
    PhoneChangeDialog,
} from '@/components/customer/phone-change';
import { ReassignDialog } from '@/components/customer/reassign-dialog';
import { VerificationTab } from '@/components/customer/verification-tab';
import type { VerificationState } from '@/components/customer/verification-tab';
import {
    DetailCard,
    EmptyPanel,
    Field,
    FieldGrid,
    Timeline,
    TimelineItem,
} from '@/components/employee/detail-ui';
import { LocationDiagram } from '@/components/pakis/location-diagram';
import { PageTabs } from '@/components/pakis/page-tabs';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatDate } from '@/lib/listing-query';
import { googleMapsUrl } from '@/lib/maps-link';
import { edit, index, show } from '@/routes/admin/customers';

type Ref = { id: number; code: string; name: string };

type Customer = {
    id: number;
    code: string;
    store_name: string;
    owner_name: string;
    owner_phone: string;
    customer_type: string | null;
    address: string | null;
    latitude: string | null;
    longitude: string | null;
    term_days: number;
    status: CustomerStatus;
    status_label: string;
    is_verified: boolean;
    sales_area: Ref | null;
    assigned_sales: {
        id: number;
        name: string;
        employee_number: string;
    } | null;
    home_stock_location: Ref | null;
    region: {
        province: string | null;
        regency: string | null;
        district: string | null;
        village: string | null;
    };
    credit_limit: string;
    credit_limit_active: boolean;
    verified_at: string | null;
    created_at: string | null;
    /** URL bertanda tangan 5 menit (SEC-108); null = belum ada foto toko. */
    photo_url: string | null;
    hold_reason: string | null;
    harvest_term_allowed: boolean;
    harvest_term_max_days: number | null;
    restricted_buyer_eligible: boolean;
    restricted_buyer_note: string | null;
    restricted_buyer_valid_until: string | null;
    restricted_buyer_document_url: string | null;
};

type Assignment = {
    id: number;
    sales_area: Ref | null;
    sales_employee: { id: number; name: string } | null;
    start_date: string;
    end_date: string | null;
    reason: string | null;
    state: 'current' | 'pending' | 'ended';
};

type Props = {
    customer: Customer;
    assignments: Assignment[];
    phoneMasked: boolean;
    verification: VerificationState;
    changes: ChangeState;
    can: {
        update: boolean;
        reassign: boolean;
        change_phone: boolean;
        credit_limit: boolean;
        merge: boolean;
        change_status: boolean;
        manage_contacts: boolean;
    };
    today: string;
    maxReassign: number;
};

/**
 * Tab detail kios (`?tab=`, 15 §7.1; T-074). Riwayat perubahan kios belum punya prop sendiri: riwayat
 * yang ada adalah penugasan (tab Penugasan) dan verifikasi/ganti HP (tab Verifikasi).
 */
const TABS: { key: string; label: string; icon: LucideIcon }[] = [
    { key: 'ringkasan', label: 'Ringkasan', icon: Info },
    { key: 'verifikasi', label: 'Verifikasi', icon: ShieldCheck },
    { key: 'limit', label: 'Limit', icon: CreditCard },
    { key: 'kontak', label: 'Kontak', icon: UsersRound },
    { key: 'penugasan', label: 'Penugasan', icon: History },
];

const ASSIGNMENT_STATE = {
    current: { label: 'Berlaku', tone: 'success' },
    pending: { label: 'Terjadwal', tone: 'info' },
    ended: { label: 'Selesai', tone: 'muted' },
} as const;

const rupiah = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

/** Detail kios bertab (15 §7.1/§7.3, FR-A13 data inti, FR-A17 riwayat penugasan). */
export default function CustomerShow({
    customer,
    assignments,
    phoneMasked,
    verification,
    changes,
    can,
    today,
    maxReassign,
}: Props) {
    const page = usePage();
    const timezone = page.props.timezone;
    const requested = new URLSearchParams(page.url.split('?')[1] ?? '').get(
        'tab',
    );
    const active = TABS.some((t) => t.key === requested)
        ? (requested as string)
        : 'ringkasan';
    const [reassignOpen, setReassignOpen] = useState(false);
    const [phoneOpen, setPhoneOpen] = useState(false);
    const [mergeOpen, setMergeOpen] = useState(false);
    const [statusAction, setStatusAction] = useState<{
        value: StatusActionValue;
        label: string;
    } | null>(null);
    const statusActions = changes.status.actions;
    const hasMoreActions =
        changes.phone_change.can_request ||
        changes.merge.can_request ||
        statusActions.length > 0;
    useBreadcrumbTail([{ title: customer.store_name, href: '#' }]);

    return (
        <>
            <Head title={customer.store_name} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <Link
                    href={index()}
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Daftar pelanggan
                </Link>

                <header className="flex flex-wrap items-start gap-4">
                    <span className="grid size-14 shrink-0 place-items-center rounded-xl border bg-muted text-muted-foreground">
                        <Store className="size-6" aria-hidden="true" />
                    </span>
                    {/* basis-48: di HP tombol aksi turun ke baris baru, nama toko tidak terjepit. */}
                    <div className="min-w-0 flex-1 basis-48 space-y-1.5">
                        <h1 className="text-[1.4rem] leading-tight font-bold break-words">
                            {customer.store_name}
                        </h1>
                        <p className="font-mono text-sm text-muted-foreground">
                            {customer.code}
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            <StatusBadge
                                status={`customer.${customer.status}`}
                                label={customer.status_label}
                                tone={CUSTOMER_STATUS_TONE[customer.status]}
                            />
                            <StatusBadge
                                status={
                                    customer.is_verified
                                        ? 'customer.verified'
                                        : 'customer.unverified'
                                }
                                label={
                                    customer.is_verified
                                        ? 'Terverifikasi'
                                        : 'Belum verifikasi'
                                }
                                tone={
                                    customer.is_verified ? 'success' : 'warning'
                                }
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {hasMoreActions && (
                            // modal={false}: menu tertutup tanpa mengunci halaman sebelum dialog terbuka.
                            <DropdownMenu modal={false}>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline">
                                        Aksi lain
                                        <ChevronDown aria-hidden="true" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {changes.phone_change.can_request && (
                                        <DropdownMenuItem
                                            onSelect={() => setPhoneOpen(true)}
                                        >
                                            <Smartphone aria-hidden="true" />
                                            Ganti HP
                                        </DropdownMenuItem>
                                    )}
                                    {changes.merge.can_request && (
                                        <DropdownMenuItem
                                            onSelect={() => setMergeOpen(true)}
                                        >
                                            <Combine aria-hidden="true" />
                                            Gabung ke kios lain
                                        </DropdownMenuItem>
                                    )}
                                    {statusActions.length > 0 &&
                                        (changes.phone_change.can_request ||
                                            changes.merge.can_request) && (
                                            <DropdownMenuSeparator />
                                        )}
                                    {statusActions.map((a) => {
                                        const copy =
                                            STATUS_ACTION_COPY[a.value];
                                        const Icon = copy.icon;

                                        return (
                                            <DropdownMenuItem
                                                key={a.value}
                                                variant={
                                                    copy.danger
                                                        ? 'destructive'
                                                        : 'default'
                                                }
                                                onSelect={() =>
                                                    setStatusAction(a)
                                                }
                                            >
                                                <Icon aria-hidden="true" />
                                                {a.label}
                                            </DropdownMenuItem>
                                        );
                                    })}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                        {can.reassign && (
                            <Button
                                variant="outline"
                                onClick={() => setReassignOpen(true)}
                            >
                                <ArrowRightLeft aria-hidden="true" />
                                Alihkan
                            </Button>
                        )}
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link href={edit(customer.id)}>
                                    <Pencil aria-hidden="true" />
                                    Ubah Kios
                                </Link>
                            </Button>
                        )}
                    </div>
                </header>

                <CustomerNotices
                    status={customer.status}
                    merge={changes.merge}
                    holdReason={changes.status.hold_reason}
                    timezone={timezone}
                />

                <PageTabs
                    label="Bagian kios"
                    active={active}
                    tabs={TABS.map((t) => ({
                        key: t.key,
                        label: t.label,
                        icon: t.icon,
                        href:
                            t.key === 'ringkasan'
                                ? show(customer.id).url
                                : show(customer.id, { query: { tab: t.key } })
                                      .url,
                    }))}
                />

                {active === 'ringkasan' && (
                    <SummaryTab customer={customer} phoneMasked={phoneMasked} />
                )}
                {active === 'verifikasi' && (
                    <VerificationTab
                        customerId={customer.id}
                        status={customer.status}
                        isVerified={customer.is_verified}
                        verifiedAt={customer.verified_at}
                        state={verification}
                        phoneCard={
                            <PhoneChangeCard
                                customerId={customer.id}
                                ownerPhone={customer.owner_phone}
                                phoneMasked={phoneMasked}
                                state={changes.phone_change}
                                allowed={can.change_phone}
                                timezone={timezone}
                            />
                        }
                    />
                )}
                {active === 'limit' && (
                    <LimitTab
                        customerId={customer.id}
                        current={customer.credit_limit}
                        active={customer.credit_limit_active}
                        termDays={customer.term_days}
                        state={changes.credit_limit}
                        timezone={timezone}
                    />
                )}
                {active === 'kontak' && (
                    <ContactsTab
                        customerId={customer.id}
                        state={changes.contacts}
                        phoneMasked={phoneMasked}
                    />
                )}
                {active === 'penugasan' && (
                    <AssignmentsTab rows={assignments} />
                )}
            </div>

            {can.reassign && reassignOpen && (
                <ReassignDialog
                    open
                    onOpenChange={setReassignOpen}
                    customerIds={[customer.id]}
                    targetLabel={`${customer.store_name} · sekarang di ${customer.sales_area?.name ?? 'tanpa area'}${customer.assigned_sales ? `, sales ${customer.assigned_sales.name}` : ''}.`}
                    today={today}
                    maxReassign={maxReassign}
                />
            )}
            {changes.phone_change.can_request && phoneOpen && (
                <PhoneChangeDialog
                    customerId={customer.id}
                    currentPhone={phoneMasked ? null : customer.owner_phone}
                    state={changes.phone_change}
                    onOpenChange={setPhoneOpen}
                />
            )}
            {changes.merge.can_request && mergeOpen && (
                <MergeDialog
                    customerId={customer.id}
                    storeName={customer.store_name}
                    onOpenChange={setMergeOpen}
                />
            )}
            {statusAction && (
                <StatusDialog
                    customerId={customer.id}
                    storeName={customer.store_name}
                    action={statusAction}
                    onOpenChange={(open) => !open && setStatusAction(null)}
                />
            )}
        </>
    );
}

function SummaryTab({
    customer,
    phoneMasked,
}: {
    customer: Customer;
    phoneMasked: boolean;
}) {
    const timezone = usePage().props.timezone;
    const region = [
        customer.region.village,
        customer.region.district,
        customer.region.regency,
        customer.region.province,
    ]
        .filter(Boolean)
        .join(', ');
    const lat = customer.latitude !== null ? Number(customer.latitude) : NaN;
    const lng = customer.longitude !== null ? Number(customer.longitude) : NaN;
    const hasPoint = Number.isFinite(lat) && Number.isFinite(lng);

    return (
        <div className="grid gap-5 lg:grid-cols-2">
            <div className="space-y-5">
                <DetailCard title="Identitas kios" icon={Store}>
                    <FieldGrid>
                        <Field label="Kode" value={customer.code} mono />
                        <Field label="Jenis" value={customer.customer_type} />
                        <Field label="Pemilik" value={customer.owner_name} />
                        <Field
                            label="HP pemilik"
                            value={
                                <CustomerPhone
                                    phone={customer.owner_phone}
                                    masked={phoneMasked}
                                />
                            }
                        />
                        <Field
                            label="Dibuat"
                            value={
                                customer.created_at
                                    ? formatDateTimeIn(
                                          customer.created_at,
                                          timezone,
                                      )
                                    : null
                            }
                        />
                        <Field
                            label="Terverifikasi"
                            value={
                                customer.verified_at
                                    ? formatDateTimeIn(
                                          customer.verified_at,
                                          timezone,
                                      )
                                    : 'Belum'
                            }
                        />
                    </FieldGrid>
                </DetailCard>
                <DetailCard title="Area & sales" icon={MapPinned}>
                    <FieldGrid>
                        <Field
                            label="Area penjualan"
                            value={customer.sales_area?.name}
                            hint={customer.sales_area?.code}
                        />
                        <Field
                            label="Sales"
                            value={customer.assigned_sales?.name}
                            hint={customer.assigned_sales?.employee_number}
                        />
                        <Field
                            label="Gudang asal"
                            value={
                                customer.home_stock_location?.name ??
                                'Ikut gudang asal area'
                            }
                            hint={customer.home_stock_location?.code}
                        />
                    </FieldGrid>
                </DetailCard>
                <DetailCard title="Kredit" icon={CreditCard}>
                    <FieldGrid>
                        <Field
                            label="Tempo standar"
                            value={`${customer.term_days} hari`}
                        />
                        <Field
                            label="Limit kredit"
                            value={
                                customer.credit_limit_active
                                    ? rupiah.format(
                                          Number(customer.credit_limit),
                                      )
                                    : 'Belum aktif'
                            }
                        />
                    </FieldGrid>
                    {!customer.credit_limit_active && (
                        <p className="mt-3 text-xs text-muted-foreground">
                            Limit kredit hanya aktif setelah kios terverifikasi.
                            Sebelum itu kios hanya bisa order tunai.
                        </p>
                    )}
                </DetailCard>
                <DetailCard title="Ketentuan dagang" icon={FileText}>
                    <FieldGrid>
                        <Field
                            label="Tempo panen"
                            value={
                                customer.harvest_term_allowed
                                    ? `Boleh, maks ${customer.harvest_term_max_days ?? '–'} hari`
                                    : 'Tidak'
                            }
                        />
                        <Field
                            label="Pembeli pestisida terbatas"
                            value={
                                customer.restricted_buyer_eligible
                                    ? customer.restricted_buyer_valid_until
                                        ? `Layak sampai ${formatDate(customer.restricted_buyer_valid_until)}`
                                        : 'Layak'
                                    : 'Tidak'
                            }
                        />
                        {customer.restricted_buyer_note && (
                            <Field
                                label="Catatan pembeli terbatas"
                                value={customer.restricted_buyer_note}
                                wide
                            />
                        )}
                        {customer.restricted_buyer_document_url && (
                            <Field
                                label="Dokumen kelayakan"
                                value={
                                    <a
                                        href={
                                            customer.restricted_buyer_document_url
                                        }
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="font-medium text-primary hover:underline"
                                    >
                                        Buka dokumen
                                    </a>
                                }
                                hint="tautan berlaku 5 menit"
                                wide
                            />
                        )}
                    </FieldGrid>
                </DetailCard>
            </div>
            <div className="space-y-5">
                <DetailCard title="Alamat" icon={MapPin}>
                    <FieldGrid columns={1}>
                        <Field label="Alamat jalan" value={customer.address} />
                        <Field label="Wilayah" value={region || null} />
                    </FieldGrid>
                </DetailCard>
                <DetailCard title="Foto toko" icon={Camera}>
                    <SignedPhoto
                        url={customer.photo_url}
                        alt={`Foto toko ${customer.store_name}`}
                        emptyText="Foto toko diambil saat verifikasi."
                    />
                    {customer.photo_url && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Klik foto untuk membuka ukuran penuh. Tautan berlaku
                            5 menit.
                        </p>
                    )}
                </DetailCard>
                <DetailCard title="Titik lokasi" icon={MapPin}>
                    {hasPoint ? (
                        <LocationDiagram
                            point={{ lat, lng }}
                            area={null}
                            label="Titik kios"
                            noAreaText={`Titik kios: ${customer.latitude}, ${customer.longitude}.`}
                            mapHref={googleMapsUrl(
                                customer.latitude ?? '',
                                customer.longitude ?? '',
                            )}
                            mapLabel="Lihat di Google Maps"
                        />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Titik kios belum diisi. Isi lewat Ubah Kios atau
                            saat verifikasi.
                        </p>
                    )}
                </DetailCard>
            </div>
        </div>
    );
}

function AssignmentsTab({ rows }: { rows: Assignment[] }) {
    if (rows.length === 0) {
        return (
            <EmptyPanel
                icon={History}
                title="Belum ada penugasan"
                text="Riwayat area dan sales kios akan tercatat di sini."
            />
        );
    }

    return (
        <DetailCard title="Riwayat area & sales" icon={History}>
            <Timeline>
                {rows.map((r, i) => (
                    <TimelineItem
                        key={r.id}
                        active={r.state === 'current'}
                        last={i === rows.length - 1}
                    >
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="font-medium">
                                    {r.sales_area?.name ?? 'Tanpa area'}
                                </p>
                                <StatusBadge
                                    status={`assignment.${r.state}`}
                                    label={ASSIGNMENT_STATE[r.state].label}
                                    tone={ASSIGNMENT_STATE[r.state].tone}
                                />
                            </div>
                            <p className="text-sm">
                                Sales: {r.sales_employee?.name ?? 'belum ada'}
                            </p>
                            <p className="text-xs text-muted-foreground tabular-nums">
                                {formatDate(r.start_date)} –{' '}
                                {r.end_date
                                    ? formatDate(r.end_date)
                                    : r.state === 'pending'
                                      ? 'seterusnya'
                                      : 'sekarang'}
                            </p>
                            {r.reason && (
                                <p className="text-sm">Alasan: {r.reason}</p>
                            )}
                        </div>
                    </TimelineItem>
                ))}
            </Timeline>
        </DetailCard>
    );
}

CustomerShow.layout = {
    breadcrumbs: [{ title: 'Pelanggan', href: index() }],
};
