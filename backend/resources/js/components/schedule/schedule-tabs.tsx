import { CalendarDays, CalendarX2, ListChecks } from 'lucide-react';
import { PageTabs } from '@/components/pakis/page-tabs';
import { calendar, index } from '@/routes/admin/schedules';
import { index as overrides } from '@/routes/admin/schedule-overrides';

export type ScheduleTab = 'pola' | 'kalender' | 'pengecualian';

const LABELS: Record<ScheduleTab, string> = {
    pola: 'Pola',
    kalender: 'Kalender',
    pengecualian: 'Pengecualian',
};

export function scheduleTabLabel(tab: ScheduleTab): string {
    return LABELS[tab];
}

/** Satu menu Jadwal di sidebar, tiga halaman (pola prototipe): Pola · Kalender · Pengecualian. */
export function ScheduleTabs({ active }: { active: ScheduleTab }) {
    return (
        <PageTabs
            label="Bagian jadwal"
            active={active}
            tabs={[
                {
                    key: 'pola',
                    label: LABELS.pola,
                    href: index(),
                    icon: ListChecks,
                },
                {
                    key: 'kalender',
                    label: LABELS.kalender,
                    href: calendar(),
                    icon: CalendarDays,
                },
                {
                    key: 'pengecualian',
                    label: LABELS.pengecualian,
                    href: overrides(),
                    icon: CalendarX2,
                },
            ]}
        />
    );
}
