<script setup lang="ts">
import { Send, Trash2, Upload } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

defineProps<{
    report?: { id: number; status: string } | null;
}>();

const emit = defineEmits<{
    upload: [];
    push: [reportId: number];
    delete: [reportId: number];
}>();
</script>

<template>
    <div class="flex items-center justify-start gap-2 md:justify-end">
        <template v-if="report?.status === 'draft'">
            <Button
                type="button"
                size="icon"
                variant="ghost"
                class="size-8 text-red-700 hover:bg-red-50 hover:text-red-800"
                title="Delete draft"
                @click="emit('delete', report.id)"
            >
                <Trash2 class="h-4 w-4" />
                <span class="sr-only">Delete draft</span>
            </Button>
            <Button
                type="button"
                size="sm"
                class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                @click="emit('push', report.id)"
            >
                <Send class="h-4 w-4" />
                Push
            </Button>
        </template>
        <Button
            v-else-if="!report"
            type="button"
            size="sm"
            variant="outline"
            class="border-[#8bcfaa] text-[#006d2b] hover:bg-[#e7f6ed]"
            @click="emit('upload')"
        >
            <Upload class="h-4 w-4" />
            Upload
        </Button>
        <span v-else class="text-xs text-[#66808a]">No action needed</span>
    </div>
</template>
