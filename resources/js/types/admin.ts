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

/** Which companies' users a role fits, see App\Enums\CompanyScope. */
export type CompanyScope = 'client' | 'executor';

export type Company = {
    id: number;
    code: string;
    name: string;
    is_client: boolean;
    email_domains: string[];
    is_active: boolean;
    departments_count: number;
    deleted_at: string | null;
};

/** A company as the department filter and form offer it. */
export type CompanyOption = {
    id: number;
    code: string;
    name: string;
    is_client: boolean;
    is_active: boolean;
    deleted: boolean;
};

export type DepartmentOption = {
    id: number;
    code: string;
    name: string;
};

export type Department = DepartmentOption & {
    company_id: number;
    company: {
        id: number;
        code: string;
        name: string;
        is_client: boolean;
        deleted_at: string | null;
    };
    is_active: boolean;
    users_count: number;
    deleted_at: string | null;
};

/** A department the user form offers, with the company whose roles fit. */
export type AssignableDepartment = DepartmentOption & {
    company: { code: string; name: string; scope: CompanyScope };
};

/** A role as the UI names it: its stable slug and its display label. */
export type RoleOption = {
    name: string;
    label: string;
};

export type AssignableRole = RoleOption & {
    company_scope: CompanyScope | null;
};

export type WorkOrderCategory = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    is_active: boolean;
    deleted_at: string | null;
};

/** Review state of an account, see App\Enums\AccountStatus. */
export type AccountStatus = 'pending' | 'approved' | 'rejected';

export type ManagedUser = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    account_status: AccountStatus;
    deleted_at: string | null;
    department: DepartmentOption | null;
    roles: RoleOption[];
};

export type EditableUser = {
    id: number;
    name: string;
    email: string;
    department_id: number | null;
    is_active: boolean;
    roles: string[];
};

/** A self-registered account on the Pendaftaran page. */
export type Registration = {
    id: number;
    name: string;
    email: string;
    account_status: AccountStatus;
    is_active: boolean;
    department: DepartmentOption;
    company: { id: number; name: string; scope: CompanyScope };
    roles: RoleOption[];
    registered_at: string | null;
    reviewed_at: string | null;
    reviewer: string | null;
    rejection_reason: string | null;
};

/** A department the approval dialog may move a registration to. */
export type RegistrationDepartment = DepartmentOption & {
    company_id: number;
};

export type RoleSummary = {
    id: number;
    name: string;
    label: string;
    users_count: number;
    permissions_count: number;
    company_scope: SelectOption | null;
    is_system: boolean;
};

export type EditableRole = {
    id: number;
    name: string;
    /** Null for a role created before labels existed. */
    label: string | null;
    company_scope: CompanyScope | null;
    permissions: string[];
    is_system: boolean;
};

export type ListAbilities = {
    create: boolean;
    update: boolean;
    delete: boolean;
    restore: boolean;
};

export type SelectOption = {
    value: string;
    label: string;
};

export type ActivityValue = string | number | string[] | null;

export type ActivityChange = {
    field: string;
    label: string;
    old: ActivityValue;
    new: ActivityValue;
};

export type ActivityEntry = {
    id: number;
    log_name: string | null;
    event: string;
    event_label: string;
    subject: {
        type: string;
        type_label: string;
        id: number | null;
        label: string;
    } | null;
    causer: { id: number; name: string } | null;
    changes: ActivityChange[];
    /** "Diinput oleh X atas nama Y" for a work order entered on someone's behalf. */
    summary: string | null;
    properties: Record<string, string>;
    created_at: string;
};

/** Morph aliases that have a history panel, see AuditSubject::withHistoryPanel(). */
export type HistorySubjectType =
    | 'user'
    | 'company'
    | 'department'
    | 'wo-category'
    | 'work-order';
