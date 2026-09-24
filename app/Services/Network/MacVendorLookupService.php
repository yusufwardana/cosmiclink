<?php

namespace App\Services\Network;

use App\Models\MacVendorRegistry;

final class MacVendorLookupService
{
    public function normalize(?string $mac): ?string
    {
        $value = strtoupper((string) $mac);
        $value = preg_replace('/[^0-9A-F]/', '', $value);

        return is_string($value) && preg_match('/\A[0-9A-F]{12}\z/', $value) === 1 ? $value : null;
    }

    public function lookup(?string $mac): ?MacVendorRegistry
    {
        $normalized = $this->normalize($mac);
        if (! $normalized) {
            return null;
        }

        foreach ([36, 28, 24] as $length) {
            $prefix = substr($normalized, 0, intdiv($length, 4));
            $match = MacVendorRegistry::query()
                ->where('prefix_length', $length)
                ->where('prefix', $prefix)
                ->orderByDesc('id')
                ->first();
            if ($match) {
                return $match;
            }
        }

        return null;
    }
}