<?php

declare(strict_types=1);

/**
 * mod_rewrite probe.
 *
 * The installer requests /__rewrite_probe; .htaccess rewrites that to this
 * file. Reaching it proves rewriting actually works, which reading phpinfo()
 * does not — plenty of hosts load the module but ignore .htaccess.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode(['rewrite' => true]);
