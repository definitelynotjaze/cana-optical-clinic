-- ================================================================
--  Patient source badge (replaces Active/Inactive on Patient Records)
--  + removal of the 1-year auto-deactivation.
--  Run once in phpMyAdmin → SQL tab, locally and on Hostinger.
--  (If the ADD COLUMN lines say "Duplicate column", they already ran —
--  skip them and run the rest.)
-- ================================================================

-- How the patient came to the clinic: online | walk-in | transferred | referral
ALTER TABLE `patients` ADD COLUMN `source` VARCHAR(20) NULL DEFAULT NULL AFTER `status`;
-- Previous clinic's name (transferred) or who referred them (referral).
ALTER TABLE `patients` ADD COLUMN `source_note` VARCHAR(150) NULL DEFAULT NULL AFTER `source`;

-- Existing patients: self-registered on the website → online; everyone
-- else was added by clinic staff → walk-in.
UPDATE `patients` p
  LEFT JOIN `users` u ON u.`id` = p.`user_id`
   SET p.`source` = IF(u.`pending_registration_id` IS NOT NULL, 'online', 'walk-in')
 WHERE p.`source` IS NULL;

-- The 1-year auto-deactivation is gone (a patient may return after 2–3
-- years), and patient status is no longer shown — so re-enable every
-- patient login and record it had switched off.
UPDATE `users` SET `is_active` = 1 WHERE `role` = 'patient' AND `is_active` = 0;
UPDATE `patients` SET `status` = 'active' WHERE `status` = 'inactive' AND `archived_at` IS NULL;

-- Check:
SELECT `source`, COUNT(*) AS patients FROM `patients` GROUP BY `source`;
