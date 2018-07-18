CREATE TABLE `swag_migration_data` (
  `id`          binary(16) NOT NULL,
  `tenant_id`   binary(16) NOT NULL,
  `profile`     VARCHAR(255),
  `entity_name` VARCHAR(255),
  `raw`         LONGTEXT,
  `converted`   LONGTEXT,
  `unmapped`    LONGTEXT,
  `created_at`  datetime(3),
  `updated_at`  datetime(3),
  PRIMARY KEY (`id`, `tenant_id`),
  CHECK (JSON_VALID(`raw`)),
  CHECK (JSON_VALID(`converted`)),
  CHECK (JSON_VALID(`unmapped`))
) ENGINE = InnoDB CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE `swag_migration_profile` (
  `id`                binary(16) NOT NULL,
  `tenant_id`         binary(16) NOT NULL,
  `profile`           VARCHAR(255),
  `gateway_type`      VARCHAR(255),
  `credential_fields` LONGTEXT,
  PRIMARY KEY (`id`, `tenant_id`),
  CHECK (JSON_VALID(`credential_fields`))
) ENGINE = InnoDB CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
