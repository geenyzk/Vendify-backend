-- Seed the `audit_logs` permission and grant it to the staff roles.
--
-- Use this on an environment where you'd rather not run the seeder
-- (`php artisan db:seed --class=PermissionSeeder`). Both do the same thing.
--
-- Safe to run more than once: the INSERTs are idempotent (INSERT IGNORE +
-- NOT EXISTS), so re-running changes nothing and never duplicates a row.
--
-- NOTE: this only seeds the PERMISSION. The `audit_logs` TABLE itself is
-- created by the migration (2026_07_15_050000_create_audit_logs_table) and is
-- meant to start empty — entries are written by the app as things happen, so
-- there is deliberately no sample data to seed.

-- 1. The permission row.
INSERT IGNORE INTO `permissions` (`name`, `slug`, `description`, `created_at`, `updated_at`)
VALUES (
    'Audit Log',
    'audit_logs',
    'View the audit trail of admin actions and data changes',
    NOW(),
    NOW()
);

-- 2. Grant it to every staff role that should see the trail.
--    Adjust the role slug list to taste; 'admin' alone is the safe default.
INSERT INTO `permission_role` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE p.`slug` = 'audit_logs'
  AND r.`slug` IN ('admin', 'owner', 'co-owner')
  AND NOT EXISTS (
      SELECT 1 FROM `permission_role` pr
      WHERE pr.`role_id` = r.`id` AND pr.`permission_id` = p.`id`
  );

-- 3. Verify.
SELECT r.`slug` AS role, p.`slug` AS permission
FROM `permission_role` pr
JOIN `roles` r ON r.`id` = pr.`role_id`
JOIN `permissions` p ON p.`id` = pr.`permission_id`
WHERE p.`slug` = 'audit_logs'
ORDER BY r.`slug`;
