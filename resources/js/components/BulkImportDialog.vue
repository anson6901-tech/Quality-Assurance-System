<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Download, Upload } from 'lucide-vue-next';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    title: string;
    description: string;
    templateUrl: string;
    importUrl: string;
    columns: string;
}>();

const open = ref(false);
const form = useForm({ structure_file: null as File | null });

const submit = () => {
    form.post(props.importUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
};
</script>

<template>
    <Button
        type="button"
        variant="outline"
        class="border-[#007a2f]/30 bg-white text-[#007a2f] hover:bg-[#e3f6eb]"
        @click="open = true"
    >
        <Upload class="size-4" />
        Bulk add
    </Button>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <form class="space-y-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>{{ description }}</DialogDescription>
                </DialogHeader>
                <Button
                    as-child
                    type="button"
                    variant="outline"
                    class="w-full border-[#007a2f]/30 text-[#007a2f] hover:bg-[#e3f6eb]"
                >
                    <a :href="templateUrl" download>
                        <Download class="size-4" />
                        Download CSV template
                    </a>
                </Button>
                <p class="text-xs leading-5 text-muted-foreground">
                    Required columns: {{ columns }}
                </p>
                <div class="grid gap-2">
                    <Label>Completed CSV file</Label>
                    <Input
                        type="file"
                        accept=".csv,text/csv"
                        required
                        @input="
                            form.structure_file =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <InputError :message="form.errors.structure_file" />
                </div>
                <div class="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="open = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        :disabled="!form.structure_file || form.processing"
                    >
                        <Upload class="size-4" />
                        Import
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
