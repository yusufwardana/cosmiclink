<?php

namespace App\Console\Commands;

use App\Models\MacVendorRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ImportMacVendorRegistry extends Command
{
    protected $signature = 'network:mac-vendors:import {file : Local registry/manuf file} {--source= : Dataset source label}';
    protected $description = 'Import a local MAC prefix vendor registry for offline lookup';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $source = (string) ($this->option('source') ?: 'local-registry');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Registry file is not readable.');
            return self::FAILURE;
        }

        $records = [];
        $handle = fopen($path, 'rb');
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (preg_match('/^([0-9A-F:]+)(?:\/(24|28|36))?\s+([^#\t]+)(?:\s+#.*)?$/i', $line, $match) !== 1) continue;
            $prefix = strtoupper(preg_replace('/[^0-9A-F]/i', '', $match[1]));
            $length = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : strlen($prefix) * 4;
            if (! in_array($length, [24, 28, 36], true)) continue;
            $prefix = substr($prefix, 0, intdiv($length, 4));
            $vendor = trim($match[3]);
            if ($vendor === '') continue;
            $records[$prefix.'|'.$length] = compact('prefix', 'length', 'vendor');
        }
        fclose($handle);

        if ($records === []) {
            throw new RuntimeException('No supported MAC vendor records found.');
        }

        foreach (array_chunk(array_values($records), 500) as $batch) {
            $now = now();
            $rows = array_map(fn (array $record): array => [
                'prefix' => $record['prefix'],
                'prefix_length' => $record['length'],
                'vendor' => $record['vendor'],
                'source' => $source,
                'metadata' => json_encode(['imported_by' => 'network:mac-vendors:import'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ], $batch);
            DB::table('mac_vendor_registry')->upsert($rows, ['prefix', 'prefix_length', 'source'], ['vendor', 'metadata', 'updated_at']);
        }

        $this->info('Imported '.count($records).' MAC vendor prefixes from '.$source.'.');
        return self::SUCCESS;
    }
}