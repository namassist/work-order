<script setup lang="ts">
import {
    AlignCenter,
    AlignJustify,
    AlignLeft,
    AlignRight,
    BetweenHorizontalEnd,
    BetweenVerticalEnd,
    Bold,
    Braces,
    Columns3,
    Heading1,
    Heading2,
    Heading3,
    ImagePlus,
    Italic,
    List,
    ListOrdered,
    Minus,
    Pilcrow,
    Rows3,
    Strikethrough,
    Table,
    Trash2,
    Underline,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type { Editor } from '@tiptap/core';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    bastTemplateExtensions,
    templateImageSource,
} from '@/lib/bastTemplateEditor';
import type { Attachment, BastPlaceholderOption } from '@/types';

/**
 * The BAST template editor (Tiptap, FLOW.md §9). v-model is the editor's
 * HTML. Placeholders are inserted as plain text from the picker, and images
 * from the template's uploads (managed in the Gambar section). The server
 * sanitizes every save and refuses unknown or misplaced placeholders.
 */
const props = defineProps<{
    images: Attachment[];
    placeholders: BastPlaceholderOption[];
    inputId: string;
    label: string;
    invalid?: boolean;
}>();

const html = defineModel<string>({ required: true });

const editor = useEditor({
    content: html.value,
    extensions: bastTemplateExtensions(),
    editorProps: {
        attributes: {
            id: props.inputId,
            role: 'textbox',
            'aria-multiline': 'true',
            'aria-label': props.label,
            class: 'bast-template min-h-96 px-4 py-3 text-sm outline-none',
        },
    },
    onUpdate: ({ editor }) => {
        html.value = editor.isEmpty ? '' : editor.getHTML();
    },
});

// A change from outside (the saved draft after a reload) replaces the content.
watch(html, (value) => {
    const current = editor.value;

    if (!current || value === (current.isEmpty ? '' : current.getHTML())) {
        return;
    }

    current.commands.setContent(value, { emitUpdate: false });
});

const insertPlaceholder = (placeholder: BastPlaceholderOption) => {
    const chain = editor.value?.chain().focus();

    if (!chain) {
        return;
    }

    // A block placeholder is a paragraph of its own (the server checks it).
    if (placeholder.block) {
        chain
            .insertContent({
                type: 'paragraph',
                content: [{ type: 'text', text: placeholder.token }],
            })
            .run();
    } else {
        chain.insertContent({ type: 'text', text: placeholder.token }).run();
    }
};

const insertImage = (image: Attachment) => {
    editor.value
        ?.chain()
        .focus()
        .setImage({ src: templateImageSource(image.id), alt: image.name })
        .run();
};

type Tool = {
    label: string;
    shortcut?: string;
    icon: LucideIcon;
    active: (editor: Editor) => boolean;
    run: (editor: Editor) => void;
};

const title = (tool: Tool): string =>
    tool.shortcut ? `${tool.label} (${tool.shortcut})` : tool.label;

const HEADINGS = [Heading1, Heading2, Heading3];

const TOOLS: Tool[][] = [
    [
        {
            label: 'Paragraf',
            shortcut: 'Ctrl+Alt+0',
            icon: Pilcrow,
            active: (e) => e.isActive('paragraph'),
            run: (e) => e.chain().focus().setParagraph().run(),
        },
        ...([1, 2, 3] as const).map((level): Tool => ({
            label: `Judul ${level}`,
            shortcut: `Ctrl+Alt+${level}`,
            icon: HEADINGS[level - 1],
            active: (e) => e.isActive('heading', { level }),
            run: (e) => e.chain().focus().toggleHeading({ level }).run(),
        })),
    ],
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
            label: 'Garis bawah',
            shortcut: 'Ctrl+U',
            icon: Underline,
            active: (e) => e.isActive('underline'),
            run: (e) => e.chain().focus().toggleUnderline().run(),
        },
        {
            label: 'Coret',
            shortcut: 'Ctrl+Shift+S',
            icon: Strikethrough,
            active: (e) => e.isActive('strike'),
            run: (e) => e.chain().focus().toggleStrike().run(),
        },
    ],
    [
        {
            label: 'Rata kiri',
            shortcut: 'Ctrl+Shift+L',
            icon: AlignLeft,
            active: (e) => e.isActive({ textAlign: 'left' }),
            run: (e) => e.chain().focus().setTextAlign('left').run(),
        },
        {
            label: 'Rata tengah',
            shortcut: 'Ctrl+Shift+E',
            icon: AlignCenter,
            active: (e) => e.isActive({ textAlign: 'center' }),
            run: (e) => e.chain().focus().setTextAlign('center').run(),
        },
        {
            label: 'Rata kanan',
            shortcut: 'Ctrl+Shift+R',
            icon: AlignRight,
            active: (e) => e.isActive({ textAlign: 'right' }),
            run: (e) => e.chain().focus().setTextAlign('right').run(),
        },
        {
            label: 'Rata kiri kanan',
            shortcut: 'Ctrl+Shift+J',
            icon: AlignJustify,
            active: (e) => e.isActive({ textAlign: 'justify' }),
            run: (e) => e.chain().focus().setTextAlign('justify').run(),
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
            label: 'Garis pemisah',
            icon: Minus,
            active: () => false,
            run: (e) => e.chain().focus().setHorizontalRule().run(),
        },
    ],
];

const TABLE_TOOLS: Tool[] = [
    {
        label: 'Tambah baris',
        icon: BetweenHorizontalEnd,
        active: () => false,
        run: (e) => e.chain().focus().addRowAfter().run(),
    },
    {
        label: 'Tambah kolom',
        icon: BetweenVerticalEnd,
        active: () => false,
        run: (e) => e.chain().focus().addColumnAfter().run(),
    },
    {
        label: 'Hapus baris',
        icon: Rows3,
        active: () => false,
        run: (e) => e.chain().focus().deleteRow().run(),
    },
    {
        label: 'Hapus kolom',
        icon: Columns3,
        active: () => false,
        run: (e) => e.chain().focus().deleteColumn().run(),
    },
    {
        label: 'Hapus tabel',
        icon: Trash2,
        active: () => false,
        run: (e) => e.chain().focus().deleteTable().run(),
    },
];

const insertTable = () =>
    editor.value
        ?.chain()
        .focus()
        .insertTable({ rows: 3, cols: 2, withHeaderRow: true })
        .run();
</script>

<template>
    <div
        class="rounded-md border border-input shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50 dark:bg-input/30"
        :class="{ 'border-destructive': invalid }"
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
                    :aria-label="title(tool)"
                    :title="title(tool)"
                    :aria-pressed="tool.active(editor)"
                    @click="tool.run(editor)"
                >
                    <component :is="tool.icon" />
                </Button>
                <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
            </template>

            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-8 text-muted-foreground"
                aria-label="Sisipkan tabel"
                title="Sisipkan tabel"
                :disabled="editor.isActive('table')"
                @click="insertTable"
            >
                <Table />
            </Button>
            <template v-if="editor.isActive('table')">
                <Button
                    v-for="tool in TABLE_TOOLS"
                    :key="tool.label"
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-8 text-muted-foreground"
                    :aria-label="tool.label"
                    :title="tool.label"
                    @click="tool.run(editor)"
                >
                    <component :is="tool.icon" />
                </Button>
            </template>
            <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-8 text-muted-foreground"
                        aria-label="Sisipkan gambar"
                        title="Sisipkan gambar"
                    >
                        <ImagePlus />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="w-64">
                    <DropdownMenuLabel>Gambar template</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        v-for="image in images"
                        :key="image.id"
                        @select="insertImage(image)"
                    >
                        <span class="truncate">{{ image.name }}</span>
                    </DropdownMenuItem>
                    <p
                        v-if="images.length === 0"
                        class="px-2 py-1.5 text-sm text-muted-foreground"
                    >
                        Unggah gambar di bagian Gambar lebih dulu.
                    </p>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-8 text-muted-foreground"
                    >
                        <Braces />
                        Placeholder
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    class="max-h-80 w-80 overflow-y-auto"
                >
                    <DropdownMenuItem
                        v-for="placeholder in placeholders"
                        :key="placeholder.key"
                        class="flex-col items-start gap-0"
                        @select="insertPlaceholder(placeholder)"
                    >
                        <span class="font-mono text-xs">{{
                            placeholder.token
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            placeholder.label
                        }}</span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
        <EditorContent :editor="editor" />
    </div>
</template>
