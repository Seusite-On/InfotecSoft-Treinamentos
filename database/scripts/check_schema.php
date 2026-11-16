<?php
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\DB;

foreach (['systems', 'modules', 'lessons', 'users', 'user_permissions', 'user_module_access', 'migrations'] as $t) {
    $n = DB::select("SELECT COUNT(*) AS n FROM `$t`")[0]['n'] ?? 0;
    printf("%-20s %6d registro(s)\n", $t, $n);
}
