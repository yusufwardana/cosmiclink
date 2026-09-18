<?php

namespace App\Services\Network;

use App\Models\NetworkAgent;
use InvalidArgumentException;

class NetworkAgentCompatibilityService
{
    private function valid(string $version): bool
    {
        return preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-((?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $version) === 1;
    }

    private function compare(string $left, string $right): int
    {
        [$a, $ap] = array_pad(explode('-', explode('+', $left)[0], 2), 2, null);
        [$b, $bp] = array_pad(explode('-', explode('+', $right)[0], 2), 2, null);
        $number = static fn (string $x, string $y): int => (strlen($x) <=> strlen($y)) ?: strcmp($x, $y);
        foreach (array_map(null, explode('.', $a), explode('.', $b)) as [$x, $y]) {
            if ($result = $number($x, $y)) {
                return $result;
            }
        }
        if ($ap === null || $bp === null) {
            return ($ap === null) <=> ($bp === null);
        }
        $aa = explode('.', $ap);
        $bb = explode('.', $bp);
        foreach ($aa as $i => $x) {
            if (! isset($bb[$i])) {
                return 1;
            }
            $y = $bb[$i];
            $result = ctype_digit($x) && ctype_digit($y) ? $number($x, $y)
                : (ctype_digit($x) !== ctype_digit($y) ? (ctype_digit($y) <=> ctype_digit($x)) : strcmp($x, $y));
            if ($result) {
                return $result;
            }
        }

        return count($aa) <=> count($bb);
    }

    public function compatibility(NetworkAgent $agent): string
    {
        $minimum = config('network_agents.minimum_version');
        $recommended = config('network_agents.recommended_version');
        if (! is_string($minimum) || ! is_string($recommended) || ! $this->valid($minimum) || ! $this->valid($recommended) || $this->compare($minimum, $recommended) > 0) {
            throw new InvalidArgumentException('Invalid Network Agent version policy.');
        }
        if (in_array($agent->version, config('network_agents.legacy_versions'), true)) {
            return 'UPDATE_AVAILABLE';
        }
        if (! is_string($agent->version) || ! $this->valid($agent->version) || $this->compare($agent->version, $minimum) < 0) {
            return 'UNSUPPORTED';
        }

        return $this->compare($agent->version, $recommended) < 0 ? 'UPDATE_AVAILABLE' : 'CURRENT';
    }

    public function canDiscover(NetworkAgent $agent, bool $retry = false): bool
    {
        if ($this->compatibility($agent) === 'UNSUPPORTED' || ! in_array('discovery.routeros.readonly', $agent->capabilities ?? [], true)) {
            return false;
        }

        return ! $retry || ($this->valid($agent->version)
            && $this->compare($agent->version, config('network_agents.lease_minimum_version')) >= 0
            && in_array('jobs.lease.v1', $agent->capabilities ?? [], true));
    }
}
