<script setup lang="ts">
import AuthUhasLayout from '@/layouts/auth/AuthUhasLayout.vue';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthUhasLayout :title="title" :description="description">
        <slot />
    </AuthUhasLayout>
</template>
