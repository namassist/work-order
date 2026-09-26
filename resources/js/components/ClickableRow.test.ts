import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { defineComponent, h } from 'vue';
import ClickableRow from './ClickableRow.vue';
import RowActionsMenu from './RowActionsMenu.vue';

const onTitleClick = vi.fn();
const onActivate = vi.fn();

/**
 * A row shaped like the list pages': a title link in the main cell, plain
 * text, a checkbox, and the three-dot menu in the last cell.
 */
const Harness = defineComponent({
    props: { disabled: Boolean },
    setup(props) {
        return () =>
            h('table', [
                h('tbody', [
                    h(
                        ClickableRow,
                        { disabled: props.disabled, onActivate },
                        () => [
                            h('td', [
                                h(
                                    'a',
                                    {
                                        href: '/work-orders/1',
                                        'data-test': 'title',
                                        onClick: (event: MouseEvent) => {
                                            event.preventDefault();
                                            onTitleClick();
                                        },
                                    },
                                    [h('span', 'WO/IT/2026/09/0001')],
                                ),
                            ]),
                            h('td', { 'data-test': 'text' }, 'Lampu mati'),
                            h('td', [
                                h('input', {
                                    type: 'checkbox',
                                    'data-test': 'checkbox',
                                }),
                            ]),
                            h('td', [
                                h(RowActionsMenu, { label: 'Aksi WO 1' }),
                            ]),
                        ],
                    ),
                ]),
            ]);
    },
});

const mountRow = (props: { disabled?: boolean } = {}) =>
    mount(Harness, { props, attachTo: document.body });

afterEach(() => {
    onTitleClick.mockReset();
    onActivate.mockReset();
    document.body.innerHTML = '';
});

describe('ClickableRow', () => {
    it('activates when a plain cell is clicked', async () => {
        const wrapper = mountRow();

        await wrapper.get('[data-test="text"]').trigger('click');

        expect(onActivate).toHaveBeenCalledOnce();
    });

    it('lets the title link handle its own click without activating the row', async () => {
        const wrapper = mountRow();

        await wrapper.get('[data-test="title"] span').trigger('click');

        expect(onTitleClick).toHaveBeenCalledOnce();
        expect(onActivate).not.toHaveBeenCalled();
    });

    it('lets the three-dot menu open without activating the row', async () => {
        const wrapper = mountRow();
        const trigger = wrapper.get('button[aria-label="Aksi WO 1"]');

        await trigger.trigger('pointerdown', {
            button: 0,
            pointerType: 'mouse',
        });
        await trigger.trigger('click');

        expect(trigger.attributes('aria-expanded')).toBe('true');
        expect(onActivate).not.toHaveBeenCalled();
    });

    it('leaves form controls in the row alone', async () => {
        const wrapper = mountRow();

        await wrapper.get('[data-test="checkbox"]').trigger('click');

        expect(onActivate).not.toHaveBeenCalled();
    });

    it.each([
        ['ctrlKey', { ctrlKey: true }],
        ['metaKey', { metaKey: true }],
        ['shiftKey', { shiftKey: true }],
        ['middle button', { button: 1 }],
    ])('ignores a click with %s', async (_, init) => {
        const wrapper = mountRow();

        await wrapper.get('[data-test="text"]').trigger('click', init);

        expect(onActivate).not.toHaveBeenCalled();
    });

    it('does nothing when disabled', async () => {
        const wrapper = mountRow({ disabled: true });

        await wrapper.get('[data-test="text"]').trigger('click');

        expect(onActivate).not.toHaveBeenCalled();
        expect(wrapper.get('tr').classes()).not.toContain('cursor-pointer');
    });

    it('keeps the title link as the first Tab stop in the row, and Enter follows it', async () => {
        const wrapper = mountRow();
        const tabbable = wrapper
            .get('tr')
            .element.querySelectorAll<HTMLElement>(
                'a[href], button, input, [tabindex]',
            );
        const title = wrapper.get('[data-test="title"]');

        expect(tabbable[0]).toBe(title.element);
        expect(title.attributes('tabindex')).toBeUndefined();
        expect(wrapper.get('tr').attributes('tabindex')).toBeUndefined();

        // Browsers turn Enter on a focused link into a click on it.
        (title.element as HTMLElement).focus();
        expect(document.activeElement).toBe(title.element);
        await title.trigger('click');

        expect(onTitleClick).toHaveBeenCalledOnce();
        expect(onActivate).not.toHaveBeenCalled();
    });
});
