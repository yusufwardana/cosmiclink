<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->customer_code, 'name' => $this->name, 'phone' => $this->phone, 'email' => $this->email, 'status' => $this->status, 'connections' => $this->whenLoaded('connections', fn () => $this->connections->map(fn ($connection) => ['id' => $connection->id, 'code' => $connection->connection_code, 'status' => $connection->status, 'router' => $connection->router?->name, 'network_account' => $connection->networkAccount?->status])), 'invoices' => $this->whenLoaded('invoices', fn () => $this->invoices->take(5)->values()->map(fn ($invoice) => ['id' => $invoice->id, 'number' => $invoice->invoice_number, 'status' => $invoice->status, 'outstanding' => $invoice->outstanding()])), 'payments' => $this->whenLoaded('payments', fn () => $this->payments->take(5)->values()->map(fn ($payment) => ['id' => $payment->id, 'reference' => $payment->payment_reference, 'amount' => $payment->amount, 'status' => $payment->status])), 'messages' => $this->whenLoaded('messageLogs', fn () => $this->messageLogs->take(5)->values()->map(fn ($message) => ['id' => $message->id, 'template' => $message->template, 'status' => $message->status, 'provider' => $message->provider])), 'outage_incidents' => $this->whenLoaded('outage_incidents', fn () => OutageIncidentResource::collection($this->outage_incidents)->resolve())];
    }
}
