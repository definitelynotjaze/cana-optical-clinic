-- ================================================================
--  One-time: examination records can no longer be archived or deleted
--  (they're permanent medical records). This restores every examination
--  that was archived before that rule — plus the same visit's
--  consultation and prescription — and clears them out of Archives.
--  Run once in phpMyAdmin → SQL tab, locally and on Hostinger.
--  Safe to re-run (does nothing once they're restored).
-- ================================================================

UPDATE `consultations` c
  JOIN `examinations` e    ON e.`consultation_id` = c.`id`
  JOIN `archived_records` ar ON ar.`ref_id` = e.`id` AND ar.`type` = 'Examination'
   SET c.`archived_at` = NULL;

UPDATE `prescriptions` rx
  JOIN `archived_records` ar ON ar.`ref_id` = rx.`exam_id` AND ar.`type` = 'Examination'
   SET rx.`archived_at` = NULL;

UPDATE `examinations` e
  JOIN `archived_records` ar ON ar.`ref_id` = e.`id` AND ar.`type` = 'Examination'
   SET e.`archived_at` = NULL;

DELETE FROM `archived_records` WHERE `type` = 'Examination';

-- Check: should return 0.
SELECT COUNT(*) AS archived_examinations_left FROM `archived_records` WHERE `type` = 'Examination';
