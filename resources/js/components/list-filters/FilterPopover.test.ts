import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import FilterPopover from './FilterPopover.vue';

describe('FilterPopover', () => {
    it('shows how many of its filters are active', () => {
        const wrapper = mount(FilterPopover, { props: { count: 2 } });
        const trigger = wrapper.get('[data-test="filter-popover-trigger"]');

        expect(trigger.text()).toContain('Filter');
        expect(trigger.get('[data-test="filter-count"]').text()).toBe('2');
        expect(trigger.attributes('aria-label')).toBe('Filter, 2 aktif');
    });

    it('has no count badge when none is active', () => {
        const wrapper = mount(FilterPopover, { props: { count: 0 } });

        expect(wrapper.find('[data-test="filter-count"]').exists()).toBe(false);
    });
});
