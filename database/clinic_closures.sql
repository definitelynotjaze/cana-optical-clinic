-- ================================================================
--  Clinic closures: close the whole clinic on a date (every doctor) for
--  a sudden holiday, outing, emergency, etc. Run once in phpMyAdmin →
--  SQL tab, locally and on Hostinger.
-- ================================================================
CREATE TABLE IF NOT EXISTS `clinic_closures` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `date`       DATE         NOT NULL,
  `reason`     VARCHAR(255) NOT NULL,
  `created_by` VARCHAR(150) NULL DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_closure_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
