export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    must_change_password: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    permissions: string[];
    /** Whether the user works for a client company (IC), see User::isClient(). */
    isClient: boolean;
};
