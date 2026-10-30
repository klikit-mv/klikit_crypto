<?php

namespace App\Modules\Dashboard\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        return view('user.dashboard', ['overview' => $dashboard->overview($request->user())]);
    }
}
