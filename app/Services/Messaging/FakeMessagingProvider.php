<?php

namespace App\Services\Messaging;

use App\Models\MessageLog;

class FakeMessagingProvider implements MessagingProvider
{
    public function sendMessage(string $recipient, string $content): array
    {
        if (str_contains($recipient, '999')) {
            return ['successful' => false, 'provider_message_id' => null, 'error_code' => 'SIMULATED_MESSAGE_FAILURE', 'message' => 'Simulated messaging failure.'];
        }

return ['successful' => true, 'provider_message_id' => 'MSG-DEMO-'.str_pad((string) (MessageLog::count() + 1), 6, '0', STR_PAD_LEFT)];
    }
}
