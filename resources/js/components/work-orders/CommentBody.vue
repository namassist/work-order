<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * A comment's body. The HTML was sanitized by the server when the comment
 * was saved and again when the page was built (CommentHtml::forDisplay), and
 * this is the only place it is rendered as HTML. Images open full size in a
 * dialog, by mouse or keyboard.
 */
const props = defineProps<{
    html: string;
}>();

const root = ref<HTMLElement | null>(null);
const preview = ref<{ src: string; alt: string } | null>(null);

const open = computed({
    get: () => preview.value !== null,
    set: (value: boolean) => {
        if (!value) {
            preview.value = null;
        }
    },
});

/** Inline images can be opened from the keyboard and load lazily. */
const enhanceImages = () => {
    root.value?.querySelectorAll('img').forEach((image) => {
        image.tabIndex = 0;
        image.loading = 'lazy';
        image.decoding = 'async';
        image.setAttribute('role', 'button');
        image.setAttribute('aria-label', `Perbesar gambar ${image.alt}`);
    });
};

onMounted(enhanceImages);
watch(() => props.html, enhanceImages, { flush: 'post' });

const openImage = (target: EventTarget | null): boolean => {
    if (!(target instanceof HTMLImageElement)) {
        return false;
    }

    preview.value = { src: target.getAttribute('src') ?? '', alt: target.alt };

    return true;
};

const onKeydown = (event: KeyboardEvent) => {
    if (
        (event.key === 'Enter' || event.key === ' ') &&
        openImage(event.target)
    ) {
        event.preventDefault();
    }
};
</script>

<template>
    <div>
        <!-- eslint-disable-next-line vue/no-v-html -- sanitized by the server, see above -->
        <div
            ref="root"
            class="comment-body text-sm break-words"
            data-test="body"
            @click="openImage($event.target)"
            @keydown="onKeydown"
            v-html="html"
        />

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-4xl">
                <DialogTitle class="truncate pr-6">
                    {{ preview?.alt || 'Gambar' }}
                </DialogTitle>
                <DialogDescription class="sr-only">
                    Gambar dari komentar dalam ukuran penuh.
                </DialogDescription>
                <img
                    v-if="preview"
                    :src="preview.src"
                    :alt="preview.alt"
                    class="mx-auto max-h-[75vh] w-auto max-w-full rounded-md"
                />
                <a
                    v-if="preview"
                    :href="preview.src"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 justify-self-end text-sm underline underline-offset-2"
                >
                    Buka di tab baru <ExternalLink class="size-3.5" />
                </a>
            </DialogContent>
        </Dialog>
    </div>
</template>
