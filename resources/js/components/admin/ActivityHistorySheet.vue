<script setup lang="ts">
import { Link, useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import ActivityLogController from '@/actions/App/Http/Controllers/Admin/ActivityLogController';
import ActivityChanges from '@/components/admin/ActivityChanges.vue';
import ActivityEventBadge from '@/components/admin/ActivityEventBadge.vue';
import ActivityProperties from '@/components/admin/ActivityProperties.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormatDate } from '@/composables/useFormatDate';
import type { ActivityEntry, HistorySubjectType } from '@/types';

/**
 * The "Riwayat" panel shared by every module: the latest activity of one
 * record, loaded when the sheet opens.
 */
const props = defineProps<{
    subjectType: HistorySubjectType;
    subjectId: number | null;
    title: string;
}>();

const { formatDateTime } = useFormatDate();

const open = defineModel<boolean>('open', { required: true });

const http = useHttp<Record<string, never>, { data: ActivityEntry[] }>();
const entries = ref<ActivityEntry[]>([]);
const failed = ref(false);

const load = async (subjectId: number) => {
    http.cancel();
    entries.value = [];
    failed.value = false;

    try {
        const response = await http.get(
            ActivityLogController.history({
                subjectType: props.subjectType,
                subjectId,
            }).url,
        );
        entries.value = response.data;
    } catch {
        failed.value = true;
    }
};

watch(
    () => [open.value, props.subjectId] as const,
    ([isOpen, subjectId]) => {
        if (isOpen && subjectId !== null) {
            load(subjectId);
        }
    },
    { immediate: true },
);
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-lg">
            <SheetHeader class="border-b">
                <SheetTitle>Riwayat {{ title }}</SheetTitle>
                <SheetDescription>
                    Perubahan terbaru, dari yang paling baru.
                    <Link
                        v-if="subjectId !== null"
                        :href="
                            ActivityLogController.index({
                                query: {
                                    subject_type: subjectType,
                                    subject_id: subjectId,
                                },
                            })
                        "
                        class="underline underline-offset-4"
                    >
                        Lihat semua di Log Aktivitas
                    </Link>
                </SheetDescription>
            </SheetHeader>

            <div class="flex-1 overflow-y-auto p-4">
                <div v-if="http.processing" class="flex flex-col gap-4">
                    <Skeleton v-for="index in 3" :key="index" class="h-20" />
                </div>
                <p v-else-if="failed" class="text-sm text-destructive">
                    Riwayat gagal dimuat. Tutup lalu buka lagi untuk mencoba
                    ulang.
                </p>
                <p
                    v-else-if="entries.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Belum ada riwayat.
                </p>
                <ol v-else class="flex flex-col gap-4">
                    <li
                        v-for="entry in entries"
                        :key="entry.id"
                        class="flex flex-col gap-2 border-b pb-4 last:border-b-0"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <ActivityEventBadge :entry="entry" />
                            <span class="text-sm">
                                {{ entry.causer?.name ?? 'Sistem' }}
                            </span>
                            <time
                                :datetime="entry.created_at"
                                class="ml-auto text-xs text-muted-foreground tabular-nums"
                            >
                                {{ formatDateTime(entry.created_at) }}
                            </time>
                        </div>
                        <ActivityChanges
                            v-if="entry.changes.length > 0"
                            :changes="entry.changes"
                        />
                        <ActivityProperties :properties="entry.properties" />
                    </li>
                </ol>
            </div>
        </SheetContent>
    </Sheet>
</template>
