<?php
/* POST /api/import-finish.php — close a batch and prune what is left of it.
 *
 *   { batch_id: 3 }
 *   -> { batch: { id, filename, total_parsed, promoted, status } }
 *
 * THE PRUNING IS THE POINT. A draft still in the queue is a copy of a phone
 * number out of somebody's contacts export that has already been looked at and
 * not wanted; keeping it is keeping exactly what deleting the uploaded .vcf was
 * for. The batch row survives its drafts (schema.sql) so the import is still in
 * the record afterwards.
 *
 * public/import.php's "Done importing" button is a plain form POST that does
 * the same thing without a script; assets/import.js intercepts it and comes
 * here so that a failure shows a snackbar instead of a whole error page. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/import.php';

require_login_api();
require_same_origin();
require_method('POST');

$in      = json_body();
$batchId = (int) ($in['batch_id'] ?? 0);
if ($batchId <= 0) {
    json_error('batch_required', 422, 'No import was named.');
}

try {
    $finished = import_finish($batchId);
} catch (Throwable $e) {
    error_log('import-finish: ' . $e->getMessage());
    json_error('finish_failed', 500, 'That import could not be closed.');
}

if (!$finished) {
    json_error('batch_gone', 409, 'That import is not there any more.');
}

json_out(array('batch' => import_batch($batchId)));
