import { User } from './models';

/** A signed-in Company Admin for specs. */
export function fakeUser(overrides: Partial<User> = {}): User {
  return {
    id: 1,
    name: 'Asha Menon',
    email: 'asha@example.com',
    email_verified: true,
    is_super_admin: false,
    account_type: 'brand',
    company: { id: 10, name: 'Bright Labs', slug: 'bright-labs', approval_status: 'approved', review_note: null },
    role: { id: 1, name: 'Company Admin', slug: 'company_admin' },
    permissions: ['team.manage', 'company.manage'],
    ...overrides,
  };
}
