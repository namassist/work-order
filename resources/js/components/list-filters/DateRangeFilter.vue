<script setup lang="ts">
import type { DateValue } from '@internationalized/date';
import { parseDate } from '@internationalized/date';
import { usePage } from '@inertiajs/vue3';
import { CalendarDays } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import type { DateRange } from 'reka-ui';
import { RangeCalendarRoot } from 'reka-ui';
import { computed, ref, shallowRef } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    RangeCalendarCell,
    RangeCalendarCellTrigger,
    RangeCalendarGrid,
    RangeCalendarGridBody,
    RangeCalendarGridHead,
    RangeCalendarGridRow,
    RangeCalendarHeadCell,
    RangeCalendarHeader,
    RangeCalendarHeading,
    RangeCalendarNextButton,
    RangeCalendarPrevButton,
} from '@/components/ui/range-calendar';
import type {
    CalendarDateRange,
    DateRangePresetKey,
} from '@/lib/dateRangePresets';
import { DATE_RANGE_PRESETS, presetRange } from '@/lib/dateRangePresets';
import { calendarDateIn, formatCalendarDateRange } from '@/lib/format';

/**
 * A calendar-day range filter (Y-m-d ends) with presets counted in the display
 * timezone. It emits only complete changes: both ends picked, a preset, or
 * "Hapus" (both ends cleared), so the list never reloads after the first
 * click.
 */
const props = defineProps<{
    id?: string;
    from: string;
    to: string;
    label: string;
}>();

const emit = defineEmits<{ 'update:range': [range: CalendarDateRange] }>();

const page = usePage();
const open = ref(false);
const twoMonths = useMediaQuery('(min-width: 768px)');

const toDateValue = (date: string): DateValue | undefined =>
    date ? parseDate(date) : undefined;

/** The range as picked so far; only a complete one is applied. */
const draft = shallowRef<DateRange>({ start: undefined, end: undefined });

const today = () =>
    parseDate(calendarDateIn(new Date(), page.props.displayTimezone));

const onOpenChange = (value: boolean) => {
    if (value) {
        draft.value = {
            start: toDateValue(props.from),
            end: toDateValue(props.to),
        };
    }

    open.value = value;
};

const apply = (range: CalendarDateRange) => {
    emit('update:range', range);
    open.value = false;
};

const applyPreset = (key: DateRangePresetKey) =>
    apply(presetRange(key, new Date(), page.props.displayTimezone));

const pick = (range: DateRange) => {
    draft.value = range;

    if (range.start && range.end) {
        apply({ from: range.start.toString(), to: range.end.toString() });
    }
};

const summary = computed(() => formatCalendarDateRange(props.from, props.to));
</script>

<template>
    <Popover :open="open" @update:open="onOpenChange">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                variant="outline"
                class="w-full justify-start font-normal"
                :class="{ 'text-muted-foreground': !summary }"
                :aria-label="`${label}: ${summary || 'pilih tanggal'}`"
                data-test="date-range-trigger"
            >
                <CalendarDays class="text-muted-foreground" />
                <span class="truncate">{{ summary || 'Pilih tanggal' }}</span>
            </Button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            class="flex w-auto max-w-[calc(100vw-2rem)] flex-col p-0 sm:flex-row"
            data-test="date-range-popover"
        >
            <div
                class="flex flex-wrap gap-1 border-b p-3 sm:w-40 sm:flex-col sm:flex-nowrap sm:border-r sm:border-b-0"
            >
                <Button
                    v-for="preset in DATE_RANGE_PRESETS"
                    :key="preset.key"
                    variant="ghost"
                    size="sm"
                    class="justify-start"
                    :data-test="`date-range-preset-${preset.key}`"
                    @click="applyPreset(preset.key)"
                >
                    {{ preset.label }}
                </Button>
                <Button
                    v-if="from || to"
                    variant="ghost"
                    size="sm"
                    class="justify-start text-muted-foreground sm:mt-auto"
                    data-test="date-range-clear"
                    @click="apply({ from: '', to: '' })"
                >
                    Hapus
                </Button>
            </div>
            <!-- The ui RangeCalendar's layout, with Indonesian labels its
                 wrapper cannot pass to the navigation buttons. -->
            <RangeCalendarRoot
                v-slot="{ grid, weekDays }"
                :model-value="draft"
                :default-placeholder="draft.start ?? today()"
                locale="id-ID"
                :calendar-label="`Tanggal ${label.toLowerCase()}`"
                :week-starts-on="1"
                weekday-format="short"
                :number-of-months="twoMonths ? 2 : 1"
                class="p-3"
                data-test="date-range-calendar"
                @update:model-value="pick"
            >
                <RangeCalendarHeader>
                    <RangeCalendarHeading />
                    <div class="flex items-center gap-1">
                        <RangeCalendarPrevButton
                            aria-label="Bulan sebelumnya"
                        />
                        <RangeCalendarNextButton
                            aria-label="Bulan berikutnya"
                        />
                    </div>
                </RangeCalendarHeader>
                <div class="mt-4 flex flex-col gap-4 md:flex-row">
                    <RangeCalendarGrid
                        v-for="month in grid"
                        :key="month.value.toString()"
                    >
                        <RangeCalendarGridHead>
                            <RangeCalendarGridRow>
                                <RangeCalendarHeadCell
                                    v-for="day in weekDays"
                                    :key="day"
                                >
                                    {{ day }}
                                </RangeCalendarHeadCell>
                            </RangeCalendarGridRow>
                        </RangeCalendarGridHead>
                        <RangeCalendarGridBody>
                            <RangeCalendarGridRow
                                v-for="(week, index) in month.rows"
                                :key="`week-${index}`"
                                class="mt-2 w-full"
                            >
                                <RangeCalendarCell
                                    v-for="day in week"
                                    :key="day.toString()"
                                    :date="day"
                                >
                                    <RangeCalendarCellTrigger
                                        :day="day"
                                        :month="month.value"
                                    />
                                </RangeCalendarCell>
                            </RangeCalendarGridRow>
                        </RangeCalendarGridBody>
                    </RangeCalendarGrid>
                </div>
            </RangeCalendarRoot>
        </PopoverContent>
    </Popover>
</template>
