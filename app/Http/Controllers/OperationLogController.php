<?php

namespace App\Http\Controllers;

use App\Models\NetworkOperationLog;
use Illuminate\Support\Facades\Auth;

class OperationLogController extends Controller
{
    public function index()
    {
        return view('network.logs', ['logs' => NetworkOperationLog::where('tenant_id', Auth::user()->tenant_id)->with('router')->latest('created_at')->get()]);
    }
}
