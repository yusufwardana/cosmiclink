<?php

namespace App\Console\Commands;

use App\Services\Network\AcceptanceRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RecoverAcceptance extends Command
{
    protected $signature = 'network-agents:recover-acceptance {--confirm=}';

    protected $description = 'Recover vacant Phase 6I Core scope from a locally verified installation manifest on stdin';

    public function handle(AcceptanceRecoveryService $recovery): int
    {
        try {
            if ($this->option('confirm') !== 'RECOVER_PHASE6I_ACCEPTANCE'
                || DB::connection()->getPdo()->query('select current_database()')->fetchColumn() !== 'cosmiclink_phase6i_acceptance') {
                $this->error('Exact acceptance database and recovery confirmation required.');

                return self::FAILURE;
            }
            $manifest = json_decode(stream_get_contents(STDIN, 4097), true, 16, JSON_THROW_ON_ERROR);
            $result = $recovery->recover($manifest);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // Never render exceptions, query bindings or stdin containing tokens.
            $this->error('Acceptance recovery refused; verify vacant scope, manifest and safe defaults.');

            return self::FAILURE;
        }
    }
}
