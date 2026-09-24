<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class NetworkMapController extends Controller
{
    public function __invoke(): View
    {
        return view('network.map');
    }
}