export interface Legal {
    legalName: string;
    legalAddress: string;
    registrationDetails: string;
    signatoryName: string;
    signatoryDesignation: string;
    hrEmail: string;
    status: string;
    statusKey?: string;
    statusLabel?: string;
    verifiedAt: string | null;
    hasSignature: boolean;
    signatureOnFile?: boolean;
    signatureEnabled?: boolean;
    signatureActive?: boolean;
    signatureVersion?: number;
    signatureWidth?: number;
    signatureHeight?: number;
    signatureAlign?: string;
    signatureGapBefore?: number;
    signatureGapAfter?: number;
    signatureImageWidth?: number | null;
    signatureImageHeight?: number | null;
    updatedBy?: number | null;
    savedByHead?: boolean;
    updatedAt?: string | null;
}

export interface Letterhead {
    id: number;
    version: number;
    name: string;
    mime: string;
    status: string;
    backgroundReady: boolean;
    pageSize?: string;
    pageWidth?: number | null;
    pageHeight?: number | null;
    headerHeight?: number;
    footerHeight?: number;
    marginTop: number;
    marginRight?: number;
    marginLeft?: number;
    marginBottom?: number;
    printedName?: string;
    printedAddress?: string;
    note?: string | null;
    imageWidth?: number | null;
    imageHeight?: number | null;
    uploadedAt?: string | null;
}

export interface TemplateRow {
    id: number;
    name: string;
    status: string;
    version: number;
    body: string;
    approvedAt: string | null;
    createdBy: number | null;
    submittedBy: number | null;
    updatedAt?: string | null;
}

export interface HistoryRow {
    templateId: number;
    name: string;
    templateStatus: string;
    versionId: number;
    version: number;
    status: string;
    updatedAt: string | null;
}

export interface Identity {
    legalName: string;
    letterheadName: string;
    letterheadVersion: number | null;
    pageSize: string;
    previewUrl: string | null;
    legalAddress?: string;
    printedName?: string;
    printedAddress?: string;
    brand?: 'matched' | 'mismatched' | 'unrecorded';
    confirmed: boolean;
    confirmedBy: string;
    confirmedAt: string;
    canConfirm: boolean;
    warning: string;
}

const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function stamp(value?: string | null): string {
    const date = (value ?? '').slice(0, 10);
    const [year, month, day] = date.split('-').map(Number);

    if (!year || !month || !day) {
        return 'Not recorded';
    }

    return `${day} ${months[month - 1]} ${year}`;
}

export function templateStatus(status: string): { label: string; tone: 'green' | 'amber' | 'blue' | 'slate'; detail: string } {
    if (status === 'active') {
        return { label: 'Active', tone: 'green', detail: 'This is the appointment letter used for new India offers.' };
    }

    if (status === 'approved') {
        return { label: 'Approved', tone: 'blue', detail: 'Approved by Head of Operations, and waiting to be activated. The letter already in use stays available until then.' };
    }

    if (status === 'submitted') {
        return { label: 'Pending Approval', tone: 'amber', detail: 'Submitted by HR & Assistant Operations. Head of Operations approves it before activation.' };
    }

    if (status === 'archived') {
        return { label: 'Historical', tone: 'slate', detail: 'Kept for audit. It is not offered for new India letters.' };
    }

    return { label: 'Draft', tone: 'slate', detail: 'Working draft. HR & Assistant Operations submits it for approval. Head of Operations saves it active.' };
}
