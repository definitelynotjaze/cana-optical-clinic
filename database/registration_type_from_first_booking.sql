-- ================================================================
--  One-time: set Registration Type = Online for patients marked Walk-in
--  whose FIRST appointment was booked online by the patient themselves
--  (appointments.source = 'online'). They registered and booked through
--  the website, even if they already have examination records since.
--  Patients whose first appointment was booked by clinic staff stay Walk-in;
--  Transfer and Referral patients are untouched.
--  Run once in phpMyAdmin → SQL tab (after database/patient_source.sql).
-- ================================================================

UPDATE `patients` pt
   SET pt.`source` = 'online'
 WHERE pt.`source` = 'walk-in'
   AND (
     SELECT a.`source` FROM `appointments` a
      WHERE a.`patient_id` = pt.`id`
      ORDER BY a.`date`, STR_TO_DATE(a.`time`, '%l:%i %p')
      LIMIT 1
   ) = 'online';

-- Check:
SELECT `source`, COUNT(*) AS patients FROM `patients` WHERE `archived_at` IS NULL GROUP BY `source`;
