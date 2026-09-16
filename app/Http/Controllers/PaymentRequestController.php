<?php

namespace App\Http\Controllers;

use App\Actions\ProcessPaymentProviderEvent;
use App\Models\PaymentRequest;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PaymentRequestController extends Controller
{
    public function show(PaymentRequest $paymentRequest)
    {
        Gate::authorize('view', $paymentRequest);
        $paymentRequest->load(['invoice', 'events']);

        return view('billing.payment-requests.show', compact('paymentRequest'));
    }

    public function simulateSuccess(PaymentRequest $paymentRequest, PaymentGateway $gateway, ProcessPaymentProviderEvent $process)
    {
        Gate::authorize('view', $paymentRequest);
        $process->handle($gateway->simulateSuccess($paymentRequest), Auth::user());

        return redirect()->route('billing.invoices.show', $paymentRequest->invoice_id)->with('status', 'Simulated payment verified.');
    }
}
