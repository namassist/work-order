<script setup lang="ts">
import { Download, Eye } from '@lucide/vue';
import AttachmentController from '@/actions/App/Http/Controllers/Attachments/AttachmentController';
import { Button } from '@/components/ui/button';
import { useFormatDate } from '@/composables/useFormatDate';
import { attachmentIcon } from '@/lib/attachments';
import { formatFileSize } from '@/lib/format';
import type { Attachment } from '@/types';

/**
 * One stored file as a row: icon, name, size, uploader and time, then
 * preview (images and PDF) and download. Extra actions go in the `actions`
 * slot. Used by the attachment panels and comments, so files look the same
 * everywhere.
 */
defineProps<{
    attachment: Attachment;
}>();

const { formatDateTime } = useFormatDate();
</script>

<template>
    <li class="flex items-center gap-3 px-3 py-2">
        <component
            :is="attachmentIcon(attachment.name, attachment.extension)"
            class="size-5 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm" :title="attachment.name">
                {{ attachment.name }}
            </p>
            <p class="text-xs text-muted-foreground">
                <span class="tabular-nums">{{
                    formatFileSize(attachment.size)
                }}</span>
                <template v-if="attachment.uploader">
                    · {{ attachment.uploader.name }}
                </template>
                ·
                <span class="tabular-nums">{{
                    formatDateTime(attachment.created_at)
                }}</span>
            </p>
        </div>
        <div class="flex shrink-0 items-center">
            <Button
                v-if="attachment.previewable"
                variant="ghost"
                size="icon"
                as-child
            >
                <a
                    :href="AttachmentController.show.url(attachment.id)"
                    target="_blank"
                    rel="noopener noreferrer"
                    :aria-label="`Pratinjau ${attachment.name}`"
                >
                    <Eye />
                </a>
            </Button>
            <Button variant="ghost" size="icon" as-child>
                <a
                    :href="
                        AttachmentController.show.url(attachment.id, {
                            query: { download: 1 },
                        })
                    "
                    :aria-label="`Unduh ${attachment.name}`"
                >
                    <Download />
                </a>
            </Button>
            <slot name="actions" />
        </div>
    </li>
</template>
