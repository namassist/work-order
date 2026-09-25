export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: PaginationLink[];
};

export type DepartmentOption = {
    id: number;
    code: string;
    name: string;
};

export type Department = DepartmentOption & {
    is_active: boolean;
    users_count: number;
    deleted_at: string | null;
};

export type WorkOrderCategory = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    is_active: boolean;
    deleted_at: string | null;
};

export type ManagedUser = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    deleted_at: string | null;
    department: DepartmentOption | null;
    roles: string[];
};

export type EditableUser = {
    id: number;
    name: string;
    email: string;
    department_id: number | null;
    is_active: boolean;
    roles: string[];
};

export type RoleSummary = {
    id: number;
    name: string;
    users_count: number;
    permissions_count: number;
    is_system: boolean;
};

export type EditableRole = {
    id: number;
    name: string;
    permissions: string[];
    is_system: boolean;
};

export type ListAbilities = {
    create: boolean;
    update: boolean;
    delete: boolean;
    restore: boolean;
};
