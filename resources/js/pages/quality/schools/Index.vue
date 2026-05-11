<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type SchoolRow = {
    id: number;
    name: string;
    code: string;
    dean?: { id: number; name: string; email: string } | null;
    departments_count: number;
    users_count: number;
    quality_reports_count: number;
};

defineProps<{
    schools: SchoolRow[];
    deans: {
        id: number;
        name: string;
        email: string;
        school_id: number | null;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Schools',
                href: '/quality/schools',
            },
        ],
    },
});

const form = useForm({
    name: '',
    code: '',
    dean_id: '',
});

const submit = () => {
    form.post('/quality/schools', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Schools" />

    <div class="grid gap-8 xl:grid-cols-[1fr_360px]">
        <section class="space-y-6">
            <Heading
                variant="small"
                title="Schools"
                description="University schools used to scope dean access and QA reports"
            />

            <div class="overflow-hidden rounded-lg border">
                <div
                    class="grid grid-cols-[1fr_1fr_100px_100px_100px] gap-4 border-b bg-muted/50 px-4 py-3 text-sm font-medium"
                >
                    <span>School</span>
                    <span>Dean</span>
                    <span>Departments</span>
                    <span>Users</span>
                    <span>Reports</span>
                </div>
                <div
                    v-for="school in schools"
                    :key="school.id"
                    class="grid grid-cols-[1fr_1fr_100px_100px_100px] gap-4 border-b px-4 py-4 text-sm last:border-b-0"
                >
                    <div>
                        <div class="font-medium">{{ school.name }}</div>
                        <div class="text-muted-foreground">
                            {{ school.code }}
                        </div>
                    </div>
                    <div>
                        <div class="font-medium">
                            {{ school.dean?.name ?? 'Not assigned' }}
                        </div>
                        <div class="text-muted-foreground">
                            {{ school.dean?.email }}
                        </div>
                    </div>
                    <span>{{ school.departments_count }}</span>
                    <span>{{ school.users_count }}</span>
                    <span>{{ school.quality_reports_count }}</span>
                </div>
            </div>
        </section>

        <form class="space-y-4 rounded-lg border p-4" @submit.prevent="submit">
            <Heading variant="small" title="Add school" />

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" required />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="code">Code</Label>
                <Input id="code" v-model="form.code" required />
                <InputError :message="form.errors.code" />
            </div>

            <div class="grid gap-2">
                <Label for="dean">Dean</Label>
                <select
                    id="dean"
                    v-model="form.dean_id"
                    class="h-9 rounded-md border bg-background px-3 text-sm"
                >
                    <option value="">Assign later</option>
                    <option
                        v-for="dean in deans"
                        :key="dean.id"
                        :value="dean.id"
                    >
                        {{ dean.name }} - {{ dean.email }}
                    </option>
                </select>
                <InputError :message="form.errors.dean_id" />
            </div>

            <Button type="submit" :disabled="form.processing">
                <Plus /> Add school
            </Button>
        </form>
    </div>
</template>
