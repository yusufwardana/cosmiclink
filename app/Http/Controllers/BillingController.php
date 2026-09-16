<?php

namespace App\Http\Controllers;

use App\Actions\GenerateMonthlyInvoices;
use App\Actions\MarkOverdueInvoices;
use App\Actions\ProcessOverdueBilling;
use App\Models\Invoice;
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
        $invoice->load(['customer', 'connection', 'items', 'payments', 'automationAttempts']);

        return view('billing.invoices.show', compact('invoice'));
    }

    public function generate(Request $request, GenerateMonthlyInvoices $generate)
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);
        $count = $generate->handle(Auth::user()->tenant_id, Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth());

        return back()->with('status', "Generated {$count} invoice(s).");
    }

    public function overdue(MarkOverdueInvoices $mark, ProcessOverdueBilling $process)
    {
        $tenantId = Auth::user()->tenant_id;
        $marked = $mark->handle($tenantId);
        $processed = $process->handle($tenantId, Auth::user());

        return back()->with('status', "Marked {$marked} overdue; processed {$processed} connection(s).");
    }
}
