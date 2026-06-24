<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowRight, LockKeyhole, Mail } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Welcome back',
        description: 'Sign in to continue to your QA dashboard.',
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
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label for="email" class="text-sm font-semibold text-[#071126]">
                    Email
                </Label>
                <div class="relative">
                    <Mail
                        class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[#637083]"
                    />
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="you@uhas.edu.gh"
                        class="h-12 rounded-xl border-[#d8dee8] bg-[#f6f8fb] pl-12 text-base text-[#071126] shadow-none placeholder:text-[#657287] focus-visible:ring-[#007a2f]/25"
                    />
                </div>
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label
                        for="password"
                        class="text-sm font-semibold text-[#071126]"
                    >
                        Password
                    </Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm font-medium text-[#007a2f] hover:text-[#005f27]"
                        :tabindex="5"
                    >
                        Forgot?
                    </TextLink>
                </div>
                <div class="relative">
                    <LockKeyhole
                        class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[#637083]"
                    />
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="........"
                        class="h-12 rounded-xl border-[#d8dee8] bg-[#f6f8fb] pl-12 text-base text-[#071126] shadow-none placeholder:text-[#657287] focus-visible:ring-[#007a2f]/25"
                    />
                </div>
                <InputError :message="errors.password" />
            </div>

            <input type="hidden" name="remember" value="on" />

            <Button
                type="submit"
                class="mt-2 h-12 w-full rounded-xl bg-[#007a2f] text-base font-bold text-white shadow-none hover:bg-[#006d2b]"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Sign in
                <ArrowRight class="ml-2 size-5" />
            </Button>
        </div>

        <div class="mt-2 flex items-center gap-4">
            <div class="h-px flex-1 bg-[#d8dee8]" />
            <span
                class="text-xs font-semibold tracking-[0.14em] text-[#657287]"
            >
                LECTURER?
            </span>
            <div class="h-px flex-1 bg-[#d8dee8]" />
        </div>

        <Button
            type="button"
            variant="outline"
            class="h-12 rounded-xl border-[#d8dee8] bg-transparent text-sm font-semibold text-[#071126] hover:bg-[#eef3f8]"
        >
            Verify identity to view your report
        </Button>
    </Form>
</template>
