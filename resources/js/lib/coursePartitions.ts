import type { CourseCell, SplitMode } from '@/types';

export type SplitChoice = Exclude<SplitMode, 'merged'>;

export const splitChoices: Array<{
    value: SplitChoice;
    label: string;
    description: string;
}> = [
    {
        value: 'level_program',
        label: 'Program and level',
        description:
            'One part for each program at its level. Sections stay together.',
    },
    {
        value: 'level_section',
        label: 'Level and section',
        description:
            'One part for each section at each level. The same section from different programs is taught together.',
    },
    {
        value: 'section_program',
        label: 'Program and section',
        description: 'One part for each section of each program.',
    },
    {
        value: 'level_program_section',
        label: 'Level, program and section',
        description:
            'The finest split: each program section at its level is its own part.',
    },
];

/** Mirrors App\Support\CoursePartitions::partKey(). */
export function partKey(mode: SplitMode, cell: CourseCell): string {
    const program = cell.programId ?? 0;
    const section = cell.sectionId ?? 0;
    const level = cell.level ?? 0;
    const sectionKind = cell.section
        ? `${cell.section.type}${cell.section.isWeekend ? '-weekend' : ''}`
        : 'none';

    switch (mode) {
        case 'level_program':
            return `L${level}:P${program}`;
        case 'level_section':
            return `L${level}:T${sectionKind}`;
        case 'section_program':
            return `P${program}:S${section}`;
        case 'level_program_section':
            return `L${level}:P${program}:S${section}`;
        default:
            return 'all';
    }
}

/** Groups cells into the parts a split would produce, with a readable label for each. */
export function previewParts(mode: SplitMode, cells: CourseCell[]): string[] {
    const groups = new Map<string, CourseCell[]>();

    cells.forEach((cell) => {
        const key = partKey(mode, cell);
        groups.set(key, [...(groups.get(key) ?? []), cell]);
    });

    return [...groups.values()].map((group) => {
        const programs = [
            ...new Set(group.map((cell) => cell.program?.code).filter(Boolean)),
        ].join(', ');
        const section = group[0].section?.name ?? 'All sections';
        const level = group[0].level
            ? `Level ${group[0].level}`
            : 'Level not set';

        switch (mode) {
            case 'level_program':
                return `${programs} · ${level}`;
            case 'level_section':
                return `${level} · ${section} · ${programs}`;
            case 'section_program':
                return `${programs} · ${section}`;
            default:
                return `${programs} · ${level} · ${section}`;
        }
    });
}

export const sectionOptions = [
    'undergraduate',
    'postgraduate',
    'sandwich',
    'access',
].flatMap((type) => {
    const label = type.charAt(0).toUpperCase() + type.slice(1);

    return [
        { value: type, label },
        { value: `${type}_weekend`, label: `${label} · Weekend` },
    ];
});

export const sectionKey = (section: { type: string; isWeekend: boolean }) =>
    section.isWeekend ? `${section.type}_weekend` : section.type;

/** True when every word of the query appears in one of the given values. */
export function matchesSearch(
    query: string,
    values: Array<string | null | undefined>,
): boolean {
    const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return true;
    }

    const haystack = values.filter(Boolean).join(' ').toLowerCase();

    // A course code such as "NURS 301" must match as a whole code, not word by word.
    if (/^[a-z]{2,6}\s*\d{3}[a-z]?$/i.test(query.trim())) {
        const code = query.replace(/\s+/g, '').toLowerCase();

        return values.some((value) =>
            (value ?? '').replace(/\s+/g, '').toLowerCase().includes(code),
        );
    }

    return words.every((word) => haystack.includes(word));
}
