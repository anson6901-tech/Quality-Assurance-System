<script setup lang="ts">
import AuthLoginLayout from '@/layouts/auth/AuthLoginLayout.vue';
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthLoginLayout
        v-if="title === 'Welcome back'"
        :title="title"
        :description="description"
    >
        <slot />
    </AuthLoginLayout>

    <AuthLayout v-else :title="title" :description="description">
        <slot />
    </AuthLayout>
</template>
