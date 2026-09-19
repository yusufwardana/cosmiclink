<?php

namespace App\Services\Network;

/**
 * Bounded reason-code vocabulary shared by the controlled-gate decisions,
 * managed target context, action audit, and (in later tasks) the Go preflight
 * payloads. Codes are stable strings so evidence can be compared across the
 * Laravel/Go boundary without inventing per-call messages.
 *
 * Every code is a denial unless it is APPROVED; the gate is default-deny, so a
 * missing or unrecognised signal must resolve to a code here rather than to an
 * ad-hoc message.
 */
final class ControlledOperationReason
{
    /** Every ordered precondition was satisfied and the action may proceed. */
    public const APPROVED = 'APPROVED';

    public const MUTATIONS_DISABLED = 'MUTATIONS_DISABLED';

    public const TENANT_MISMATCH = 'TENANT_MISMATCH';

    public const OPERATION_NOT_ALLOWED = 'OPERATION_NOT_ALLOWED';

    public const NOT_MANAGED = 'NOT_MANAGED';

    public const MODE_NOT_AUTHORISED = 'MODE_NOT_AUTHORISED';

    public const RECONCILIATION_NOT_MATCHED = 'RECONCILIATION_NOT_MATCHED';

    public const RELATIONSHIP_MISMATCH = 'RELATIONSHIP_MISMATCH';

    public const TARGET_NOT_FOUND = 'TARGET_NOT_FOUND';

    public const IDENTITY_MISSING = 'IDENTITY_MISSING';

    public const IDENTITY_MISMATCH = 'IDENTITY_MISMATCH';

    public const IDENTITY_STALE = 'IDENTITY_STALE';

    public const IDENTITY_TYPE_UNSUPPORTED = 'IDENTITY_TYPE_UNSUPPORTED';

    public const AMBIGUOUS_ACCOUNT_IDENTITY = 'AMBIGUOUS_ACCOUNT_IDENTITY';

    public const PREFLIGHT_MISSING = 'PREFLIGHT_MISSING';

    public const PREFLIGHT_STALE = 'PREFLIGHT_STALE';

    public const PREFLIGHT_NOT_READY = 'PREFLIGHT_NOT_READY';

    public const PRE_FLIGHT_IDENTITY_MISMATCH = 'PRE_FLIGHT_IDENTITY_MISMATCH';

    public const DISCOVERY_STALE = 'DISCOVERY_STALE';

    public const COMPATIBILITY_UNKNOWN = 'COMPATIBILITY_UNKNOWN';

    public const PROVIDER_UNSUPPORTED = 'PROVIDER_UNSUPPORTED';

    public const VERSION_MISSING = 'VERSION_MISSING';

    public const VERSION_UNSUPPORTED = 'VERSION_UNSUPPORTED';

    public const ROUTER_HEALTH_UNKNOWN = 'ROUTER_HEALTH_UNKNOWN';

    public const CONCURRENT_OPERATION = 'CONCURRENT_OPERATION';

    public const SCOPE_NOT_CONFIRMED = 'SCOPE_NOT_CONFIRMED';

    public const MANAGEMENT_REVOKED = 'MANAGEMENT_REVOKED';

    /**
     * Ordered lifecycle preconditions from the Phase 6i plan (PRE1 first,
     * PRE12 last). A denial records which one failed.
     */
    public const PRE1_MUTATIONS_ENABLED = 'PRE1';

    public const PRE2_TENANT_SCOPE = 'PRE2';

    public const PRE3_RELATIONSHIP_COMPLETE = 'PRE3';

    public const PRE4_RESOURCE_ADOPTED = 'PRE4';

    public const PRE5_USERNAME_CANONICAL = 'PRE5';

    public const PRE6_CONNECTION_BOUND = 'PRE6';

    public const PRE7_RECONCILIATION = 'PRE7';

    public const PRE8_TARGET_IDENTITY = 'PRE8';

    public const PRE9_ROUTER_COMPATIBILITY = 'PRE9';

    public const PRE10_MODE_AUTHORISED = 'PRE10';

    public const PRE11_ROUTER_HEALTH = 'PRE11';

    public const PRE12_NO_CONCURRENT_MUTATION = 'PRE12';

    /**
     * @return list<string>
     */
    public static function preconditions(): array
    {
        return [
            self::PRE1_MUTATIONS_ENABLED,
            self::PRE2_TENANT_SCOPE,
            self::PRE3_RELATIONSHIP_COMPLETE,
            self::PRE4_RESOURCE_ADOPTED,
            self::PRE5_USERNAME_CANONICAL,
            self::PRE6_CONNECTION_BOUND,
            self::PRE7_RECONCILIATION,
            self::PRE8_TARGET_IDENTITY,
            self::PRE9_ROUTER_COMPATIBILITY,
            self::PRE10_MODE_AUTHORISED,
            self::PRE11_ROUTER_HEALTH,
            self::PRE12_NO_CONCURRENT_MUTATION,
        ];
    }
}
