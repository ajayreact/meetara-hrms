import { bodyCell, bodyRow, DataTable, headCell } from '@/components/hrms/data-table';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard, StatusBadge } from '@/components/hrms/section-card';
import { Button } from '@/components/ui/button';
import type { HrmsPageProps } from '@/types/hrms';
import { router } from '@inertiajs/react';

interface AccessLevel {
    value: string;
    label: string;
    description: string;
    available: boolean;
    protected: boolean;
}

interface AccessLevelsPageProps extends HrmsPageProps {
    levels: AccessLevel[];
    notice?: string | null;
}

export default function AccessLevelsPage({ workspace, levels, notice }: AccessLevelsPageProps) {
    return (
        <HrmsLayout workspace={workspace} title="Access levels">
            <PageIntro
                title="Access levels"
                subtitle="Employee, Team Lead, Company Manager, and HR & Assistant Operations are available for this company. Head of Operations stays protected and is not removed."
            />
            {notice && <p className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{notice}</p>}
            <SectionCard title="Roles for this company">
                <DataTable>
                    <thead>
                        <tr>
                            <th className={headCell}>Access level</th>
                            <th className={headCell}>Use</th>
                            <th className={headCell}>Status</th>
                            <th className={headCell}>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        {levels.map((level) => (
                            <tr key={level.value} className={bodyRow}>
                                <td className={bodyCell}>{level.label}</td>
                                <td className={bodyCell}>{level.description}</td>
                                <td className={bodyCell}>
                                    <StatusBadge value={level.protected ? 'Protected' : (level.available ? 'Active' : 'Inactive')} />
                                </td>
                                <td className={bodyCell}>
                                    {level.value === 'Accounts' && !level.available && (
                                        <Button type="button" variant="outline" onClick={() => router.post('/company/access-levels/accounts')}>Enable Accounts access</Button>
                                    )}
                                    {level.protected && <span className="text-sm text-slate-500">Cannot be removed</span>}
                                    {level.value !== 'Accounts' && !level.protected && <span className="text-sm text-slate-500">Available when creating a login</span>}
                                    {level.value === 'Accounts' && level.available && <span className="text-sm text-slate-500">Existing assignments only</span>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </DataTable>
            </SectionCard>
        </HrmsLayout>
    );
}
