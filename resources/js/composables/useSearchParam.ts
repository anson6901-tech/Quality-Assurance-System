import { usePage } from '@inertiajs/vue3';

/** Reads a query-string value from the current Inertia URL (works during SSR too). */
export function useSearchParam(name: string): string {
    const page = usePage();

    return new URL(page.url, 'http://localhost').searchParams.get(name) ?? '';
}
