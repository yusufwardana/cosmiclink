<?php

namespace App\Services\Messaging;

class PhoneNormalizer
{
    public function normalize(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        } $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '0') && strlen($digits) >= 10 && strlen($digits) <= 13) {
            return '+62'.substr($digits, 1);
        } if (str_starts_with($digits, '62') && strlen($digits) >= 11 && strlen($digits) <= 14) {
            return '+'.$digits;
        }

        return null;
    }
}
