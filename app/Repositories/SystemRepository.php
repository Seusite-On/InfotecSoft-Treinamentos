<?php

namespace App\Repositories;

use App\Core\DB;

class SystemRepository
{
    public function all(): array
    {
        return DB::table('systems')->where('active', 1)->orderBy('position', 'ASC')->get();
    }

    public function exists(string $id): bool
    {
        return (bool) DB::table('systems')->where('system_id', $id)->where('active', 1)->first();
    }
}
