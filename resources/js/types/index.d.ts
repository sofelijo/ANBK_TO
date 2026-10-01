export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    role: 'admin' | 'operator' | 'teacher' | 'student';
    school_id?: number;
    grade_level?: number;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash: {
        success?: string;
        error?: string;
    };
    notifications: {
        unread_count: number;
        items: Array<{
            id: string;
            title: string;
            message: string;
            kind: string;
            read_at?: string;
            created_at?: string;
        }>;
    };
};
