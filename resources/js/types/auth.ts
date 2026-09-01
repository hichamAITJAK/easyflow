import type { AgentScope, CommissionRule, PerformanceTarget } from './agent';

export type UserRole =
    | 'super_admin'
    | 'admin'
    | 'confirmation_agent'
    | 'fulfilment_agent';

export type UserStatus = 'active' | 'invited' | 'disabled';

export type User = {
    id: number;
    business_id: number | null;
    name: string;
    email: string;
    phone?: string | null;
    role: UserRole;
    status: UserStatus;
    avatar?: string | null;
    last_login_at?: string | null;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    agent_scopes?: AgentScope[];
    commission_rules?: CommissionRule[];
    performance_targets?: PerformanceTarget[];
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
