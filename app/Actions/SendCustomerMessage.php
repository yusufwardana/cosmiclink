<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\OutageIncident;
use App\Models\User;
use App\Services\Messaging\MessagingProvider;
use App\Services\Messaging\PhoneNormalizer;

class SendCustomerMessage
{
    public function __construct(private readonly MessagingProvider $provider, private readonly PhoneNormalizer $phones) {}

    public function handle(Customer $customer, string $template, array $data, User $user, ?Invoice $invoice = null, ?CustomerConnection $connection = null, ?string $idempotencyKey = null, ?OutageIncident $outageIncident = null): MessageLog
    {
        abort_unless($customer->tenant_id === $user->tenant_id, 403);
        $recipient = $this->phones->normalize($customer->phone);
        $content = $this->render($template, $data);
        $log = MessageLog::create(['tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id, 'invoice_id' => $invoice?->id, 'outage_incident_id' => $outageIncident?->id, 'customer_connection_id' => $connection?->id, 'channel' => 'whatsapp', 'provider' => config('messaging.provider'), 'recipient' => $recipient ?? 'invalid', 'template' => $template, 'rendered_content' => $content, 'status' => 'pending', 'attempted_at' => now(), 'idempotency_key' => $idempotencyKey]);
        if (! $recipient) {
            $log->update(['status' => 'skipped', 'failure_code' => 'INVALID_PHONE', 'failure_message' => 'Customer phone could not be normalized.']);

            return $log;
        } try {
            $result = $this->provider->sendMessage($recipient, $content);
        } catch (\Throwable $exception) {
            $result = ['successful' => false, 'provider_message_id' => null, 'error_code' => 'MESSAGE_PROVIDER_ERROR', 'message' => 'Message provider error.'];
        } $log->update($result['successful'] ? ['status' => 'sent', 'provider_message_id' => $result['provider_message_id'], 'sent_at' => now()] : ['status' => 'failed', 'failure_code' => $result['error_code'], 'failure_message' => $result['message'], 'failed_at' => now()]);

        return $log;
    }

    private function render(string $template, array $data): string
    {
        $templates = ['invoice_created' => 'Halo, :customer_name. Tagihan :invoice_number untuk :period sebesar Rp:amount telah diterbitkan.', 'payment_reminder' => 'Halo, :customer_name. Tagihan :invoice_number untuk :period sebesar Rp:outstanding belum dibayar. Jatuh tempo: :due_date.', 'payment_received' => 'Pembayaran sebesar Rp:amount telah diterima.', 'service_suspended' => 'Layanan internet sementara dinonaktifkan karena tagihan melewati jatuh tempo.', 'service_reactivated' => 'Pembayaran telah diterima dan layanan internet Anda telah aktif kembali.', 'outage_detected' => 'Halo, :customer_name. Layanan pada :connection_code sedang terdampak gangguan jaringan pada :router_name. Tim kami sedang memantau pemulihan.', 'outage_resolved' => 'Halo, :customer_name. Gangguan jaringan pada :connection_code di :router_name telah pulih.'];
        $content = $templates[$template] ?? throw new \InvalidArgumentException('Unknown message template.');
        foreach ($data as $key => $value) {
            $content = str_replace(':'.$key, is_int($value) ? number_format($value, 0, ',', '.') : $value, $content);
        }

        return $content;
    }
}
