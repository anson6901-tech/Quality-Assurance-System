<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type AuditMetadata = {
    summary?: string;
    status?: number;
    route?: string;
    target?: {
        type?: string;
        id?: number | string;
        value?: string;
        label?: string;
    };
    submitted?: {
        field_names?: string[];
        fields?: Record<string, unknown>;
        sensitive_fields_present?: string[];
    };
    actor?: {
        role?: string;
        school_id?: number;
        department_id?: number;
        current_team_id?: number;
    };
    referrer?: string;
};

type AuditLog = {
    id: number;
    event: string;
    module: string | null;
    action: string | null;
    method: string | null;
    path: string | null;
    ipAddress: string | null;
    metadata: AuditMetadata | null;
    createdAt: string;
    user: { id: number; name: string; email: string } | null;
};

defineProps<{
    logs: AuditLog[];
}>();

const formatEvent = (event: string) => event.replaceAll('_', ' ');

const formatFieldValue = (value: unknown): string => {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    if (typeof value === 'object') {
        const objectValue = value as { count?: number; values?: unknown[] };

        if (Array.isArray(objectValue.values)) {
            return `${objectValue.count ?? objectValue.values.length} selected: ${objectValue.values.join(', ')}`;
        }

        return JSON.stringify(value);
    }

    return String(value);
};

const detailItems = (log: AuditLog) => {
    const items: string[] = [];

    if (log.metadata?.route) {
        items.push(`Route: ${log.metadata.route}`);
    }

    if (log.metadata?.status) {
        items.push(`Status: ${log.metadata.status}`);
    }

    if (log.metadata?.target) {
        const target = log.metadata.target;
        items.push(
            `Target: ${target.type ?? 'Record'} ${target.label ?? target.value ?? target.id ?? ''}`.trim(),
        );
    }

    if (log.metadata?.actor?.role) {
        items.push(`Actor role: ${log.metadata.actor.role}`);
    }

    if (log.metadata?.submitted?.sensitive_fields_present?.length) {
        items.push(
            `Sensitive input present: ${log.metadata.submitted.sensitive_fields_present.join(', ')}`,
        );
    }

    return items;
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Audit Logs',
                href: '/quality/audit-logs',
            },
        ],
    },
});
</script>

<template>
    <Head title="Audit Logs" />

    <section class="space-y-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                variant="small"
                title="Audit Logs"
                description="Detailed activity trail showing who did what, where, and with which submitted fields"
            />

            <Button
                as="a"
                href="/quality/audit-logs/export"
                class="w-full gap-2 sm:w-auto"
            >
                <Download class="size-4" />
                Export CSV
            </Button>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"
        >
            <div
                class="grid grid-cols-[170px_220px_130px_130px_1fr_130px] gap-4 border-b border-[#9fd8b8] bg-[#e8f6ee] px-4 py-3 text-sm font-bold text-[#063f22]"
            >
                <span>Time</span>
                <span>User</span>
                <span>Event</span>
                <span>Module</span>
                <span>Activity details</span>
                <span>IP address</span>
            </div>

            <div
                v-if="logs.length === 0"
                class="px-4 py-12 text-center text-sm text-muted-foreground"
            >
                No audit logs have been recorded yet.
            </div>

            <div
                v-for="log in logs"
                :key="log.id"
                class="grid grid-cols-[170px_220px_130px_130px_1fr_130px] gap-4 border-b px-4 py-4 text-sm last:border-b-0"
            >
                <span class="text-muted-foreground">{{ log.createdAt }}</span>
                <div>
                    <div class="font-medium">
                        {{ log.user?.name ?? 'System' }}
                    </div>
                    <div class="text-muted-foreground">
                        {{ log.user?.email }}
                    </div>
                </div>
                <div>
                    <Badge variant="secondary">
                        {{ formatEvent(log.event) }}
                    </Badge>
                    <div class="mt-2 text-xs text-muted-foreground">
                        {{ log.method ?? '-' }}
                    </div>
                </div>
                <span>{{ log.module ?? 'General' }}</span>
                <div class="min-w-0 space-y-2">
                    <div class="font-medium">
                        {{ log.metadata?.summary ?? log.action ?? '-' }}
                    </div>
                    <div class="break-all text-muted-foreground">
                        {{ log.path }}
                    </div>
                    <div
                        v-if="detailItems(log).length"
                        class="flex flex-wrap gap-2 text-xs text-muted-foreground"
                    >
                        <span
                            v-for="item in detailItems(log)"
                            :key="item"
                            class="rounded border border-[#d7eadf] px-2 py-1"
                        >
                            {{ item }}
                        </span>
                    </div>
                    <div
                        v-if="log.metadata?.submitted?.fields"
                        class="space-y-1 text-xs text-muted-foreground"
                    >
                        <div
                            v-for="(value, field) in log.metadata.submitted
                                .fields"
                            :key="field"
                            class="grid grid-cols-[140px_1fr] gap-2"
                        >
                            <span class="font-medium text-foreground">{{
                                field
                            }}</span>
                            <span class="break-words">{{
                                formatFieldValue(value)
                            }}</span>
                        </div>
                    </div>
                </div>
                <span>{{ log.ipAddress ?? '-' }}</span>
            </div>
        </div>
    </section>
</template>
