<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <header
        class="page-banner relative overflow-hidden rounded-2xl bg-[linear-gradient(120deg,#004d22_0%,#006b30_55%,#00853a_100%)] px-5 py-6 shadow-lg shadow-[#005f28]/15 sm:px-7 print:hidden"
    >
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -top-16 -right-10 size-56 rounded-full bg-white/[0.06]"
        />
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -right-24 -bottom-24 size-64 rounded-full bg-white/[0.05]"
        />
        <div
            class="relative flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
        >
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight text-white">
                    {{ title }}
                </h1>
                <p
                    v-if="description"
                    class="mt-1 max-w-3xl text-sm leading-6 text-white/80"
                >
                    {{ description }}
                </p>
            </div>
            <div
                v-if="$slots.actions"
                class="page-banner-actions flex shrink-0 flex-wrap gap-2"
            >
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>

<style scoped>
/* Buttons placed on the green banner become white so they stay readable. */
.page-banner-actions :deep(:is(button, a)) {
    border-color: rgb(255 255 255 / 0.85);
    background-color: #ffffff;
    color: #06502a;
}

.page-banner-actions :deep(:is(button, a):hover) {
    background-color: #e8f7ee;
    color: #004d22;
}
</style>
