# Vendify authorization model

Vendify separates pricing/account tier from authorization:

- `users.user_type` is legacy pricing/reporting data. It never grants admin access.
- `roles.is_staff` on an active assigned role allows entry to staff-only APIs.
- Permission slugs grant individual capabilities. Role names are display/grouping labels and do not grant capabilities.

There is no permission cache. `Role::hasPermission()` queries the role-permission relationship, so changes apply on the next request.

## Role-management permissions

- `manage_roles`: create ordinary roles, edit ordinary role permission sets, view role assignments, and assign staff roles.
- `manage_system_roles`: manage protected owner/co-owner accounts and protected permissions. This is seeded only to `owner`.

The `owner` and `co-owner` roles cannot be deleted. The owner role cannot be deactivated or lose `manage_system_roles`. Users cannot change their own role/status through the customer-management API. The generic table writer rejects users, roles, permissions, transactions, sessions, audit logs, and support records so it cannot bypass dedicated authorization.

## Impersonation

Starting customer impersonation requires:

1. authenticated secure session;
2. active `owner` or `co-owner` role;
3. `customers` permission;
4. `switch_account` permission;
5. recent password confirmation.

Owner and co-owner may target customer or staff accounts. Self-impersonation and nested impersonation are rejected. Start and end events use the existing audit log with actor, actor role, target account, time, IP, user agent, and auth-session context.

While impersonating, the backend blocks profile/password/PIN changes, virtual-account generation, Vendify purchases, betting funding, airtime-to-cash submission, wallet transfers, and withdrawal submission. Ending impersonation remains available even if `switch_account` is subsequently removed, provided the original account remains active staff.

## Seeded switch-account access

After the authorization reconciliation migration and canonical seeder run, these system roles explicitly carry `switch_account`:

- `owner`
- `co-owner`

The backend also verifies the role slug, so assigning `switch_account` to Admin, customer-care, or a custom role cannot grant impersonation. Legacy Admin does not receive `migrations` or `manage_roles`.
