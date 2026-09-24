export type QualityProfile = {
    role: string | null;
    roleLabel: string | null;
    school: { id: number; name: string } | null;
    department: { id: number; name: string } | null;
    permissions: string[];
    canManageUsers: boolean;
    canManageQualityStructure: boolean;
    canManageCourseAssignments: boolean;
    canViewReports: boolean;
    canUploadReports: boolean;
    canViewApprovalQueue: boolean;
    canReviewReports: boolean;
    canApproveReports: boolean;
    canViewPrintQueue: boolean;
    canViewAuditLogs: boolean;
};

export type QualityReport = {
    id: number;
    title: string;
    category: string;
    status: string;
    academicYear: AcademicYearOption | null;
    semester: { id: number; name: string } | null;
    summary: string | null;
    filePath: string | null;
    fileUrl: string | null;
    downloadUrl: string | null;
    location: string;
    school: { id: number; name: string } | null;
    department: { id: number; name: string } | null;
    program: { id: number; name: string; code: string } | null;
    lecturer: LecturerOption | null;
    courses: CourseOption[];
    uploader: { id: number; name: string; email?: string | null } | null;
    reviews: Array<{
        id: number;
        action: string;
        note: string | null;
        actor: {
            id: number;
            name: string;
            email: string;
            role: string | null;
        } | null;
        recipient: {
            name: string;
            email: string;
            role: string | null;
        } | null;
        createdAt: string;
    }>;
    createdAt: string;
    isLocked: boolean;
};

export type AcademicYearOption = {
    id: number;
    name: string;
    status: string;
};

export type SchoolOption = {
    id: number;
    name: string;
    departments?: DepartmentOption[];
};

export type DepartmentOption = {
    id: number;
    school_id?: number;
    name: string;
};

export type CourseOption = {
    id: number;
    code: string;
    title: string;
    level?: string | null;
    creditHours?: number | null;
    department?: { id: number; name: string } | null;
    lecturerCount?: number;
    lecturers?: { id: number; name: string; email: string }[];
};

export type LecturerOption = {
    id: number;
    name: string;
    email: string;
    school: { id: number; name: string } | null;
    department: { id: number; name: string } | null;
    courses?: CourseOption[];
};
