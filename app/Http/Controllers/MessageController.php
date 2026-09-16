<?php

namespace App\Http\Controllers;

use App\Models\MessageLog;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    public function index()
    {
        return view('messages.index', ['messages' => MessageLog::where('tenant_id', Auth::user()->tenant_id)->with('customer')->latest()->get()]);
    }
}
