export type QualityProfile = {
    role: string | null;
    roleLabel: string | null;
    school: { id: number; name: string } | null;
    department: { id: number; name: string } | null;
    placement: 'university' | 'school' | 'department' | null;
    placementLabel: string;
    permissions: string[];
    canManageUsers: boolean;
    canManageRoles: boolean;
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
    canView: boolean;
    fileUrl: string | null;
    downloadUrl: string | null;
    location: string;
    school: { id: number; name: string } | null;
    department: { id: number; name: string } | null;
    program: { id: number; name: string; code: string } | null;
    section?: { id: number; name: string } | null;
    part?: { key: string; label: string; mode: SplitMode } | null;
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

export type SplitMode =
    | 'merged'
    | 'level_program'
    | 'level_section'
    | 'section_program'
    | 'level_program_section';

export type CourseCell = {
    programId: number | null;
    sectionId: number | null;
    level: string | null;
    program: { id: number; name: string; code: string } | null;
    section: {
        id: number;
        name: string;
        type: string;
        isWeekend: boolean;
    } | null;
};

export type PartLecturer = {
    id: number;
    name: string;
    email: string;
    studentCount: number | null;
    allocatedHours: number | null;
};

export type CoursePart = {
    key: string;
    label: string;
    cells: CourseCell[];
    lecturers: PartLecturer[];
    mixedLecturers: boolean;
    unassignedCells: number;
};

export type CourseEntry<Part extends CoursePart = CoursePart> = {
    key: string;
    id: number;
    code: string;
    title: string;
    courseType: 'normal' | 'module';
    creditHours: number | null;
    semesterId: number;
    department: {
        id: number;
        name: string;
        school: { id: number; name: string } | null;
    } | null;
    splitMode: SplitMode;
    programs: Array<{ id: number; name: string; code: string }>;
    sections: Array<{ id: number; name: string }>;
    levels: string[];
    hasSections: boolean;
    cellCount: number;
    parts: Part[];
};
