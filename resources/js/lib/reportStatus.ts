export type ReportStatusKey =
    | 'not_uploaded'
    | 'not_published'
    | 'draft'
    | 'submitted'
    | 'under_review'
    | 'approved'
    | 'mixed';

type StatusStyle = {
    label: string;
    pill: string;
    stripe: string;
    dot: string;
};

export const reportStatusStyles: Record<ReportStatusKey, StatusStyle> = {
    not_uploaded: {
        label: 'Not uploaded',
        pill: 'border border-[#d5dee3] bg-[#f5f8fa] text-[#52646c]',
        stripe: 'border-l-[#cfd9de]',
        dot: 'bg-[#a9b8bf]',
    },
    not_published: {
        label: 'Not published',
        pill: 'border border-[#d5dee3] bg-[#f5f8fa] text-[#52646c]',
        stripe: 'border-l-[#cfd9de]',
        dot: 'bg-[#a9b8bf]',
    },
    draft: {
        label: 'Draft',
        pill: 'border border-[#cbd3dc] bg-[#e9edf2] text-[#334155]',
        stripe: 'border-l-[#64748b]',
        dot: 'bg-[#64748b]',
    },
    submitted: {
        label: 'With supervisor',
        pill: 'border border-[#f5d48a] bg-[#fdf3d7] text-[#8a4b06]',
        stripe: 'border-l-[#d98a0b]',
        dot: 'bg-[#d98a0b]',
    },
    under_review: {
        label: 'With director',
        pill: 'border border-[#b8d2f5] bg-[#e3eefc] text-[#1d4f99]',
        stripe: 'border-l-[#2f6fd1]',
        dot: 'bg-[#2f6fd1]',
    },
    approved: {
        label: 'Published',
        pill: 'border border-[#a8dcbc] bg-[#e1f5e8] text-[#0b6130]',
        stripe: 'border-l-[#0f8a3e]',
        dot: 'bg-[#0f8a3e]',
    },
    mixed: {
        label: 'Mixed status',
        pill: 'border border-[#d6c9f2] bg-[#efe9fb] text-[#56349c]',
        stripe: 'border-l-[#7c5cc4]',
        dot: 'bg-[#7c5cc4]',
    },
};

export const reportStatusOrder: ReportStatusKey[] = [
    'not_uploaded',
    'draft',
    'submitted',
    'under_review',
    'approved',
];

export function toReportStatus(status?: string | null): ReportStatusKey {
    return status && status in reportStatusStyles
        ? (status as ReportStatusKey)
        : 'not_uploaded';
}
