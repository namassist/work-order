import { describe, expect, it } from 'vite-plus/test';
import { visibleNavItems } from '@/lib/navigation';
import type { NavItem } from '@/types';

const items: NavItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    {
        title: 'Departemen',
        href: '/admin/departments',
        permission: 'departments.view',
    },
    { title: 'Role', href: '/admin/roles', permission: 'roles.manage' },
];

describe('visibleNavItems', () => {
    it('keeps items without a permission and those the user holds', () => {
        const granted = new Set(['departments.view']);

        expect(
            visibleNavItems(items, (permission) => granted.has(permission)).map(
                (item) => item.title,
            ),
        ).toEqual(['Dashboard', 'Departemen']);
    });

    it('hides every guarded item when the user has no permissions', () => {
        expect(
            visibleNavItems(items, () => false).map((item) => item.title),
        ).toEqual(['Dashboard']);
    });
});
