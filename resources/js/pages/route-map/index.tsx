import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';

export default function RouteMapPage({ workspace }: HrmsPageProps) {
    return (
        <HrmsLayout workspace={workspace} title="Route Map">
            <PageIntro title="Route Map" subtitle="This module is not implemented. Location tracking is not enabled." />
            <SectionCard title="Locations">
                <p className="text-sm text-slate-500">No location data is collected. A route map needs an approved use case before any employee location is stored.</p>
            </SectionCard>
        </HrmsLayout>
    );
}
