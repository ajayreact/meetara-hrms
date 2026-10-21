import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';

export default function ForbiddenPage({ workspace, title, message }: HrmsPageProps & { title: string; message: string }) {
    return (
        <HrmsLayout workspace={workspace} title={title}>
            <PageIntro title={title} />
            <SectionCard title="Not included for this preview role">
                <p className="text-sm text-slate-600">{message}</p>
            </SectionCard>
        </HrmsLayout>
    );
}
