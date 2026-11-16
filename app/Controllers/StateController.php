<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Services\StateService;

class StateController extends Controller
{
    public function show(): void
    {
        json_out((new StateService())->build());
    }
}
