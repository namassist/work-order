import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import type { RequesterAccount } from '@/types';
import RequesterAccountPicker from './RequesterAccountPicker.vue';

type Pending = {
    resolve: (value: { data: RequesterAccount[] }) => void;
    reject: (error: Error) => void;
};

const requests: Pending[] = [];
let cancelRejects = true;

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    useHttp: () => ({
        processing: false,
        get: () =>
            new Promise((resolve, reject) =>
                requests.push({ resolve, reject }),
            ),
        cancel: () => {
            if (cancelRejects) {
                requests.at(-1)?.reject(new Error('cancelled'));
            }
        },
    }),
}));

const account = (id: number, name: string): RequesterAccount => ({
    id,
    name,
    email: `${name.toLowerCase()}@ic.test`,
});

function render() {
    return mount(RequesterAccountPicker, {
        props: { departmentId: 1, inputId: 'picker', modelValue: null },
    });
}

describe('RequesterAccountPicker', () => {
    beforeEach(() => {
        requests.length = 0;
        cancelRejects = true;
    });

    it('shows the latest results after a superseded request was cancelled', async () => {
        const wrapper = render();

        await wrapper.setProps({ departmentId: 2 });
        requests[1].resolve({ data: [account(5, 'Eko')] });
        await flushPromises();

        expect(wrapper.text()).toContain('Eko');
        expect(wrapper.text()).not.toContain('gagal');
    });

    it('ignores an older request that finishes after the newer one', async () => {
        cancelRejects = false;
        const wrapper = render();

        await wrapper.setProps({ departmentId: 2 });
        requests[1].resolve({ data: [account(5, 'Eko')] });
        await flushPromises();
        requests[0].resolve({ data: [account(9, 'Lama')] });
        await flushPromises();

        expect(wrapper.text()).toContain('Eko');
        expect(wrapper.text()).not.toContain('Lama');
    });

    it('reports a failure of the latest request', async () => {
        cancelRejects = false;
        const wrapper = render();

        requests[0].reject(new Error('500'));
        await flushPromises();

        expect(wrapper.text()).toContain('gagal dimuat');
    });

    it('clears the chosen account when the department changes', async () => {
        const wrapper = mount(RequesterAccountPicker, {
            props: {
                departmentId: 1,
                inputId: 'picker',
                modelValue: account(5, 'Eko'),
                'onUpdate:modelValue': (value: RequesterAccount | null) =>
                    wrapper.setProps({ modelValue: value }),
            },
        });

        await wrapper.setProps({ departmentId: 2 });

        expect(wrapper.props('modelValue')).toBeNull();
    });
});
