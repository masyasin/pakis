import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

type Props = {
    log: {
        id: number;
        occurred_at: string;
        actor: string;
        event: string;
        object: string | null;
        ip: string | null;
        user_agent: string | null;
        request_id: string | null;
    };
    changes: Array<{
        key: string;
        old: string | null;
        new: string | null;
        changed: boolean;
    }>;
};

function Value({ value }: { value: string | null }) {
    if (value === null) {
        return <span className="text-muted-foreground">–</span>;
    }

    return (
        <pre className="font-mono text-xs break-all whitespace-pre-wrap">
            {value}
        </pre>
    );
}

/** Detail audit: nilai lama vs baru per kunci (05 §4.4 "Diff lama/baru"). */
export default function AuditShow({ log, changes }: Props) {
    const meta: Array<[string, string | null]> = [
        ['Waktu', log.occurred_at],
        ['Pelaku', log.actor],
        ['Event', log.event],
        ['Objek', log.object],
        ['IP', log.ip],
        ['Perangkat', log.user_agent],
        ['Kode permintaan', log.request_id],
    ];

    return (
        <>
            <Head title={`Audit #${log.id}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-6 p-4 md:px-7 md:py-6">
                <div className="flex items-center gap-3">
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        aria-label="Kembali ke audit log"
                    >
                        <Link href="/audit">
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Audit #{log.id}
                    </h1>
                </div>

                <dl className="grid gap-x-6 gap-y-3 rounded-xl border bg-card p-4 text-sm sm:grid-cols-[max-content_1fr]">
                    {meta.map(([label, value]) => (
                        <div key={label} className="contents">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="break-all">{value ?? '–'}</dd>
                        </div>
                    ))}
                </dl>

                <section className="space-y-2">
                    <h2 className="font-medium">Perubahan</h2>
                    {changes.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Tidak ada nilai yang dicatat untuk event ini.
                        </p>
                    ) : (
                        <div className="overflow-hidden rounded-xl border bg-card">
                            <Table aria-label="Nilai lama dan baru">
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>Kolom</TableHead>
                                        <TableHead>Lama</TableHead>
                                        <TableHead>Baru</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {changes.map((c) => (
                                        <TableRow
                                            key={c.key}
                                            className={cn(
                                                c.changed &&
                                                    'bg-status-warning-bg/40',
                                            )}
                                        >
                                            <TableCell className="align-top font-mono text-xs">
                                                {c.key}
                                            </TableCell>
                                            <TableCell className="align-top">
                                                <Value value={c.old} />
                                            </TableCell>
                                            <TableCell className="align-top">
                                                <Value value={c.new} />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

AuditShow.layout = {
    breadcrumbs: [
        { title: 'Audit log', href: '/audit' },
        { title: 'Detail', href: '#' },
    ],
};
