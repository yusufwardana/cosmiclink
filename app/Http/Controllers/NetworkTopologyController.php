<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class NetworkTopologyController extends Controller
{
    public function __invoke(): View
    {
        return view('network.topology');
    }
}