-- Migration: 001_create_migrations_table
-- Creates the schema_migrations tracking table

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
