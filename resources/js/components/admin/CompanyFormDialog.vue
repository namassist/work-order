<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import CompanyController from '@/actions/App/Http/Controllers/Admin/CompanyController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { Company } from '@/types';

const props = defineProps<{
    company: Company | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
    code: '',
    name: '',
    type: 'client' as 'client' | 'executor',
    email_domains: '',
    is_active: true,
});

// The type decides which side of a work order the company's departments are
// on, so the server refuses to change it once the company has departments.
const typeLocked = computed(() => (props.company?.departments_count ?? 0) > 0);

// The server validates is_client and email_domains.N, which are not fields
// of this form (it holds `type` and one text for all domains).
const serverErrors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const domainErrors = computed(() =>
    Object.entries(serverErrors.value)
        .filter(([key]) => key.startsWith('email_domains'))
        .map(([, message]) => message),
);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({
        code: props.company?.code ?? '',
        name: props.company?.name ?? '',
        type: props.company && !props.company.is_client ? 'executor' : 'client',
        email_domains: props.company?.email_domains.join('\n') ?? '',
        is_active: props.company?.is_active ?? true,
    });
    form.reset();
});

const submit = () => {
    const action = props.company
        ? CompanyController.update(props.company.id)
        : CompanyController.store();

    form.transform(({ type, email_domains, ...rest }) => ({
        ...rest,
        is_client: type === 'client',
        email_domains: email_domains
            .split(/[\s,]+/)
            .filter((domain) => domain !== ''),
    })).submit(action, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{ company ? 'Ubah perusahaan' : 'Tambah perusahaan' }}
                    </DialogTitle>
                    <DialogDescription>
                        Perusahaan klien mengajukan work order; perusahaan
                        pelaksana mengerjakannya.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="company-code">Kode</Label>
                    <Input
                        id="company-code"
                        v-model="form.code"
                        class="font-mono uppercase"
                        maxlength="20"
                        required
                        autocomplete="off"
                        placeholder="IC"
                    />
                    <InputError :message="form.errors.code" />
                </div>

                <div class="grid gap-2">
                    <Label for="company-name">Nama</Label>
                    <Input
                        id="company-name"
                        v-model="form.name"
                        required
                        placeholder="PT Contoh Klien"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="company-type">Jenis</Label>
                    <Select v-model="form.type" :disabled="typeLocked">
                        <SelectTrigger id="company-type" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="client">
                                Klien (mengajukan WO)
                            </SelectItem>
                            <SelectItem value="executor">
                                Pelaksana (mengerjakan WO)
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="typeLocked" class="text-xs text-muted-foreground">
                        Jenis tidak dapat diubah karena perusahaan ini sudah
                        memiliki departemen.
                    </p>
                    <InputError :message="serverErrors.is_client" />
                </div>

                <div class="grid gap-2">
                    <Label for="company-domains">Domain email</Label>
                    <Textarea
                        id="company-domains"
                        v-model="form.email_domains"
                        class="font-mono"
                        rows="3"
                        placeholder="perusahaan.co.id"
                    />
                    <p class="text-xs text-muted-foreground">
                        Satu domain per baris. Dipakai untuk mencocokkan email
                        saat pendaftaran.
                    </p>
                    <InputError
                        v-for="message in domainErrors"
                        :key="message"
                        :message="message"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox id="company-active" v-model="form.is_active" />
                    <Label for="company-active">Aktif</Label>
                    <InputError :message="form.errors.is_active" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Batal</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="form.processing">
                        Simpan
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
