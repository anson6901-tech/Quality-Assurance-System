export type QualityProfile = {
    role: string | null;
    roleLabel: string | null;
    school: { id: number; name: string } | null;
    department: { id: number; name: string } | null;
    canManageQualityStructure: boolean;
    canUploadReports: boolean;
};

export type QualityReport = {
    id: number;
    title: string;
    category: string;
    status: string;
    academicYear: AcademicYearOption | null;
    summary: string | null;
    filePath: string | null;
    school: { id: number; name: string } | null;
    department: { id: number; name: string } | null;
    lecturer: LecturerOption | null;
    courses: CourseOption[];
    uploader: { id: number; name: string; email?: string | null } | null;
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
};

export type LecturerOption = {
    id: number;
    name: string;
    email: string;
    school: { id: number; name: string } | null;
    department: { id: number; name: string } | null;
    courses?: CourseOption[];
};
