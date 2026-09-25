import { describe, expect, it } from 'vite-plus/test';
import { visibleNavGroups, visibleNavItems } from '@/lib/navigation';
import type { NavGroup, NavItem } from '@/types';

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

describe('visibleNavGroups', () => {
    const groups: NavGroup[] = [
        { items: [items[0]] },
        { label: 'Master Data', items: [items[1]] },
        { label: 'Administrasi', items: [items[2]] },
    ];

    it('drops groups left with no visible items', () => {
        const granted = new Set(['departments.view']);

        expect(
            visibleNavGroups(groups, (permission) => granted.has(permission)),
        ).toEqual([
            { items: [items[0]] },
            { label: 'Master Data', items: [items[1]] },
        ]);
    });
});
