import type { HrmsPageProps } from '@/types/hrms';
import CatalogPage from './catalog';

interface DepartmentRecord {
    id: number;
    name: string;
    code: string | null;
    active: boolean;
}

interface DepartmentsPageProps extends HrmsPageProps {
    departments: DepartmentRecord[];
    notice?: string | null;
}

export default function DepartmentsPage({ workspace, departments, notice }: DepartmentsPageProps) {
    return (
        <CatalogPage
            workspace={workspace}
            records={departments}
            notice={notice}
            title="Departments"
            description="Departments belong to this company. Deactivating one keeps it on existing employees and hides it from new assignments."
            storeUrl="/departments"
            updateUrl={(id) => `/departments/${id}`}
            showCode
        />
    );
}
