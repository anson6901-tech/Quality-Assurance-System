<script setup lang="ts">
defineProps<{
    title?: string;
    description?: string;
}>();

// Faint jigsaw outline tiled across the background, as on the UHAS Hostel portal.
const pattern = `url("data:image/svg+xml,${encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><path d="M14 14h20a8 8 0 1 1 16 0h20v20a8 8 0 1 1 0 16v20H50a8 8 0 1 0-16 0H14V50a8 8 0 1 0 0-16z" fill="none" stroke="#9fe0b8" stroke-opacity=".45" stroke-width="2"/></svg>',
)}")`;
</script>

<template>
    <div
        class="flex min-h-svh items-center justify-center bg-[#dcfce7] px-4 py-10 dark:bg-[#dcfce7]"
        :style="{ backgroundImage: pattern }"
    >
        <main
            class="w-full max-w-[426px] rounded-sm bg-white px-6 py-8 shadow-sm sm:px-7 dark:bg-white"
        >
            <div class="flex flex-col items-center text-center">
                <img
                    src="/uhas-crest.png"
                    alt="University of Health and Allied Sciences crest"
                    class="h-20 w-auto"
                />
                <h1
                    class="mt-5 text-[1.4rem] leading-tight font-medium text-[#111827] dark:text-[#111827]"
                >
                    UHAS Quality Assurance System
                </h1>
                <h2
                    v-if="title"
                    class="mt-3 text-base font-semibold text-[#111827] dark:text-[#111827]"
                >
                    {{ title }}
                </h2>
                <p
                    v-if="description"
                    class="mt-2 text-sm text-[#4b5563] dark:text-[#4b5563]"
                >
                    {{ description }}
                </p>
            </div>
            <div class="mt-7">
                <slot />
            </div>
        </main>
    </div>
</template>
