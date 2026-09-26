import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import ActiveFilterChips from './ActiveFilterChips.vue';

const chips = [
    { key: 'status', label: 'Status: Diajukan' },
    { key: 'created', label: 'Dibuat: Sejak 1 Sep 2026' },
];

describe('ActiveFilterChips', () => {
    it('renders nothing when no filter is active', () => {
        const wrapper = mount(ActiveFilterChips, { props: { chips: [] } });

        expect(wrapper.find('[data-test="active-filters"]').exists()).toBe(
            false,
        );
    });

    it('shows one removable chip per active filter', () => {
        const wrapper = mount(ActiveFilterChips, { props: { chips } });
        const buttons = wrapper.findAll('[data-test="filter-chip"]');

        expect(buttons.map((button) => button.text())).toEqual([
            'Status: Diajukan',
            'Dibuat: Sejak 1 Sep 2026',
        ]);
        expect(buttons[0].attributes('aria-label')).toBe(
            'Hapus filter Status: Diajukan',
        );
    });

    it('emits the key of a removed chip, and reset', async () => {
        const wrapper = mount(ActiveFilterChips, { props: { chips } });

        await wrapper.findAll('[data-test="filter-chip"]')[1].trigger('click');
        await wrapper.get('[data-test="filter-reset"]').trigger('click');

        expect(wrapper.emitted('remove')).toEqual([['created']]);
        expect(wrapper.emitted('reset')).toHaveLength(1);
    });
});
