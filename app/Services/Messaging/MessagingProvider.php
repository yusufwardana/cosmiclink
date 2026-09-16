<?php

namespace App\Services\Messaging;

interface MessagingProvider
{
    public function sendMessage(string $recipient, string $content): array;
}
