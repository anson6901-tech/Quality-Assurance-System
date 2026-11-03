<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <img
        src="/uhas-crest.png"
        alt="University of Health and Allied Sciences crest"
        :class="className"
        v-bind="$attrs"
        class="object-contain"
    />
</template>
