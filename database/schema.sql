-- ---------------------------------------------------------------------------
-- Base schema.
--
-- `__PREFIX__` is substituted with the configured table prefix by the installer
-- and by the migration runner, so one install can share a database with others.
--
-- Conventions that hold everywhere:
--   * id BIGINT UNSIGNED AUTO_INCREMENT
--   * created_at / updated_at are UTC (the connection sets time_zone='+00:00')
--   * deleted_at NULL marks a soft delete; every list query filters on it
--   * InnoDB + utf8mb4_unicode_ci
-- ---------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------ billing

CREATE TABLE IF NOT EXISTS `__PREFIX__plans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  `slug` VARCHAR(64) NOT NULL,
  `price_monthly` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `price_yearly` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `device_limit` INT NOT NULL DEFAULT 10,
  `network_limit` INT NOT NULL DEFAULT 1,
  `user_limit` INT NOT NULL DEFAULT 3,
  `relay_gb_month` INT NOT NULL DEFAULT 10,
  `features_json` JSON NULL,
  `support_tier` VARCHAR(32) NOT NULL DEFAULT 'community',
  `is_public` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plans_slug` (`slug`),
  KEY `idx_plans_public` (`is_public`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ tenants

CREATE TABLE IF NOT EXISTS `__PREFIX__tenants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_name` VARCHAR(160) NOT NULL,
  `slug` VARCHAR(64) NOT NULL,
  `contact_person` VARCHAR(120) NULL,
  `mobile` VARCHAR(32) NULL,
  `email` VARCHAR(190) NOT NULL,
  `plan_id` BIGINT UNSIGNED NULL,
  `device_limit` INT NOT NULL DEFAULT 10,
  `network_limit` INT NOT NULL DEFAULT 1,
  `user_limit` INT NOT NULL DEFAULT 3,
  `relay_gb_month` INT NOT NULL DEFAULT 10,
  `status` ENUM('trial','active','suspended','cancelled') NOT NULL DEFAULT 'trial',
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
  `locale` VARCHAR(8) NOT NULL DEFAULT 'en',
  `trial_ends_at` DATETIME NULL,
  `subscription_expires_at` DATETIME NULL,
  `grace_until` DATETIME NULL,
  `branding_json` JSON NULL,
  `custom_domain` VARCHAR(190) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenants_slug` (`slug`),
  UNIQUE KEY `uq_tenants_custom_domain` (`custom_domain`),
  KEY `idx_tenants_status` (`status`, `deleted_at`),
  KEY `idx_tenants_plan` (`plan_id`),
  CONSTRAINT `fk_tenants_plan` FOREIGN KEY (`plan_id`) REFERENCES `__PREFIX__plans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------- users

CREATE TABLE IF NOT EXISTS `__PREFIX__users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- NULL tenant_id means a platform (super admin) user.
  `tenant_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('super_admin','company_admin','network_admin','support_agent','read_only') NOT NULL DEFAULT 'read_only',
  `status` ENUM('active','disabled','invited') NOT NULL DEFAULT 'active',
  `twofa_secret` VARCHAR(255) NULL,
  `twofa_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `twofa_recovery_json` JSON NULL,
  `timezone` VARCHAR(64) NULL,
  `locale` VARCHAR(8) NULL,
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `failed_attempts` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL,
  `password_changed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_tenant` (`tenant_id`, `deleted_at`),
  KEY `idx_users_role` (`role`),
  CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `__PREFIX__tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__sessions` (
  `id` VARCHAR(128) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `tenant_id` BIGINT UNSIGNED NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `payload` MEDIUMTEXT NOT NULL,
  `last_activity` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_user` (`user_id`),
  KEY `idx_sessions_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__password_resets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `ip` VARCHAR(45) NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_resets_token` (`token_hash`),
  KEY `idx_resets_user` (`user_id`, `expires_at`),
  CONSTRAINT `fk_resets_user` FOREIGN KEY (`user_id`) REFERENCES `__PREFIX__users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- networks

CREATE TABLE IF NOT EXISTS `__PREFIX__networks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `network_uid` CHAR(16) NOT NULL,
  `description` VARCHAR(255) NULL,
  `cidr` VARCHAR(20) NOT NULL,
  -- Where this network's virtual prefixes come from. NULL uses the
  -- configured default; a customer already numbering out of it needs a way
  -- out that does not move every other customer with them.
  `mapped_pool` VARCHAR(20) NULL DEFAULT NULL,
  `dns_json` JSON NULL,
  `search_domain` VARCHAR(190) NULL,
  `mtu` SMALLINT UNSIGNED NOT NULL DEFAULT 1280,
  `keepalive_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 25,
  `auto_assign_ip` TINYINT(1) NOT NULL DEFAULT 1,
  `auto_approve_devices` TINYINT(1) NOT NULL DEFAULT 0,
  `private` TINYINT(1) NOT NULL DEFAULT 1,
  `acl_default_action` ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  `status` ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  `config_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_networks_uid` (`network_uid`),
  KEY `idx_networks_tenant` (`tenant_id`, `status`, `deleted_at`),
  CONSTRAINT `fk_networks_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `__PREFIX__tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived, single-network enrolment codes shown on the network page.
CREATE TABLE IF NOT EXISTS `__PREFIX__join_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `network_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(24) NOT NULL,
  `max_uses` INT NOT NULL DEFAULT 1,
  `uses` INT NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_join_codes_code` (`code`),
  KEY `idx_join_codes_network` (`network_id`, `expires_at`),
  CONSTRAINT `fk_join_codes_network` FOREIGN KEY (`network_id`) REFERENCES `__PREFIX__networks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_join_codes_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `__PREFIX__tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ devices

CREATE TABLE IF NOT EXISTS `__PREFIX__devices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `network_id` BIGINT UNSIGNED NULL,
  `device_uid` CHAR(24) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `hostname` VARCHAR(190) NULL,
  `os` VARCHAR(32) NULL,
  `os_version` VARCHAR(64) NULL,
  `arch` VARCHAR(16) NULL,
  `agent_version` VARCHAR(32) NULL,
  -- Curve25519 public key, base64. The private half never leaves the device.
  `public_key` VARCHAR(64) NOT NULL,
  `hw_fingerprint` CHAR(64) NULL,
  `virtual_ip` VARCHAR(45) NULL,
  `status` ENUM('pending','authorized','disabled','revoked') NOT NULL DEFAULT 'pending',
  `approved_by` BIGINT UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `revoked_at` DATETIME NULL,
  `last_seen_at` DATETIME NULL,
  `last_endpoint` VARCHAR(64) NULL,
  `last_lan_endpoint` VARCHAR(64) NULL,
  `connection_type` ENUM('direct','relay','offline') NOT NULL DEFAULT 'offline',
  `relay_id` BIGINT UNSIGNED NULL,
  `rx_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `tx_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `latency_ms` INT NULL,
  `tags_json` JSON NULL,
  `is_gateway` TINYINT(1) NOT NULL DEFAULT 0,
  -- What this device could not do, reported where somebody will see it: an
  -- NRPT rule Windows refused, a prefix that clashed with a network the
  -- machine was already on. Neither is something the agent can fix, and a line
  -- in a log file on the customer's machine is the same as no report at all.
  `problems_json` JSON NULL DEFAULT NULL,
  `problems_at` DATETIME NULL DEFAULT NULL,
  `token_hash` CHAR(64) NULL,
  `token_rotated_at` DATETIME NULL,
  `config_revision` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_devices_uid` (`device_uid`),
  UNIQUE KEY `uq_devices_network_ip` (`network_id`, `virtual_ip`),
  UNIQUE KEY `uq_devices_pubkey` (`public_key`),
  KEY `idx_devices_tenant_status` (`tenant_id`, `status`, `deleted_at`),
  KEY `idx_devices_network` (`network_id`, `status`),
  KEY `idx_devices_lastseen` (`last_seen_at`),
  KEY `idx_devices_token` (`token_hash`),
  CONSTRAINT `fk_devices_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `__PREFIX__tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_devices_network` FOREIGN KEY (`network_id`) REFERENCES `__PREFIX__networks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__ip_allocations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `network_id` BIGINT UNSIGNED NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `ip_numeric` INT UNSIGNED NOT NULL,
  `device_id` BIGINT UNSIGNED NULL,
  `reserved` TINYINT(1) NOT NULL DEFAULT 0,
  `label` VARCHAR(120) NULL,
  `released_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip_network_ip` (`network_id`, `ip`),
  KEY `idx_ip_device` (`device_id`),
  KEY `idx_ip_free` (`network_id`, `device_id`, `reserved`),
  KEY `idx_ip_numeric` (`network_id`, `ip_numeric`),
  CONSTRAINT `fk_ip_network` FOREIGN KEY (`network_id`) REFERENCES `__PREFIX__networks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ip_device` FOREIGN KEY (`device_id`) REFERENCES `__PREFIX__devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- access rules

CREATE TABLE IF NOT EXISTS `__PREFIX__acl_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `network_id` BIGINT UNSIGNED NOT NULL,
  `priority` INT NOT NULL DEFAULT 100,
  `description` VARCHAR(190) NULL,
  `src_type` ENUM('device','tag','cidr','any') NOT NULL DEFAULT 'any',
  `src_value` VARCHAR(190) NULL,
  `dst_type` ENUM('device','tag','cidr','any') NOT NULL DEFAULT 'any',
  `dst_value` VARCHAR(190) NULL,
  `protocol` ENUM('tcp','udp','icmp','any') NOT NULL DEFAULT 'any',
  `port_from` INT NULL,
  `port_to` INT NULL,
  `action` ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acl_network` (`network_id`, `enabled`, `priority`),
  KEY `idx_acl_tenant` (`tenant_id`),
  CONSTRAINT `fk_acl_network` FOREIGN KEY (`network_id`) REFERENCES `__PREFIX__networks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__routes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `network_id` BIGINT UNSIGNED NOT NULL,
  `destination_cidr` VARCHAR(20) NOT NULL,
  -- The prefix the overlay uses for this LAN. Unique within the network,
  -- because two customers both on 192.168.1.0/24 is the normal case and one
  -- routing table cannot hold both.
  `mapped_cidr` VARCHAR(20) NULL DEFAULT NULL,
  `via_device_id` BIGINT UNSIGNED NULL,
  `metric` INT NOT NULL DEFAULT 100,
  `description` VARCHAR(190) NULL,
  `approved` TINYINT(1) NOT NULL DEFAULT 0,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  -- Same NULL-in-a-unique-key problem as settings: a route with no gateway
  -- would otherwise be insertable twice.
  `route_key` VARCHAR(80) AS (CONCAT(`network_id`, ':', `destination_cidr`, ':', IFNULL(`via_device_id`, 0))) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_routes_net_dest` (`route_key`),
  KEY `idx_routes_network` (`network_id`, `enabled`),
  -- Unique: two LANs sharing a virtual prefix is the collision the virtual
  -- prefix exists to prevent, and allocation reads before it writes.
  UNIQUE KEY `idx_routes_mapped` (`network_id`, `mapped_cidr`),
  KEY `idx_routes_tenant` (`tenant_id`),
  CONSTRAINT `fk_routes_network` FOREIGN KEY (`network_id`) REFERENCES `__PREFIX__networks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_routes_device` FOREIGN KEY (`via_device_id`) REFERENCES `__PREFIX__devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- relays

-- Names for machines behind a gateway (§18).
--
-- An NVR, a printer and a DVR are not devices: no agent, no key, no row of
-- their own anywhere. They are addresses inside an advertised range, and this
-- is where an operator writes down which address is which so a technician can
-- type a name instead of a number the panel invented.
CREATE TABLE IF NOT EXISTS `__PREFIX__route_hosts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `network_id` BIGINT UNSIGNED NOT NULL,
  `route_id` BIGINT UNSIGNED NOT NULL,
  `label` VARCHAR(63) NOT NULL,
  -- The **real** address, the one on the label on the machine. The mapped
  -- address is derived, because a route withdrawn and re-advertised can be
  -- given a different prefix and a stored copy would then be wrong.
  `address` VARCHAR(45) NOT NULL,
  `description` VARCHAR(190) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  -- Two machines answering to one name, or one machine with two names, are
  -- both ways for a technician to reach the wrong box.
  UNIQUE KEY `uq_route_hosts_label` (`route_id`, `label`),
  UNIQUE KEY `uq_route_hosts_address` (`route_id`, `address`),
  KEY `idx_route_hosts_network` (`network_id`),
  KEY `idx_route_hosts_tenant` (`tenant_id`),
  CONSTRAINT `fk_route_hosts_route` FOREIGN KEY (`route_id`) REFERENCES `__PREFIX__routes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__relays` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `region` VARCHAR(32) NOT NULL DEFAULT 'in',
  `host` VARCHAR(190) NOT NULL,
  -- The relay's control port. 9000 is what akconnect-relay listens on; the
  -- old default of 51820 was WireGuard's, which a relay does not use.
  `port` INT NOT NULL DEFAULT 9000,
  -- TCP fallback is not implemented. The column stays for when it is.
  `tcp_port` INT NOT NULL DEFAULT 0,
  -- Unused. A relay authorises sessions from the coordinator's HMAC ticket and
  -- has no keypair; this is left over from a design where agents were going to
  -- talk to relays directly. Nullable since 1.9.1 — see the migration.
  `public_key` VARCHAR(64) NULL DEFAULT NULL,
  `capacity_mbps` INT NOT NULL DEFAULT 100,
  `current_sessions` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','draining','down','disabled') NOT NULL DEFAULT 'active',
  `last_heartbeat_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_relays_pubkey` (`public_key`),
  KEY `idx_relays_status` (`status`, `region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ api/audit

CREATE TABLE IF NOT EXISTS `__PREFIX__api_keys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(120) NOT NULL,
  `key_prefix` VARCHAR(16) NOT NULL,
  `key_hash` CHAR(64) NOT NULL,
  `scopes_json` JSON NULL,
  `last_used_at` DATETIME NULL,
  `last_used_ip` VARCHAR(45) NULL,
  `expires_at` DATETIME NULL,
  `revoked_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_keys_hash` (`key_hash`),
  KEY `idx_api_keys_tenant` (`tenant_id`, `revoked_at`),
  KEY `idx_api_keys_prefix` (`key_prefix`),
  CONSTRAINT `fk_api_keys_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `__PREFIX__tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `impersonator_id` BIGINT UNSIGNED NULL,
  `actor_type` VARCHAR(16) NOT NULL DEFAULT 'user',
  `action` VARCHAR(64) NOT NULL,
  `target_type` VARCHAR(48) NULL,
  `target_id` BIGINT UNSIGNED NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `before_json` JSON NULL,
  `after_json` JSON NULL,
  `result` ENUM('success','failure') NOT NULL DEFAULT 'success',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_tenant_created` (`tenant_id`, `created_at`),
  KEY `idx_audit_user` (`user_id`, `created_at`),
  KEY `idx_audit_action` (`action`, `created_at`),
  KEY `idx_audit_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- platform

CREATE TABLE IF NOT EXISTS `__PREFIX__settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NULL,
  `key_name` VARCHAR(120) NOT NULL,
  `value_text` MEDIUMTEXT NULL,
  `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
  -- A composite UNIQUE over a nullable column does NOT constrain NULL rows:
  -- SQL treats every NULL as distinct, so platform settings (tenant_id NULL)
  -- would duplicate on every upsert. Folding NULL to 0 in a stored generated
  -- column gives the constraint we actually mean, and keeps the FK intact.
  `tenant_key` VARCHAR(191) AS (CONCAT(IFNULL(`tenant_id`, 0), ':', `key_name`)) STORED,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_tenant_key` (`tenant_key`),
  KEY `idx_settings_lookup` (`tenant_id`, `key_name`),
  CONSTRAINT `fk_settings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `__PREFIX__tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `level` ENUM('info','success','warning','critical') NOT NULL DEFAULT 'info',
  `title` VARCHAR(190) NOT NULL,
  `body` TEXT NULL,
  `link` VARCHAR(255) NULL,
  `category` VARCHAR(48) NULL,
  `read_at` DATETIME NULL,
  `emailed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`, `read_at`, `created_at`),
  KEY `idx_notifications_tenant` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` VARCHAR(48) NOT NULL DEFAULT 'default',
  `job_type` VARCHAR(64) NOT NULL,
  `payload_json` JSON NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `max_attempts` INT NOT NULL DEFAULT 5,
  `available_at` DATETIME NOT NULL,
  `reserved_at` DATETIME NULL,
  `reserved_by` VARCHAR(64) NULL,
  `completed_at` DATETIME NULL,
  `failed_at` DATETIME NULL,
  `last_error` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jobs_pickup` (`queue`, `reserved_at`, `available_at`),
  KEY `idx_jobs_state` (`completed_at`, `failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MySQL fallback for the sliding-window limiter when Redis is not configured.
CREATE TABLE IF NOT EXISTS `__PREFIX__rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket_key` VARCHAR(190) NOT NULL,
  `window_start` BIGINT UNSIGNED NOT NULL,
  `hits` INT NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate_key_window` (`bucket_key`, `window_start`),
  KEY `idx_rate_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ billing

CREATE TABLE IF NOT EXISTS `__PREFIX__subscriptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `plan_id` BIGINT UNSIGNED NOT NULL,
  `billing_cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `status` ENUM('trialing','active','past_due','cancelled','expired') NOT NULL DEFAULT 'trialing',
  `gateway` VARCHAR(32) NULL,
  `gateway_subscription_id` VARCHAR(128) NULL,
  `current_period_start` DATETIME NULL,
  `current_period_end` DATETIME NULL,
  `cancelled_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_subs_tenant` (`tenant_id`, `status`),
  CONSTRAINT `fk_subs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `__PREFIX__tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subs_plan` FOREIGN KEY (`plan_id`) REFERENCES `__PREFIX__plans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `subscription_id` BIGINT UNSIGNED NULL,
  `invoice_number` VARCHAR(32) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `status` ENUM('draft','issued','paid','void','refunded') NOT NULL DEFAULT 'draft',
  `gateway` VARCHAR(32) NULL,
  `gateway_payment_id` VARCHAR(128) NULL,
  `issued_at` DATETIME NULL,
  `due_at` DATETIME NULL,
  `paid_at` DATETIME NULL,
  `lines_json` JSON NULL,
  `pdf_path` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  KEY `idx_invoices_tenant` (`tenant_id`, `status`),
  CONSTRAINT `fk_invoices_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `__PREFIX__tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__usage_counters` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `period` CHAR(7) NOT NULL,
  `metric` VARCHAR(48) NOT NULL,
  `value_num` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usage_tenant_period_metric` (`tenant_id`, `period`, `metric`),
  CONSTRAINT `fk_usage_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `__PREFIX__tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- agent distribution

CREATE TABLE IF NOT EXISTS `__PREFIX__agent_releases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` VARCHAR(32) NOT NULL,
  `channel` ENUM('stable','beta','dev') NOT NULL DEFAULT 'stable',
  `platform` VARCHAR(32) NOT NULL,
  `arch` VARCHAR(16) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sha256` CHAR(64) NOT NULL,
  `signature` VARCHAR(255) NOT NULL,
  `release_notes` TEXT NULL,
  `rollout_percent` TINYINT UNSIGNED NOT NULL DEFAULT 100,
  `published_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agent_release` (`version`, `channel`, `platform`, `arch`),
  KEY `idx_agent_channel` (`channel`, `platform`, `arch`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- updater

CREATE TABLE IF NOT EXISTS `__PREFIX__app_updates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_version` VARCHAR(32) NULL,
  `to_version` VARCHAR(32) NULL,
  `from_commit` VARCHAR(40) NULL,
  `to_commit` VARCHAR(40) NULL,
  `status` ENUM('checking','downloading','backing_up','migrating','applying','verifying','success','failed','rolled_back') NOT NULL DEFAULT 'checking',
  `step` VARCHAR(32) NOT NULL DEFAULT 'PRECHECK',
  `progress_pct` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `backup_id` BIGINT UNSIGNED NULL,
  `log_path` VARCHAR(255) NULL,
  `journal_path` VARCHAR(255) NULL,
  `stage_path` VARCHAR(255) NULL,
  `manifest_json` JSON NULL,
  `applied_migrations_json` JSON NULL,
  -- The migration files MIGRATE copied into database/migrations, so a
  -- rollback can remove exactly those and leave the rest alone.
  `copied_migrations_json` JSON NULL,
  `error_text` TEXT NULL,
  `started_by` BIGINT UNSIGNED NULL,
  `trigger_source` ENUM('web','cli','schedule') NOT NULL DEFAULT 'web',
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_updates_status` (`status`, `created_at`),
  KEY `idx_updates_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__app_backups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('pre_update','manual','scheduled') NOT NULL DEFAULT 'manual',
  `files_path` VARCHAR(255) NULL,
  `db_path` VARCHAR(255) NULL,
  `files_sha256` CHAR(64) NULL,
  `db_sha256` CHAR(64) NULL,
  -- Which dumper wrote db_path. A dump mysqldump produced for a schema
  -- with generated columns cannot be replayed (error 1906), so the
  -- recovery instructions need to know which one it was.
  `db_method` VARCHAR(20) NULL DEFAULT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `app_version` VARCHAR(32) NULL,
  `app_commit` VARCHAR(40) NULL,
  -- Explicit rather than implied: the UI must never claim uploads were saved
  -- when they were skipped for size.
  `uploads_included` TINYINT(1) NOT NULL DEFAULT 1,
  `uploads_skipped_reason` VARCHAR(190) NULL,
  `status` ENUM('running','complete','failed') NOT NULL DEFAULT 'running',
  `error_text` TEXT NULL,
  `retained_until` DATETIME NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_backups_type` (`type`, `created_at`),
  KEY `idx_backups_retention` (`retained_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__migrations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(190) NOT NULL,
  `batch` INT NOT NULL DEFAULT 1,
  `checksum` CHAR(64) NOT NULL,
  `execution_ms` INT NOT NULL DEFAULT 0,
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  `error_text` TEXT NULL,
  `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migrations_filename` (`filename`),
  KEY `idx_migrations_batch` (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__update_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `repo_owner` VARCHAR(120) NULL,
  `repo_name` VARCHAR(120) NULL,
  `branch` VARCHAR(120) NOT NULL DEFAULT 'main',
  -- AES-256-GCM ciphertext. Never rendered, never logged, never returned by the API.
  `token_encrypted` TEXT NULL,
  `channel` ENUM('stable','beta') NOT NULL DEFAULT 'stable',
  `auto_check` TINYINT(1) NOT NULL DEFAULT 1,
  `auto_apply` TINYINT(1) NOT NULL DEFAULT 0,
  `check_interval_hours` INT NOT NULL DEFAULT 6,
  `last_checked_at` DATETIME NULL,
  `last_available_version` VARCHAR(32) NULL,
  `last_available_commit` VARCHAR(40) NULL,
  `current_commit` VARCHAR(40) NULL,
  `verify_signature` TINYINT(1) NOT NULL DEFAULT 0,
  `protected_json` JSON NULL,
  `backup_retention` INT NOT NULL DEFAULT 5,
  `maintenance_allowlist_json` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
