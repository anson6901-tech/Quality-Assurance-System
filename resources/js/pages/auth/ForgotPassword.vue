<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { authButton, authInput, authLabel, authLink } from '@/lib/authStyles';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Forgot password',
        description:
            'Enter your email address and we will send you a link to reset your password.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Forgot password" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-[#007a2f]"
    >
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email" :class="authLabel">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    autofocus
                    placeholder="email@example.com"
                    :class="authInput"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="mt-6">
                <Button
                    :class="authButton"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" />
                    Email password reset link
                </Button>
            </div>
        </Form>

        <div
            class="space-x-1 text-center text-sm text-[#4b5563] dark:text-[#4b5563]"
        >
            <span>Or, return to</span>
            <TextLink :href="login()" :class="authLink">log in</TextLink>
        </div>
    </div>
</template>
