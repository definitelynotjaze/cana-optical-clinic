<?php
// ================================================================
//  CANAOPTICALCLINIC — api/previous_records/delete.php
//  Retired. Previous Clinic Records are archived first now
//  (api/previous_records/archive.php), and only an admin deletes one for
//  good, from Settings > Archives (api/archive/delete.php). Kept so an
//  old copy of this endpoint on the server can't skip that step.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

jsonResponse(['success' => false, 'message' => 'Records are archived first now. An admin can delete one for good from Settings > Archives.'], 410);
