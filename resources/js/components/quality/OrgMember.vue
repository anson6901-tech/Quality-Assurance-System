<script setup lang="ts">
import { ArrowRightLeft, Crown, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

export type OrgPerson = {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    isActive: boolean;
    isCurrentUser: boolean;
};

defineProps<{
    person: OrgPerson;
    isHead?: boolean;
    headLabel?: string;
}>();

defineEmits<{
    move: [person: OrgPerson];
    remove: [person: OrgPerson];
}>();
</script>

<template>
    <div
        class="flex items-center gap-2 rounded-lg border bg-white px-3 py-2 text-sm"
        :class="
            isHead
                ? 'border-[#007a2f]/40 ring-1 ring-[#007a2f]/20'
                : 'border-[#d5ece0] dark:border-white/10'
        "
    >
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5">
                <Crown
                    v-if="isHead"
                    class="size-3.5 shrink-0 text-[#b7791f]"
                    :aria-label="headLabel"
                />
                <span class="truncate font-medium text-[#063f22]">
                    {{ person.name }}
                </span>
                <span
                    v-if="!person.isActive"
                    class="rounded bg-[#f1f3f4] px-1.5 text-[10px] font-semibold text-[#66808a] uppercase dark:bg-white/10"
                >
                    Inactive
                </span>
            </div>
            <div class="truncate text-xs text-[#66808a]">
                {{ isHead && headLabel ? headLabel : person.roleLabel }}
            </div>
        </div>
        <template v-if="!person.isCurrentUser">
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-7"
                :title="`Move ${person.name}`"
                @click="$emit('move', person)"
            >
                <ArrowRightLeft class="size-3.5" />
                <span class="sr-only">Move {{ person.name }}</span>
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-7 text-red-700 hover:bg-red-50 hover:text-red-800"
                :title="`Remove ${person.name} from the structure`"
                @click="$emit('remove', person)"
            >
                <X class="size-3.5" />
                <span class="sr-only">Remove {{ person.name }}</span>
            </Button>
        </template>
        <span v-else class="text-[10px] font-semibold text-[#66808a] uppercase">
            You
        </span>
    </div>
</template>
