<?php

namespace App\Http\Controllers;

use App\Actions\GenerateMonthlyInvoices;
use App\Actions\MarkOverdueInvoices;
use App\Actions\ProcessOverdueBilling;
use App\Actions\SendPaymentReminder;
use App\Models\Invoice;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class BillingController extends Controller
{
    public function index()
    {
        return view('billing.invoices.index', ['invoices' => Invoice::where('tenant_id', Auth::user()->tenant_id)->with(['customer', 'connection'])->latest()->get()]);
    }

    public function show(Invoice $invoice)
    {
        Gate::authorize('view', $invoice);
        $invoice->load(['customer', 'connection', 'items', 'payments', 'automationAttempts', 'paymentRequests.events']);

        return view('billing.invoices.show', compact('invoice'));
    }

    public function generate(Request $request, GenerateMonthlyInvoices $generate)
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);
        $count = $generate->handle(Auth::user()->tenant_id, Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth(), Auth::user());

        return back()->with('status', "Generated {$count} invoice(s).");
    }

    public function overdue(MarkOverdueInvoices $mark, ProcessOverdueBilling $process)
    {
        $tenantId = Auth::user()->tenant_id;
        $marked = $mark->handle($tenantId);
        $processed = $process->handle($tenantId, Auth::user());

        return back()->with('status', "Marked {$marked} overdue; processed {$processed} connection(s).");
    }

    public function paymentRequest(Invoice $invoice, PaymentGateway $gateway)
    {
        Gate::authorize('view', $invoice);
        $request = $gateway->createPaymentRequest($invoice);

        return redirect()->route('billing.payment-requests.show', $request)->with('status', 'Simulated payment request ready.');
    }

    public function reminder(Invoice $invoice, SendPaymentReminder $reminder)
    {
        Gate::authorize('view', $invoice);
        $message = $reminder->handle($invoice, Auth::user());

        return back()->with('status', $message ? 'Payment reminder '.$message->status.'.' : 'No reminder sent for this invoice.');
    }
}
