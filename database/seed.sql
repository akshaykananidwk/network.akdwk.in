-- ---------------------------------------------------------------------------
-- Baseline data. Safe to re-run: every insert is idempotent on a unique key.
-- The admin account is NOT seeded here — the installer creates it so the
-- password is chosen by the operator and never ships in a file.
-- ---------------------------------------------------------------------------

INSERT INTO `__PREFIX__plans`
  (`name`, `slug`, `price_monthly`, `price_yearly`, `currency`, `device_limit`, `network_limit`, `user_limit`, `relay_gb_month`, `features_json`, `support_tier`, `is_public`, `sort_order`)
VALUES
  ('Starter',    'starter',    499.00,   4990.00, 'INR',  10,  1,  3,   10,
   '{"advanced_acl":false,"site_to_site":false,"api_access":false,"sso":false,"white_label":false}', 'community', 1, 1),
  ('Business',   'business',  1999.00,  19990.00, 'INR',  50,  5, 15,  100,
   '{"advanced_acl":true,"site_to_site":true,"api_access":true,"sso":false,"white_label":false}',   'email',     1, 2),
  ('Enterprise', 'enterprise', 7999.00, 79990.00, 'INR', 500, 50, 100, 1000,
   '{"advanced_acl":true,"site_to_site":true,"api_access":true,"sso":true,"white_label":true}',     'priority',  1, 3)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- Exactly one row ever exists; id=1 is assumed by UpdateSetting::current().
INSERT INTO `__PREFIX__update_settings`
  (`id`, `branch`, `channel`, `auto_check`, `auto_apply`, `check_interval_hours`, `verify_signature`, `protected_json`, `backup_retention`)
VALUES
  (1, 'main', 'stable', 1, 0, 6, 0,
   '[".env","config/config.php","uploads/","storage/","install/install.lock","*.local.php"]', 5)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

INSERT INTO `__PREFIX__settings` (`tenant_id`, `key_name`, `value_text`, `is_encrypted`) VALUES
  (NULL, 'platform.signup_enabled',        '1', 0),
  (NULL, 'platform.trial_days',            '14', 0),
  (NULL, 'platform.default_plan_slug',     'starter', 0),
  (NULL, 'alerts.device_offline_minutes',  '30', 0),
  (NULL, 'alerts.enrollment_spike_count',  '25', 0),
  (NULL, 'backup.schedule',                'daily', 0),
  (NULL, 'backup.retention_count',         '7', 0),
  (NULL, 'logs.retention_days',            '30', 0),
  (NULL, 'audit.retention_days',           '365', 0)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;
