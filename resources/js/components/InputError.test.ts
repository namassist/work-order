import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import InputError from './InputError.vue';

describe('InputError', () => {
    it('renders the given message', () => {
        const wrapper = mount(InputError, {
            props: { message: 'The email field is required.' },
        });

        expect(wrapper.text()).toBe('The email field is required.');
        expect(wrapper.attributes('style')).toBeUndefined();
    });

    it('is hidden when there is no message', () => {
        const wrapper = mount(InputError);

        expect(wrapper.attributes('style')).toContain('display: none');
    });
});
