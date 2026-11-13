<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Braces,
    FileText,
    Paperclip,
    RotateCcw,
    Save,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

export type PublishedTemplate = {
    subject: string;
    body: string;
    attachPdf: boolean;
    defaultSubject: string;
    defaultBody: string;
    placeholders: { key: string; label: string }[];
};

const props = defineProps<{
    template: PublishedTemplate;
    senderName: string | null;
}>();

const form = useForm({
    published_email_subject: props.template.subject,
    published_email_body: props.template.body,
    published_attach_pdf: props.template.attachPdf,
});

const save = () =>
    form.put('/quality/email-settings/templates', { preserveScroll: true });

const restoreDefault = () => {
    form.published_email_subject = props.template.defaultSubject;
    form.published_email_body = props.template.defaultBody;
};

// Detail buttons insert a {placeholder} where the cursor is, in the subject or the message.
const subjectBox = ref<HTMLInputElement | null>(null);
const bodyBox = ref<HTMLTextAreaElement | null>(null);
const lastField = ref<'subject' | 'body'>('body');

const insert = async (key: string) => {
    const token = `{${key}}`;
    const field = lastField.value;
    const box = field === 'subject' ? subjectBox.value : bodyBox.value;
    const name =
        field === 'subject'
            ? 'published_email_subject'
            : 'published_email_body';
    const value = form[name];
    const start = box?.selectionStart ?? value.length;
    const end = box?.selectionEnd ?? value.length;

    form[name] = value.slice(0, start) + token + value.slice(end);
    await nextTick();
    box?.focus();
    box?.setSelectionRange(start + token.length, start + token.length);
};

// Preview with example details.
const sample: Record<string, string> = {
    lecturer_name: 'Dr Grace Appiah',
    report_title: 'SOPH132 Dispensing — Dr Grace Appiah',
    courses: 'SOPH132 Dispensing',
    academic_year: '2025/2026',
    semester: 'Semester 2',
    report_link: 'https://qa.uhas.edu.gh/quality/reports/123',
    portal_link: 'https://qa.uhas.edu.gh/quality/my-courses',
};
const fill = (text: string) =>
    text.replace(/\{(\w+)\}/g, (match, key: string) =>
        key === 'sender_name'
            ? props.senderName || 'UHAS Quality Assurance'
            : (sample[key] ?? match),
    );
const paragraphs = computed(() =>
    fill(form.published_email_body)
        .trim()
        .split(/\n\s*\n/)
        .map((paragraph) => paragraph.replace(/\s*\n\s*/g, ' ').trim())
        .filter(Boolean),
);
const unknownPlaceholders = computed(() => {
    const known = new Set(props.template.placeholders.map((item) => item.key));

    return [
        ...new Set(
            [
                ...`${form.published_email_subject} ${form.published_email_body}`.matchAll(
                    /\{(\w+)\}/g,
                ),
            ]
                .map((match) => match[1])
                .filter((key) => !known.has(key)),
        ),
    ];
});
const token = (key: string) => `{${key}}`;
const unknownText = computed(() =>
    unknownPlaceholders.value.map(token).join(', '),
);
const mentionsAttachment = computed(() =>
    /attach/i.test(form.published_email_body),
);
</script>

<template>
    <section
        class="rounded-xl border border-[#9fd8b8] bg-white shadow-sm"
        aria-labelledby="compose-emails-heading"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b border-[#d6e9de] px-5 py-4"
        >
            <div>
                <h2
                    id="compose-emails-heading"
                    class="text-lg font-semibold text-[#063f22]"
                >
                    Compose emails
                </h2>
                <p class="text-sm text-[#4b6b58]">
                    The email a lecturer receives when their report is
                    published. Words in curly brackets are filled in for each
                    report.
                </p>
            </div>
            <span
                class="rounded-full bg-[#dff1e6] px-3 py-1 text-xs font-medium text-[#0b5a2a]"
                >Report published · to the lecturer</span
            >
        </div>

        <form
            class="grid gap-6 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
            @submit.prevent="save"
        >
            <div class="space-y-4">
                <div class="grid gap-2">
                    <Label for="published-subject">Subject</Label>
                    <input
                        id="published-subject"
                        ref="subjectBox"
                        v-model="form.published_email_subject"
                        class="h-10 rounded-md border border-input bg-white px-3 text-sm shadow-xs"
                        maxlength="200"
                        required
                        @focus="lastField = 'subject'"
                    />
                    <InputError
                        :message="form.errors.published_email_subject"
                    />
                </div>

                <div class="grid gap-2">
                    <span
                        class="flex items-center gap-1.5 text-sm font-medium text-[#063f22]"
                    >
                        <Braces class="size-4 text-[#007a2f]" /> Insert a detail
                        <span class="font-normal text-[#4b6b58]"
                            >(into the
                            {{
                                lastField === 'subject' ? 'subject' : 'message'
                            }})</span
                        >
                    </span>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="item in template.placeholders"
                            :key="item.key"
                            type="button"
                            class="rounded-full border border-[#9fd8b8] bg-[#f4fbf7] px-2.5 py-1 text-xs text-[#063f22] hover:border-[#007a2f] hover:bg-[#dff1e6]"
                            :title="`Insert ${token(item.key)}`"
                            @mousedown.prevent
                            @click="insert(item.key)"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="published-body">Message</Label>
                    <textarea
                        id="published-body"
                        ref="bodyBox"
                        v-model="form.published_email_body"
                        rows="12"
                        maxlength="5000"
                        required
                        class="rounded-md border border-input bg-white px-3 py-2 font-mono text-sm leading-6 shadow-xs"
                        @focus="lastField = 'body'"
                    />
                    <p class="text-xs text-[#4b6b58]">
                        Leave a blank line between paragraphs. The first
                        paragraph is the greeting. A "View the report" button is
                        added after the message. Use **double stars** for bold.
                    </p>
                    <InputError :message="form.errors.published_email_body" />
                    <p
                        v-if="unknownPlaceholders.length"
                        class="flex items-center gap-1.5 text-xs text-[#8a5a12]"
                    >
                        <TriangleAlert class="size-4" />
                        Not a known detail, so it is sent as typed:
                        {{ unknownText }}
                    </p>
                </div>

                <label
                    class="flex cursor-pointer items-start gap-3 rounded-lg border p-3"
                    :class="
                        form.published_attach_pdf
                            ? 'border-[#007a2f] bg-[#f4fbf7]'
                            : 'border-[#d6e9de]'
                    "
                >
                    <input
                        v-model="form.published_attach_pdf"
                        type="checkbox"
                        class="mt-0.5 size-4 accent-[#007a2f]"
                    />
                    <span class="grid gap-0.5">
                        <span
                            class="flex items-center gap-1.5 font-medium text-[#063f22]"
                        >
                            <Paperclip class="size-4" /> Attach the report PDF
                        </span>
                        <span class="text-xs text-[#4b6b58]">
                            The published report is sent with the email. It is
                            always on the lecturer's portal as well.
                        </span>
                    </span>
                </label>
                <p
                    v-if="!form.published_attach_pdf && mentionsAttachment"
                    class="flex items-center gap-1.5 text-xs text-[#8a5a12]"
                >
                    <TriangleAlert class="size-4" />
                    The message mentions an attachment, but the PDF is switched
                    off.
                </p>

                <div class="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        class="bg-[#007a2f] hover:bg-[#006b30]"
                        :disabled="form.processing || !form.isDirty"
                    >
                        <Save class="size-4" /> Save email
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="restoreDefault"
                    >
                        <RotateCcw class="size-4" /> Use the standard wording
                    </Button>
                </div>
            </div>

            <div class="space-y-2">
                <p class="text-sm font-medium text-[#063f22]">
                    Preview
                    <span class="font-normal text-[#4b6b58]"
                        >(with example details)</span
                    >
                </p>
                <div
                    class="overflow-hidden rounded-lg border border-[#d6e9de] bg-[#f6f8f7] text-sm"
                >
                    <div class="border-b border-[#d6e9de] bg-white px-4 py-3">
                        <p class="text-xs text-[#66808a]">Subject</p>
                        <p class="font-semibold text-[#1d2f37]">
                            {{ fill(form.published_email_subject) }}
                        </p>
                    </div>
                    <div
                        class="m-4 space-y-3 rounded-md bg-white p-5 shadow-xs"
                    >
                        <p class="text-base font-bold text-[#1d2f37]">
                            {{ paragraphs[0] }}
                        </p>
                        <p
                            v-for="(paragraph, index) in paragraphs.slice(1)"
                            :key="index"
                            class="leading-6 break-words text-[#3c5059]"
                        >
                            {{ paragraph }}
                        </p>
                        <p class="py-2 text-center">
                            <span
                                class="inline-block rounded-md bg-[#2d3748] px-4 py-2 text-xs font-semibold text-white"
                                >View the report</span
                            >
                        </p>
                        <p class="text-[#3c5059]">
                            Regards,<br />{{
                                senderName || 'UHAS Quality Assurance'
                            }}
                        </p>
                    </div>
                    <div
                        v-if="form.published_attach_pdf"
                        class="flex items-center gap-2 border-t border-[#d6e9de] bg-white px-4 py-3 text-xs text-[#3c5059]"
                    >
                        <FileText class="size-4 text-[#b42318]" />
                        soph132-dispensing-dr-grace-appiah.pdf
                        <span class="text-[#66808a]">(attached)</span>
                    </div>
                </div>
            </div>
        </form>
    </section>
</template>
