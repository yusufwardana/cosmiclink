<?php

namespace App\Console\Commands;

use App\Models\NetworkAgent;
use App\Services\Network\NetworkAgentService;
use Illuminate\Console\Command;

class RotateNetworkAgentToken extends Command
{
    protected $signature = 'network-agents:rotate-token {agent : Existing Agent identifier or database ID} {data-dir : Existing Agent data directory} {helper : Native DPAPI token-store helper path}';

    protected $description = 'Rotate an existing Agent bearer credential in place';

    public function handle(NetworkAgentService $agents): int
    {
        $value = (string) $this->argument('agent');
        $agent = NetworkAgent::query()
            ->where('identifier', $value)
            ->when(ctype_digit($value), fn ($query) => $query->orWhereKey((int) $value))
            ->first();

        if (! $agent) {
            $this->error('Existing Agent not found.');

            return self::FAILURE;
        }

        $oldTokenId = $agent->token_id;
        $oldTokenHash = $agent->token_hash;
        [$rotated, $token] = $agents->rotate($agent);
        $process = proc_open(
            [$this->argument('helper'), $this->argument('data-dir')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );

        if (! is_resource($process)) {
            $rotated->forceFill(['token_id' => $oldTokenId, 'token_hash' => $oldTokenHash])->save();
            $this->error('Agent token provisioning failed.');

            return self::FAILURE;
        }

        fwrite($pipes[0], $token);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        unset($token);

        if ($exitCode !== 0) {
            $rotated->forceFill(['token_id' => $oldTokenId, 'token_hash' => $oldTokenHash])->save();
            $this->error('Agent token provisioning failed.');

            return self::FAILURE;
        }

        $this->info('Agent token rotated and provisioned securely.');

        return self::SUCCESS;
    }
}