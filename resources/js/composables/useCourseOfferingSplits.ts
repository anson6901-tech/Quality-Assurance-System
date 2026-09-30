import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';

type PeriodValue = string | number | null | undefined;

export function useCourseOfferingSplits(
    academicYearId: MaybeRefOrGetter<PeriodValue>,
    semesterId: MaybeRefOrGetter<PeriodValue>,
) {
    const page = usePage();
    const splitCourseCodes = ref<string[]>([]);
    const storageVersion = ref(0);
    const isMounted = ref(false);
    const keyForPeriod = (year: PeriodValue, semester: PeriodValue) =>
        [
            'qa-course-assignment-splits',
            page.props.auth?.user?.id ?? 'anonymous',
            year || 'no-year',
            semester || 'no-semester',
        ].join(':');
    const storageKey = computed(() =>
        keyForPeriod(toValue(academicYearId), toValue(semesterId)),
    );

    const readStoredCodes = (key: string): string[] => {
        if (typeof window === 'undefined') {
            return [];
        }

        try {
            const stored = JSON.parse(window.localStorage.getItem(key) ?? '[]');

            return Array.isArray(stored)
                ? stored.filter(
                      (code): code is string => typeof code === 'string',
                  )
                : [];
        } catch {
            return [];
        }
    };

    const save = () => {
        window.localStorage.setItem(
            storageKey.value,
            JSON.stringify(splitCourseCodes.value),
        );
    };

    const toggleCourseSplit = (courseCode: string) => {
        splitCourseCodes.value = splitCourseCodes.value.includes(courseCode)
            ? splitCourseCodes.value.filter((code) => code !== courseCode)
            : [...splitCourseCodes.value, courseCode];
        save();
        storageVersion.value++;
    };

    const isCourseSplitForPeriod = (
        courseCode: string,
        year: PeriodValue,
        semester: PeriodValue,
    ) => {
        void storageVersion.value;

        if (!isMounted.value) {
            return false;
        }

        return readStoredCodes(keyForPeriod(year, semester)).includes(
            courseCode,
        );
    };

    const toggleCourseSplitForPeriod = (
        courseCode: string,
        year: PeriodValue,
        semester: PeriodValue,
    ) => {
        const key = keyForPeriod(year, semester);
        const codes = readStoredCodes(key);
        const nextCodes = codes.includes(courseCode)
            ? codes.filter((code) => code !== courseCode)
            : [...codes, courseCode];

        window.localStorage.setItem(key, JSON.stringify(nextCodes));
        storageVersion.value++;
    };

    const syncFromStorage = (event: StorageEvent) => {
        if (event.key === storageKey.value) {
            splitCourseCodes.value = readStoredCodes(storageKey.value);
        }

        storageVersion.value++;
    };

    watch(
        storageKey,
        (key) => {
            if (isMounted.value) {
                splitCourseCodes.value = readStoredCodes(key);
            }
        },
        { immediate: true },
    );

    onMounted(() => {
        isMounted.value = true;
        splitCourseCodes.value = readStoredCodes(storageKey.value);
        window.addEventListener('storage', syncFromStorage);
    });
    onBeforeUnmount(() =>
        window.removeEventListener('storage', syncFromStorage),
    );

    return {
        splitCourseCodes,
        toggleCourseSplit,
        isCourseSplitForPeriod,
        toggleCourseSplitForPeriod,
    };
}
