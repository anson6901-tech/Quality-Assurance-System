<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    CircleAlert,
    Mail,
    Save,
    Send,
    ShieldCheck,
} from 'lucide-vue-next';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PageBanner from '@/components/PageBanner.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Provider = 'microsoft365' | 'gmail';
type Settings = {
    enabled: boolean;
    provider: Provider;
    fromAddress: string | null;
    gmailAddress: string | null;
    hasGmailAppPassword: boolean;
    tenantId: string | null;
    clientId: string | null;
    hasClientSecret: boolean;
    senderAddress: string | null;
    senderName: string | null;
    appUrl: string | null;
    sendWelcome: boolean;
    sendReviewRequests: boolean;
    sendReportPublished: boolean;
    sendReportReturned: boolean;
    isConfigured: boolean;
    lastTest: { at: string; succeeded: boolean; message: string | null } | null;
};

const props = defineProps<{
    settings: Settings;
    defaultTestRecipient: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Email Settings', href: '/quality/email-settings' },
        ],
    },
});

const form = useForm({
    enabled: props.settings.enabled,
    provider: props.settings.provider,
    tenant_id: props.settings.tenantId ?? '',
    client_id: props.settings.clientId ?? '',
    client_secret: '',
    sender_address: props.settings.senderAddress ?? '',
    gmail_address: props.settings.gmailAddress ?? '',
    gmail_app_password: '',
    sender_name: props.settings.senderName ?? 'UHAS QA Insight',
    app_url: props.settings.appUrl ?? '',
    send_welcome: props.settings.sendWelcome,
    send_review_requests: props.settings.sendReviewRequests,
    send_report_published: props.settings.sendReportPublished,
    send_report_returned: props.settings.sendReportReturned,
});
const testForm = useForm({ recipient: props.defaultTestRecipient });

const providers: { value: Provider; label: string; description: string }[] = [
    {
        value: 'microsoft365',
        label: 'Microsoft 365',
        description:
            'A UHAS mailbox, through an Entra ID app registration (OAuth 2.0).',
    },
    {
        value: 'gmail',
        label: 'Gmail',
        description:
            'A Gmail account, signed in with a Google app password. About 500 recipients a day.',
    },
];
const providerLabel = (provider: Provider) =>
    provider === 'gmail' ? 'Gmail' : 'Microsoft 365';

const notificationOptions = [
    {
        key: 'send_welcome',
        label: 'Welcome email with a temporary password',
        description:
            'New users are emailed a system-generated password and must choose their own at first sign-in. The password fields disappear from the Add user form.',
    },
    {
        key: 'send_review_requests',
        label: 'Reports waiting for review',
        description:
            'The QA Supervisor when a report is sent to them, and the approvers in that area when it is forwarded.',
    },
    {
        key: 'send_report_published',
        label: 'Report published',
        description: 'The lecturer, when a report about them is approved.',
    },
    {
        key: 'send_report_returned',
        label: 'Report returned',
        description:
            'The QA Officer or Supervisor, with the reviewer’s note, when a report is sent back.',
    },
] as const;

const lastTestedAt = computed(() =>
    props.settings.lastTest
        ? new Date(props.settings.lastTest.at).toLocaleString()
        : null,
);
const providerChanged = computed(
    () => form.provider !== props.settings.provider,
);

const save = () =>
    form.put('/quality/email-settings', {
        preserveScroll: true,
        onSuccess: () => form.reset('client_secret', 'gmail_app_password'),
    });
const sendTest = () =>
    testForm.post('/quality/email-settings/test', { preserveScroll: true });
</script>

<template>
    <Head title="Email Settings" />

    <div class="space-y-6">
        <PageBanner
            title="Email Settings"
            description="Send password resets and report notifications from Microsoft 365 or Gmail. Everything is set here; nothing needs changing on the server."
        />

        <div
            class="flex flex-wrap items-center gap-3 rounded-xl border px-4 py-3 text-sm"
            :class="
                settings.enabled
                    ? 'border-[#9fd8b8] bg-[#edf9f2] text-[#063f22]'
                    : 'border-[#f0d3a4] bg-[#fffaf1] text-[#5c3d0b] dark:border-amber-700/60 dark:bg-amber-950/30 dark:text-amber-100'
            "
        >
            <CheckCircle2
                v-if="settings.enabled"
                class="size-5 text-[#007a2f]"
            />
            <CircleAlert v-else class="size-5 text-[#b7791f]" />
            <span v-if="settings.enabled">
                Email is <strong>on</strong> through
                <strong>{{ providerLabel(settings.provider) }}</strong
                >. Messages are sent from
                <strong>{{ settings.fromAddress }}</strong
                >.
            </span>
            <span v-else>
                Email is <strong>off</strong>. Password reset emails are written
                to the server log instead of being sent.
            </span>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <form
                class="space-y-6 rounded-xl border border-[#9fd8b8] bg-white p-5 shadow-sm"
                @submit.prevent="save"
            >
                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-[#063f22]">
                        Send email with
                    </h2>
                    <div class="grid gap-3 sm:grid-cols-2" role="radiogroup">
                        <label
                            v-for="provider in providers"
                            :key="provider.value"
                            class="flex cursor-pointer gap-3 rounded-lg border p-3"
                            :class="
                                form.provider === provider.value
                                    ? 'border-[#007a2f] bg-[#edf9f2] ring-1 ring-[#007a2f]/30'
                                    : 'border-[#d5ece0] hover:bg-[#edf9f2] dark:border-white/10'
                            "
                        >
                            <input
                                v-model="form.provider"
                                type="radio"
                                name="provider"
                                :value="provider.value"
                                class="mt-1 size-4 accent-[#007a2f]"
                            />
                            <span>
                                <span
                                    class="block text-sm font-semibold text-[#063f22]"
                                >
                                    {{ provider.label }}
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ provider.description }}
                                </span>
                            </span>
                        </label>
                    </div>
                    <InputError :message="form.errors.provider" />
                </section>

                <section
                    v-if="form.provider === 'microsoft365'"
                    class="space-y-4 border-t border-[#d5ece0] pt-5 dark:border-white/10"
                >
                    <div class="flex items-center gap-2">
                        <ShieldCheck class="size-5 text-[#007a2f]" />
                        <h2 class="text-lg font-semibold text-[#063f22]">
                            Microsoft 365 connection
                        </h2>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="tenant-id">Directory (tenant) ID</Label>
                            <Input
                                id="tenant-id"
                                v-model="form.tenant_id"
                                placeholder="00000000-0000-0000-0000-000000000000"
                                autocomplete="off"
                            />
                            <InputError :message="form.errors.tenant_id" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="client-id"
                                >Application (client) ID</Label
                            >
                            <Input
                                id="client-id"
                                v-model="form.client_id"
                                placeholder="00000000-0000-0000-0000-000000000000"
                                autocomplete="off"
                            />
                            <InputError :message="form.errors.client_id" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="client-secret">Client secret value</Label>
                        <Input
                            id="client-secret"
                            v-model="form.client_secret"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="
                                settings.hasClientSecret
                                    ? 'Saved. Leave blank to keep it.'
                                    : 'Paste the secret value from Azure'
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            Stored encrypted and never shown again. When the
                            secret expires in Azure, paste the new one here.
                        </p>
                        <InputError :message="form.errors.client_secret" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="sender-address">Send from mailbox</Label>
                        <Input
                            id="sender-address"
                            v-model="form.sender_address"
                            type="email"
                            placeholder="qa-insight@uhas.edu.gh"
                        />
                        <InputError :message="form.errors.sender_address" />
                    </div>
                </section>

                <section
                    v-else
                    class="space-y-4 border-t border-[#d5ece0] pt-5 dark:border-white/10"
                >
                    <div class="flex items-center gap-2">
                        <ShieldCheck class="size-5 text-[#007a2f]" />
                        <h2 class="text-lg font-semibold text-[#063f22]">
                            Gmail account
                        </h2>
                    </div>
                    <div class="grid gap-2">
                        <Label for="gmail-address">Gmail address</Label>
                        <Input
                            id="gmail-address"
                            v-model="form.gmail_address"
                            type="email"
                            placeholder="uhas.qa.insight@gmail.com"
                            autocomplete="off"
                        />
                        <p class="text-xs text-muted-foreground">
                            Emails are sent from this address.
                        </p>
                        <InputError :message="form.errors.gmail_address" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="gmail-app-password">App password</Label>
                        <Input
                            id="gmail-app-password"
                            v-model="form.gmail_app_password"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="
                                settings.hasGmailAppPassword
                                    ? 'Saved. Leave blank to keep it.'
                                    : 'abcd efgh ijkl mnop'
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            The 16-letter app password from Google, not the
                            normal Gmail password. Spaces are fine. Stored
                            encrypted and never shown again.
                        </p>
                        <InputError :message="form.errors.gmail_app_password" />
                    </div>
                </section>

                <section
                    class="space-y-4 border-t border-[#d5ece0] pt-5 dark:border-white/10"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="sender-name">Sender name</Label>
                            <Input
                                id="sender-name"
                                v-model="form.sender_name"
                                placeholder="UHAS QA Insight"
                            />
                            <InputError :message="form.errors.sender_name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="app-url"
                                >Web address used in email links</Label
                            >
                            <Input
                                id="app-url"
                                v-model="form.app_url"
                                type="url"
                                placeholder="https://qa.uhas.edu.gh"
                            />
                            <InputError :message="form.errors.app_url" />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        The web address is where staff open QA Insight. Links in
                        emails point there.
                    </p>
                </section>

                <section
                    class="space-y-3 border-t border-[#d5ece0] pt-5 dark:border-white/10"
                >
                    <div class="flex items-center gap-2">
                        <Mail class="size-5 text-[#007a2f]" />
                        <h2 class="text-lg font-semibold text-[#063f22]">
                            What to send
                        </h2>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Password reset emails are always sent when email is on.
                    </p>
                    <label
                        v-for="option in notificationOptions"
                        :key="option.key"
                        class="flex cursor-pointer gap-3 rounded-lg border border-[#d5ece0] p-3 hover:bg-[#edf9f2] dark:border-white/10"
                    >
                        <input
                            v-model="form[option.key]"
                            type="checkbox"
                            class="mt-0.5 size-4 accent-[#007a2f]"
                        />
                        <span>
                            <span
                                class="block text-sm font-medium text-[#063f22]"
                            >
                                {{ option.label }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ option.description }}
                            </span>
                        </span>
                    </label>
                </section>

                <section
                    class="flex flex-wrap items-center justify-between gap-3 border-t border-[#d5ece0] pt-5 dark:border-white/10"
                >
                    <label class="flex cursor-pointer items-center gap-3">
                        <input
                            v-model="form.enabled"
                            type="checkbox"
                            class="size-5 accent-[#007a2f]"
                        />
                        <span class="text-sm font-semibold text-[#063f22]">
                            Send emails through
                            {{ providerLabel(form.provider) }}
                        </span>
                    </label>
                    <Button
                        type="submit"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        :disabled="form.processing"
                    >
                        <Save /> Save settings
                    </Button>
                </section>
            </form>

            <div class="space-y-6">
                <form
                    class="space-y-4 rounded-xl border border-[#9fd8b8] bg-white p-5 shadow-sm"
                    @submit.prevent="sendTest"
                >
                    <div class="flex items-center gap-2">
                        <Send class="size-5 text-[#007a2f]" />
                        <h2 class="text-lg font-semibold text-[#063f22]">
                            Send a test email
                        </h2>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Uses the saved
                        {{ providerLabel(settings.provider) }} settings, even
                        while email is switched off.
                        <strong v-if="providerChanged || form.isDirty"
                            >Save your changes first.</strong
                        >
                    </p>
                    <div class="grid gap-2">
                        <Label for="test-recipient">Send to</Label>
                        <Input
                            id="test-recipient"
                            v-model="testForm.recipient"
                            type="email"
                            required
                        />
                        <InputError :message="testForm.errors.recipient" />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        class="border-[#007a2f]/30 text-[#007a2f]"
                        :disabled="
                            testForm.processing || !settings.isConfigured
                        "
                    >
                        <Send /> Send test email
                    </Button>
                    <div
                        v-if="settings.lastTest"
                        class="rounded-lg border p-3 text-sm"
                        :class="
                            settings.lastTest.succeeded
                                ? 'border-[#9fd8b8] bg-[#edf9f2] text-[#063f22]'
                                : 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200'
                        "
                    >
                        <div class="font-semibold">
                            {{
                                settings.lastTest.succeeded
                                    ? 'Last test worked'
                                    : 'Last test failed'
                            }}
                            <span class="font-normal opacity-80"
                                >· {{ lastTestedAt }}</span
                            >
                        </div>
                        <p class="mt-1 break-words">
                            {{ settings.lastTest.message }}
                        </p>
                    </div>
                </form>

                <section
                    v-if="form.provider === 'microsoft365'"
                    class="space-y-3 rounded-xl border border-[#9fd8b8] bg-white p-5 text-sm text-[#3c5059] shadow-sm"
                >
                    <h2 class="text-lg font-semibold text-[#063f22]">
                        One-time setup in Microsoft 365
                    </h2>
                    <ol class="list-decimal space-y-2 pl-5">
                        <li>
                            In the Azure portal, open
                            <strong
                                >Microsoft Entra ID → App registrations</strong
                            >
                            and create a registration, for example “QA Insight
                            Mail”.
                        </li>
                        <li>
                            Copy its <strong>Directory (tenant) ID</strong> and
                            <strong>Application (client) ID</strong> into this
                            page.
                        </li>
                        <li>
                            Under <strong>Certificates &amp; secrets</strong>,
                            add a client secret and paste its
                            <strong>Value</strong> here. Note when it expires.
                        </li>
                        <li>
                            Under <strong>API permissions</strong>, add
                            Microsoft Graph →
                            <strong>Application permissions → Mail.Send</strong
                            >, then <strong>Grant admin consent</strong>.
                        </li>
                        <li>
                            Use a shared mailbox such as qa-insight@uhas.edu.gh
                            as the sender. Ask your Exchange admin to limit the
                            app to that mailbox (an application access policy or
                            RBAC for Applications), so it cannot send as anyone
                            else.
                        </li>
                        <li>Save, send a test email, then switch email on.</li>
                    </ol>
                </section>

                <section
                    v-else
                    class="space-y-3 rounded-xl border border-[#9fd8b8] bg-white p-5 text-sm text-[#3c5059] shadow-sm"
                >
                    <h2 class="text-lg font-semibold text-[#063f22]">
                        One-time setup in Gmail
                    </h2>
                    <ol class="list-decimal space-y-2 pl-5">
                        <li>
                            Sign in to the Gmail account at
                            <strong>myaccount.google.com</strong> and open
                            <strong>Security</strong>.
                        </li>
                        <li>
                            Turn on <strong>2-Step Verification</strong>. App
                            passwords only appear once it is on.
                        </li>
                        <li>
                            Open <strong>App passwords</strong> (search for it
                            at the top of the page), create one named “QA
                            Insight”, and copy the 16-letter password.
                        </li>
                        <li>
                            Enter the Gmail address and paste the app password
                            here.
                        </li>
                        <li>Save, send a test email, then switch email on.</li>
                    </ol>
                    <p
                        class="rounded-md bg-[#fffaf1] px-3 py-2 text-xs text-[#8a5a12] dark:bg-amber-950/30 dark:text-amber-200"
                    >
                        A personal Gmail account can send to about 500
                        recipients a day. Above that, Google pauses sending for
                        up to 24 hours. Changing the Gmail password signs out
                        the app password, so you would need a new one.
                    </p>
                </section>
            </div>
        </div>
    </div>
</template>
