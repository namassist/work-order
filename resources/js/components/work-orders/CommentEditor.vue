<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    Bold,
    Code,
    ImagePlus,
    Italic,
    Link2,
    List,
    ListOrdered,
    Paperclip,
    Quote,
    Strikethrough,
    X,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type { Editor } from '@tiptap/core';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import WorkOrderCommentUploadController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderCommentUploadController';
import AttachmentRow from '@/components/attachments/AttachmentRow.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { attachmentProblem } from '@/lib/attachments';
import { commentExtensions, normalizeLink } from '@/lib/commentEditor';
import type { Attachment, WorkOrderCommentSettings } from '@/types';

/**
 * The rich-text comment editor (Tiptap, FLOW.md §9). v-model is the
 * editor's HTML ('' when empty), v-model:documents the uploaded documents,
 * v-model:busy true while files upload. Images and documents upload at
 * once, as the user's pending uploads on the work order; posting the
 * comment claims them. The server sanitizes the HTML and has the final say
 * on every file.
 */
const props = defineProps<{
    workOrderId: number;
    settings: WorkOrderCommentSettings;
    inputId: string;
    label: string;
    invalid?: boolean;
}>();

const html = defineModel<string>({ required: true });
const documents = defineModel<Attachment[]>('documents', {
    default: () => [],
});
const busy = defineModel<boolean>('busy', { default: false });

type UploadKind = 'gambar' | 'lampiran';
type Uploaded = Attachment & { url: string };

const http = useHttp<{ file: File | null }, Uploaded>({ file: null });
const queue = ref<{ kind: UploadKind; file: File }[]>([]);
const uploading = ref<{ kind: UploadKind; name: string } | null>(null);
const problems = ref<string[]>([]);
const imageCount = ref(0);
const characters = ref(0);

const countImages = (editor: Editor): number => {
    let count = 0;
    editor.state.doc.descendants((node) => {
        count += node.type.name === 'image' ? 1 : 0;
    });

    return count;
};

const sync = (editor: Editor) => {
    imageCount.value = countImages(editor);
    characters.value = editor.storage.characterCount.characters();
};

const editor = useEditor({
    content: html.value,
    extensions: commentExtensions({ placeholder: 'Tulis komentar…' }),
    editorProps: {
        attributes: {
            id: props.inputId,
            role: 'textbox',
            'aria-multiline': 'true',
            'aria-label': props.label,
            class: 'comment-body min-h-24 px-3 py-2 text-base outline-none md:text-sm',
        },
        handlePaste: (_view, event) => takeImages(event.clipboardData?.files),
        handleDrop: (_view, event) =>
            takeImages((event as DragEvent).dataTransfer?.files),
        handleKeyDown: (_view, event) => {
            if (
                (event.ctrlKey || event.metaKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                openLink();

                return true;
            }

            return false;
        },
    },
    onCreate: ({ editor }) => sync(editor),
    onUpdate: ({ editor }) => {
        sync(editor);
        html.value = editor.isEmpty ? '' : editor.getHTML();
    },
});

// A reset from outside (after posting) empties the editor.
watch(html, (value) => {
    const current = editor.value;

    if (!current || value === (current.isEmpty ? '' : current.getHTML())) {
        return;
    }

    current.commands.setContent(value, { emitUpdate: false });
    sync(current);
});

watch(
    () => queue.value.length > 0 || uploading.value !== null,
    (value) => (busy.value = value),
);

onBeforeUnmount(() => http.cancel());

/**
 * Files that can still be added to this comment, with a reason for each
 * one that cannot.
 */
const accept = (kind: UploadKind, files: File[]): File[] => {
    const rules = props.settings.uploads[kind];
    const limit =
        kind === 'gambar'
            ? props.settings.max_images
            : props.settings.max_documents;
    // Files waiting or in flight count too: they are not in the comment yet.
    const queued = queue.value.filter((item) => item.kind === kind).length;
    const inFlight = uploading.value?.kind === kind ? 1 : 0;
    let room =
        limit -
        queued -
        inFlight -
        (kind === 'gambar' ? imageCount.value : documents.value.length);
    const accepted: File[] = [];

    for (const file of files) {
        const problem = attachmentProblem(file, rules);

        if (problem) {
            problems.value.push(problem);
        } else if (room <= 0) {
            problems.value.push(
                `${file.name}: maksimal ${limit} ${kind === 'gambar' ? 'gambar' : 'lampiran'} per komentar.`,
            );
        } else {
            accepted.push(file);
            room--;
        }
    }

    return accepted;
};

const enqueue = (kind: UploadKind, files: File[]) => {
    problems.value = [];
    queue.value = [
        ...queue.value,
        ...accept(kind, files).map((file) => ({ kind, file })),
    ];

    if (uploading.value === null) {
        uploadNext();
    }
};

const uploadNext = async () => {
    const [next, ...rest] = queue.value;
    queue.value = rest;

    if (!next) {
        uploading.value = null;

        return;
    }

    uploading.value = { kind: next.kind, name: next.file.name };
    http.file = next.file;

    try {
        const uploaded = await http.post(
            WorkOrderCommentUploadController.store.url([
                props.workOrderId,
                next.kind,
            ]),
        );

        if (next.kind === 'gambar') {
            editor.value
                ?.chain()
                .focus()
                .setImage({ src: uploaded.url, alt: uploaded.name })
                .run();
        } else {
            documents.value = [...documents.value, uploaded];
        }
    } catch {
        problems.value.push(
            `${next.file.name}: ${http.errors.file ?? 'gagal diunggah.'}`,
        );
    } finally {
        http.file = null;
        uploadNext();
    }
};

/** Pasted or dropped image files upload; anything else is the editor's. */
const takeImages = (files: FileList | null | undefined): boolean => {
    const images = Array.from(files ?? []).filter((file) =>
        file.type.startsWith('image/'),
    );

    if (images.length === 0) {
        return false;
    }

    enqueue('gambar', images);

    return true;
};

const imageInput = ref<HTMLInputElement | null>(null);
const documentInput = ref<HTMLInputElement | null>(null);

const onPick = (kind: UploadKind, event: Event) => {
    const input = event.target as HTMLInputElement;
    enqueue(kind, Array.from(input.files ?? []));
    input.value = '';
};

const removeDocument = (id: string) => {
    documents.value = documents.value.filter((document) => document.id !== id);
};

const linkOpen = ref(false);
const linkInput = ref('');
const linkError = ref<string | null>(null);

const openLink = () => {
    linkInput.value = editor.value?.getAttributes('link').href ?? '';
    linkError.value = null;
    linkOpen.value = true;
};

const applyLink = () => {
    const current = editor.value;
    const href = normalizeLink(linkInput.value);

    if (!current) {
        return;
    }

    if (href === null) {
        linkError.value = 'Gunakan tautan http, https, atau email.';

        return;
    }

    if (current.state.selection.empty && !current.isActive('link')) {
        current
            .chain()
            .focus()
            .insertContent({
                type: 'text',
                text: linkInput.value.trim(),
                marks: [{ type: 'link', attrs: { href } }],
            })
            .run();
    } else {
        current.chain().focus().extendMarkRange('link').setLink({ href }).run();
    }

    linkOpen.value = false;
};

const removeLink = () => {
    editor.value?.chain().focus().extendMarkRange('link').unsetLink().run();
    linkOpen.value = false;
};

type Tool = {
    label: string;
    shortcut: string;
    icon: LucideIcon;
    active: (editor: Editor) => boolean;
    run: (editor: Editor) => void;
};

const TOOLS: Tool[][] = [
    [
        {
            label: 'Tebal',
            shortcut: 'Ctrl+B',
            icon: Bold,
            active: (e) => e.isActive('bold'),
            run: (e) => e.chain().focus().toggleBold().run(),
        },
        {
            label: 'Miring',
            shortcut: 'Ctrl+I',
            icon: Italic,
            active: (e) => e.isActive('italic'),
            run: (e) => e.chain().focus().toggleItalic().run(),
        },
        {
            label: 'Coret',
            shortcut: 'Ctrl+Shift+S',
            icon: Strikethrough,
            active: (e) => e.isActive('strike'),
            run: (e) => e.chain().focus().toggleStrike().run(),
        },
        {
            label: 'Kode',
            shortcut: 'Ctrl+E',
            icon: Code,
            active: (e) => e.isActive('code'),
            run: (e) => e.chain().focus().toggleCode().run(),
        },
    ],
    [
        {
            label: 'Daftar berpoin',
            shortcut: 'Ctrl+Shift+8',
            icon: List,
            active: (e) => e.isActive('bulletList'),
            run: (e) => e.chain().focus().toggleBulletList().run(),
        },
        {
            label: 'Daftar bernomor',
            shortcut: 'Ctrl+Shift+7',
            icon: ListOrdered,
            active: (e) => e.isActive('orderedList'),
            run: (e) => e.chain().focus().toggleOrderedList().run(),
        },
        {
            label: 'Kutipan',
            shortcut: 'Ctrl+Shift+B',
            icon: Quote,
            active: (e) => e.isActive('blockquote'),
            run: (e) => e.chain().focus().toggleBlockquote().run(),
        },
    ],
];

const overLimit = computed(() => characters.value > props.settings.max_length);
</script>

<template>
    <div class="grid gap-2">
        <div
            class="rounded-md border border-input shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50 dark:bg-input/30"
            :class="{ 'border-destructive': invalid || overLimit }"
        >
            <div
                v-if="editor"
                role="toolbar"
                :aria-label="`Format ${label.toLowerCase()}`"
                :aria-controls="inputId"
                class="flex flex-wrap items-center gap-0.5 border-b px-1 py-1"
            >
                <template v-for="(group, index) in TOOLS" :key="index">
                    <Button
                        v-for="tool in group"
                        :key="tool.label"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-8"
                        :class="
                            tool.active(editor)
                                ? 'bg-muted text-foreground'
                                : 'text-muted-foreground'
                        "
                        :aria-label="`${tool.label} (${tool.shortcut})`"
                        :title="`${tool.label} (${tool.shortcut})`"
                        :aria-pressed="tool.active(editor)"
                        @click="tool.run(editor)"
                    >
                        <component :is="tool.icon" />
                    </Button>
                    <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
                </template>

                <Popover v-model:open="linkOpen">
                    <PopoverTrigger as-child>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            :class="
                                editor.isActive('link')
                                    ? 'bg-muted text-foreground'
                                    : 'text-muted-foreground'
                            "
                            aria-label="Tautan (Ctrl+K)"
                            title="Tautan (Ctrl+K)"
                            :aria-pressed="editor.isActive('link')"
                            @click.prevent="openLink"
                        >
                            <Link2 />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent class="w-72" align="start">
                        <form class="grid gap-2" @submit.prevent="applyLink">
                            <label
                                :for="`${inputId}-link`"
                                class="text-sm font-medium"
                            >
                                Tautan
                            </label>
                            <Input
                                :id="`${inputId}-link`"
                                v-model="linkInput"
                                inputmode="url"
                                placeholder="https://…"
                                autocomplete="off"
                            />
                            <InputError :message="linkError ?? undefined" />
                            <div class="flex justify-end gap-2">
                                <Button
                                    v-if="editor.isActive('link')"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="removeLink"
                                >
                                    Hapus tautan
                                </Button>
                                <Button type="submit" size="sm">
                                    Terapkan
                                </Button>
                            </div>
                        </form>
                    </PopoverContent>
                </Popover>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-8 text-muted-foreground"
                    aria-label="Sisipkan gambar"
                    title="Sisipkan gambar"
                    @click="imageInput?.click()"
                >
                    <ImagePlus />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-8 text-muted-foreground"
                    aria-label="Lampirkan dokumen"
                    title="Lampirkan dokumen"
                    @click="documentInput?.click()"
                >
                    <Paperclip />
                </Button>
                <input
                    ref="imageInput"
                    type="file"
                    class="hidden"
                    multiple
                    :accept="settings.uploads.gambar.accept"
                    @change="onPick('gambar', $event)"
                />
                <input
                    ref="documentInput"
                    type="file"
                    class="hidden"
                    multiple
                    :accept="settings.uploads.lampiran.accept"
                    @change="onPick('lampiran', $event)"
                />
            </div>
            <EditorContent :editor="editor" />
        </div>

        <p
            v-if="uploading"
            class="text-xs text-muted-foreground"
            role="status"
            aria-live="polite"
        >
            Mengunggah {{ uploading.name }}…
        </p>
        <div v-if="problems.length" aria-live="polite">
            <InputError
                v-for="problem in problems"
                :key="problem"
                :message="problem"
            />
        </div>

        <ul v-if="documents.length" class="divide-y rounded-lg border">
            <AttachmentRow
                v-for="document in documents"
                :key="document.id"
                :attachment="document"
            >
                <template #actions>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Lepas ${document.name}`"
                        @click="removeDocument(document.id)"
                    >
                        <X />
                    </Button>
                </template>
            </AttachmentRow>
        </ul>

        <p
            class="text-xs tabular-nums"
            :class="overLimit ? 'text-destructive' : 'text-muted-foreground'"
        >
            {{ characters }}/{{ settings.max_length }}
        </p>
    </div>
</template>
