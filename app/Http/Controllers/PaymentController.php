<?php

namespace App\Http\Controllers;

use App\Actions\RecordPayment;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function index()
    {
        return view('billing.payments.index', ['payments' => Payment::where('tenant_id', Auth::user()->tenant_id)->with(['invoice', 'customer'])->latest()->get()]);
    }

    public function store(Request $request, Invoice $invoice, RecordPayment $record)
    {
        Gate::authorize('view', $invoice);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'method' => ['required', 'in:cash,bank_transfer,manual'], 'payment_reference' => ['required', 'string', 'max:100'], 'notes' => ['nullable', 'string']]);
        try {
            $record->handle($invoice, $data['amount'], $data['method'], $data['payment_reference'], Auth::user());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Payment recorded.');
    }
}
