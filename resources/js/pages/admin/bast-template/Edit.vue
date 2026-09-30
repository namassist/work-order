<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Eye, FileSignature, Send } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BastTemplateController from '@/actions/App/Http/Controllers/Admin/BastTemplateController';
import BastTemplateEditor from '@/components/admin/BastTemplateEditor.vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import AttachmentPanel from '@/components/attachments/AttachmentPanel.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormFooter from '@/components/FormFooter.vue';
import InputError from '@/components/InputError.vue';
import PagePanel from '@/components/PagePanel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFormatDate } from '@/composables/useFormatDate';
import { panelTableClass } from '@/lib/panel';
import type {
    AttachmentPanelData,
    BastPlaceholderOption,
    BastPreviewWorkOrder,
    BastTemplateDraft,
    BastTemplateVersion,
} from '@/types';

/**
 * The BAST template (FLOW.md §9): one template whose draft is edited here,
 * previewed with a real work order, and published as an immutable version.
 * Exactly one version is active; an older one can be activated again.
 */
const props = defineProps<{
    template: BastTemplateDraft;
    versions: BastTemplateVersion[];
    images: AttachmentPanelData;
    placeholders: BastPlaceholderOption[];
    previewWorkOrders: BastPreviewWorkOrder[];
    maxHtmlBytes: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administrasi' },
            {
                title: 'Template BAST',
                href: BastTemplateController.edit(),
            },
        ],
    },
});

const { formatDateTime } = useFormatDate();

const form = useForm({ html: props.template.draft_html });

/** Continue from the server's draft: sanitized, so what was dropped is gone. */
const syncWithSavedDraft = () => {
    form.html = props.template.draft_html;
    form.defaults({ html: props.template.draft_html });
};

// A publish or another tab's save changes the stored draft too.
watch(() => props.template.draft_html, syncWithSavedDraft);

const unsaved = computed(() => form.html !== props.template.draft_html);
const activeVersion = computed(() =>
    props.versions.find((version) => version.is_active),
);
const nextVersion = computed(() => (props.versions[0]?.version ?? 0) + 1);
const exampleToken = '{{nomor_bast}}';

const save = () =>
    form.put(BastTemplateController.update.url(), {
        preserveScroll: true,
        preserveState: true,
        // Also when sanitizing left the stored draft as it was.
        onSuccess: syncWithSavedDraft,
    });

const publishOpen = ref(false);
const publishing = ref(false);

const publish = () =>
    router.post(
        BastTemplateController.publish.url(),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (publishing.value = true),
            onFinish: () => {
                publishing.value = false;
                publishOpen.value = false;
            },
            onError: (errors) => form.setError('html', errors.html ?? ''),
        },
    );

const toActivate = ref<BastTemplateVersion | null>(null);
const activateOpen = ref(false);
const activating = ref(false);

const askActivate = (version: BastTemplateVersion) => {
    toActivate.value = version;
    activateOpen.value = true;
};

const activate = () => {
    if (!toActivate.value) {
        return;
    }

    router.post(
        BastTemplateController.activate.url(toActivate.value.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (activating.value = true),
            onFinish: () => {
                activating.value = false;
                activateOpen.value = false;
            },
        },
    );
};

const previewWorkOrder = ref<string>(
    props.previewWorkOrders[0] ? String(props.previewWorkOrders[0].id) : '',
);

const previewUrl = (version?: BastTemplateVersion): string =>
    BastTemplateController.preview.url({
        query: {
            work_order: previewWorkOrder.value,
            ...(version ? { version: version.id } : {}),
        },
    });
</script>

<template>
    <Head title="Template BAST" />

    <PagePanel title="Template BAST">
        <template #meta>
            <span v-if="activeVersion">
                Versi aktif: {{ activeVersion.version }}
            </span>
            <span v-else>Belum ada versi yang aktif</span>
            <span v-if="template.draft_updated_at">
                · Draf disimpan
                {{ formatDateTime(template.draft_updated_at) }}
                <template v-if="template.draft_editor">
                    oleh {{ template.draft_editor }}
                </template>
            </span>
        </template>

        <form @submit.prevent="save">
            <section
                class="px-4 py-6 sm:px-6"
                aria-labelledby="bast-draft-heading"
            >
                <div class="mb-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                    <h2 id="bast-draft-heading" class="font-medium">Draf</h2>
                    <Badge
                        v-if="!template.is_published || unsaved"
                        variant="secondary"
                    >
                        {{ unsaved ? 'Belum disimpan' : 'Belum diterbitkan' }}
                    </Badge>
                </div>
                <p class="mb-4 text-sm text-muted-foreground">
                    Placeholder seperti
                    <code class="font-mono">{{ exampleToken }}</code>
                    diisi dari data work order saat BAST dibuat, sebagai teks
                    biasa. Perubahan berlaku untuk BAST berikutnya setelah
                    diterbitkan; BAST yang sudah dibuat tidak berubah.
                </p>
                <BastTemplateEditor
                    v-model="form.html"
                    :images="images.items"
                    :placeholders="placeholders"
                    input-id="bast-template-html"
                    label="Isi template BAST"
                    :invalid="Boolean(form.errors.html)"
                />
                <InputError class="mt-2" :message="form.errors.html" />
            </section>

            <FormFooter>
                <p v-if="unsaved" class="mr-auto text-sm text-muted-foreground">
                    Simpan draf sebelum pratinjau atau terbitkan.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="
                        unsaved || template.is_published || !template.draft_html
                    "
                    @click="publishOpen = true"
                >
                    <Send />
                    Terbitkan
                </Button>
                <Button type="submit" :disabled="form.processing || !unsaved">
                    Simpan draf
                </Button>
            </FormFooter>
        </form>

        <section
            class="border-b px-4 py-6 sm:px-6"
            aria-labelledby="bast-preview-heading"
        >
            <h2 id="bast-preview-heading" class="mb-1 font-medium">
                Pratinjau
            </h2>
            <p class="mb-4 text-sm text-muted-foreground">
                PDF dari draf tersimpan, diisi data work order pilihan dan
                ditandai PRATINJAU.
            </p>
            <div
                v-if="previewWorkOrders.length > 0"
                class="flex flex-wrap items-end gap-2"
            >
                <div class="grid w-full gap-2 sm:w-96">
                    <Label for="bast-preview-work-order">Work order</Label>
                    <Select v-model="previewWorkOrder">
                        <SelectTrigger
                            id="bast-preview-work-order"
                            class="w-full overflow-hidden"
                        >
                            <SelectValue
                                class="min-w-0 truncate"
                                placeholder="Pilih work order"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="workOrder in previewWorkOrders"
                                :key="workOrder.id"
                                :value="String(workOrder.id)"
                            >
                                <span class="truncate">
                                    <span class="font-mono">{{
                                        workOrder.number
                                    }}</span>
                                    · {{ workOrder.title }}
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <Button
                    v-if="previewWorkOrder && template.draft_html && !unsaved"
                    variant="outline"
                    as-child
                >
                    <a :href="previewUrl()" target="_blank" rel="noopener">
                        <Eye />
                        Pratinjau draf
                    </a>
                </Button>
                <Button
                    v-else
                    variant="outline"
                    disabled
                    :title="unsaved ? 'Simpan draf lebih dulu' : undefined"
                >
                    <Eye />
                    Pratinjau draf
                </Button>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                Belum ada work order yang diajukan dan dapat Anda lihat.
                Pratinjau memerlukan izin melihat work order.
            </p>
        </section>

        <section
            class="border-b px-4 py-6 sm:px-6"
            aria-labelledby="bast-images-heading"
        >
            <h2 id="bast-images-heading" class="mb-1 font-medium">Gambar</h2>
            <p class="mb-4 text-sm text-muted-foreground">
                Kop surat dan gambar lain. Sisipkan ke draf lewat tombol gambar
                di editor. Versi yang sudah terbit menyimpan salinannya sendiri.
            </p>
            <AttachmentPanel
                :rules="images.rules"
                :target="images.target"
                :items="images.items"
                :can-upload="images.can.upload"
                :can-delete="images.can.delete"
                preserve-state
            />
        </section>

        <section class="py-6" aria-labelledby="bast-versions-heading">
            <h2
                id="bast-versions-heading"
                class="mb-4 px-4 font-medium sm:px-6"
            >
                Versi terbit
            </h2>
            <Table :class="panelTableClass">
                <TableHeader>
                    <TableRow>
                        <TableHead>Versi</TableHead>
                        <TableHead class="hidden sm:table-cell">
                            Diterbitkan
                        </TableHead>
                        <TableHead class="hidden md:table-cell">
                            Oleh
                        </TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="text-right">
                            <span class="sr-only">Aksi</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="version in versions" :key="version.id">
                        <TableCell class="font-medium tabular-nums">
                            {{ version.version }}
                        </TableCell>
                        <TableCell class="hidden sm:table-cell">
                            {{ formatDateTime(version.published_at) }}
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            {{ version.publisher ?? 'Sistem (bawaan)' }}
                        </TableCell>
                        <TableCell>
                            <StatusBadge :is-active="version.is_active" />
                        </TableCell>
                        <TableCell class="text-right whitespace-nowrap">
                            <Button
                                v-if="previewWorkOrder"
                                variant="ghost"
                                size="sm"
                                as-child
                            >
                                <a
                                    :href="previewUrl(version)"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    Pratinjau
                                </a>
                            </Button>
                            <Button
                                v-if="!version.is_active"
                                variant="outline"
                                size="sm"
                                @click="askActivate(version)"
                            >
                                Aktifkan
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="versions.length === 0" :colspan="5">
                        <EmptyState
                            :icon="FileSignature"
                            title="Belum ada versi terbit"
                            description="Simpan draf lalu terbitkan untuk membuat versi pertama."
                        />
                    </TableEmpty>
                </TableBody>
            </Table>
        </section>
    </PagePanel>

    <ConfirmDialog
        v-model:open="publishOpen"
        title="Terbitkan template BAST?"
        :description="`Draf tersimpan menjadi versi ${nextVersion} dan langsung aktif untuk BAST berikutnya. Versi terbit tidak dapat diubah; BAST yang sudah dibuat tetap memakai versinya.`"
        confirm-label="Terbitkan"
        :processing="publishing"
        @confirm="publish"
    />

    <ConfirmDialog
        v-model:open="activateOpen"
        :title="`Aktifkan versi ${toActivate?.version}?`"
        description="BAST berikutnya dibuat dari versi ini. BAST yang sudah dibuat tetap memakai versinya."
        confirm-label="Aktifkan"
        :processing="activating"
        @confirm="activate"
    />
</template>
