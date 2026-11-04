import type { HrmsPageProps } from '@/types/hrms';
import CatalogPage from './catalog';

interface DesignationRecord {
    id: number;
    name: string;
    active: boolean;
}

interface DesignationsPageProps extends HrmsPageProps {
    designations: DesignationRecord[];
    notice?: string | null;
}

export default function DesignationsPage({ workspace, designations, notice }: DesignationsPageProps) {
    return (
        <CatalogPage
            workspace={workspace}
            records={designations}
            notice={notice}
            title="Designations"
            description="Designations belong to this company. A designation is not tied to one department. Deactivating one keeps it on existing employees and hides it from new assignments."
            storeUrl="/designations"
            updateUrl={(id) => `/designations/${id}`}
        />
    );
}
