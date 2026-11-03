export type AppRole = 'super_admin' | 'company_admin' | 'hr' | 'accounts' | 'team_lead' | 'employee';

export interface WorkspacePermissions {
    employees: boolean;
    settings: boolean;
    routeMap: boolean;
    attendanceManage: boolean;
    attendanceCorrect: boolean;
    leaveApprove: boolean;
    payroll: boolean;
    salaryManage: boolean;
    payrollView: boolean;
    payrollApprove: boolean;
    payrollPay: boolean;
    payrollImport: boolean;
    offices: boolean;
    exportAttendance: boolean;
    companies: boolean;
    audit: boolean;
    i983Sign: boolean;
}

export interface Workspace {
    interfacePreview: boolean;
    role: AppRole;
    roleLabel: string;
    companyName: string | null;
    userName: string;
    designation: string;
    email: string;
    officeName: string | null;
    officeRegion: string | null;
    authenticated: boolean;
    authName: string | null;
    authEmail: string | null;
    permissions: WorkspacePermissions;
}

export interface HrmsPageProps {
    workspace: Workspace;
}
