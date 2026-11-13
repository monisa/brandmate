<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** Page size for list endpoints: ?per_page=, capped at 100. */
    protected function perPage(Request $request, int $default = 20): int
    {
        return max(1, min(100, (int) $request->query('per_page', $default)));
    }
}
