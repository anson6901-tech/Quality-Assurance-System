<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { authButton, authInput, authLabel, authLink } from '@/lib/authStyles';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        description: 'Enter your email and password below to log in',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-[#007a2f]"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="grid gap-5"
    >
        <div class="grid gap-2">
            <Label for="email" :class="authLabel">Email address</Label>
            <Input
                id="email"
                type="email"
                name="email"
                required
                autofocus
                :tabindex="1"
                autocomplete="email"
                placeholder="email@example.com"
                :class="authInput"
            />
            <InputError :message="errors.email" />
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between">
                <Label for="password" :class="authLabel">Password</Label>
                <TextLink
                    v-if="canResetPassword"
                    :href="request()"
                    :class="authLink"
                    :tabindex="4"
                >
                    Forgot password?
                </TextLink>
            </div>
            <Input
                id="password"
                type="password"
                name="password"
                required
                :tabindex="2"
                autocomplete="current-password"
                placeholder="Password"
                :class="authInput"
            />
            <InputError :message="errors.password" />
        </div>

        <input type="hidden" name="remember" value="on" />

        <Button
            type="submit"
            :class="['mt-3', authButton]"
            :tabindex="3"
            :disabled="processing"
            data-test="login-button"
        >
            <Spinner v-if="processing" />
            Log in
        </Button>
    </Form>
</template>
