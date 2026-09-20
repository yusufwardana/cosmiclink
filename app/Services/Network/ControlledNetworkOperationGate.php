<?php

namespace App\Services\Network;

use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\NetworkAccount;
use App\Models\NetworkAccountTransition;
use App\Models\ReconciliationEvidence;
use App\Models\Router;
use App\Models\User;

class ControlledNetworkOperationGate
{
    private const ALLOWED_OPERATIONS = [
        'ENABLE_PPPOE',
        'DISABLE_PPPOE',
        'DISCONNECT_SESSION',
    ];

    public function __construct(
        private readonly ManagedTargetIdentityService $targetIdentity,
        private readonly RouterCompatibilityEvidenceService $compatibility,
        private readonly RouterHealthSafetyQuery $health,
        private readonly NetworkOperationSafetyQuery $operationSafety,
    ) {}

    public function check(User $user, NetworkAccount $account, string $operation): ControlledNetworkOperationDecision
    {
        if (! config('network.mutations_enabled', false)) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::MUTATIONS_DISABLED);
        }

        if ($user->tenant_id !== $account->tenant_id) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::TENANT_MISMATCH);
        }

        if (! $user->isNetworkOperator()) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::OPERATION_NOT_ALLOWED);
        }

        if (! in_array($operation, self::ALLOWED_OPERATIONS, true)) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::OPERATION_NOT_ALLOWED);
        }

        if ($account->management_state !== NetworkAccountTransition::STATE_MANAGED) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::NOT_MANAGED);
        }
        if ($account->management_scope !== ManagedAccountLifecycleService::MANAGEMENT_SCOPE
            || ! in_array($operation, $account->management_scope['operations'] ?? [], true)) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::SCOPE_NOT_CONFIRMED);
        }

        $router = $account->router;
        if (! $router instanceof Router || $router->tenant_id !== $account->tenant_id) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::RELATIONSHIP_MISMATCH, ControlledOperationReason::PRE3_RELATIONSHIP_COMPLETE);
        }

        $resource = DiscoveredNetworkResource::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('router_id', $router->id)
            ->where('network_account_id', $account->id)
            ->where('management_state', NetworkAccountTransition::STATE_ADOPTED)
            ->latest('id')
            ->first();
        if ($resource === null || $resource->management_state !== NetworkAccountTransition::STATE_ADOPTED) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::TARGET_NOT_FOUND, ControlledOperationReason::PRE4_RESOURCE_ADOPTED);
        }

        $username = mb_strtolower(trim((string) $account->username));
        $resourceUsername = mb_strtolower(trim((string) ($resource->normalized_data['username'] ?? $resource->name)));
        if ($username === '' || $username !== $resourceUsername) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::IDENTITY_MISMATCH, ControlledOperationReason::PRE5_USERNAME_CANONICAL);
        }

        $connection = CustomerConnection::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('router_id', $router->id)
            ->where('network_account_id', $account->id)
            ->first();
        if ($connection === null) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::RELATIONSHIP_MISMATCH, ControlledOperationReason::PRE6_CONNECTION_BOUND);
        }

        $identity = $this->targetIdentity->resolve($account);

        $reconciliation = ReconciliationEvidence::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('router_id', $router->id)
            ->where('network_account_id', $account->id)
            ->where('adopted_resource_id', $resource->id)
            ->latest('reconciled_at')
            ->first();
        if ($reconciliation === null || ! $reconciliation->isCurrentlyApplicable()) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::RECONCILIATION_NOT_MATCHED, ControlledOperationReason::PRE7_RECONCILIATION)
                ->withEvidence(['identity' => $identity->projection()]);
        }

        if (! $identity->resolved) {
            return ControlledNetworkOperationDecision::deny($identity->reasonCode, ControlledOperationReason::PRE8_TARGET_IDENTITY)
                ->withEvidence(['identity' => $identity->projection()]);
        }

        $preflight = $this->targetIdentity->preflightEvidenceIsCurrent($account, $operation);
        if (! $preflight->resolved) {
            return ControlledNetworkOperationDecision::deny($preflight->reasonCode, ControlledOperationReason::PRE8_TARGET_IDENTITY)
                ->withEvidence([
                    'identity' => $identity->projection(),
                    'preflight' => $preflight->projection(),
                ]);
        }

        $compatibility = $this->compatibility->evaluate($router);
        if (! $compatibility->compatible) {
            return ControlledNetworkOperationDecision::deny($compatibility->reason, ControlledOperationReason::PRE9_ROUTER_COMPATIBILITY)
                ->withEvidence(['identity' => $identity->projection(), 'preflight' => $preflight->projection()]);
        }
        if ($compatibility->executionMode !== 'simulation' || ! $compatibility->canAuthorizeRealMutation()) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::MODE_NOT_AUTHORISED, ControlledOperationReason::PRE10_MODE_AUTHORISED)
                ->withEvidence(['identity' => $identity->projection(), 'preflight' => $preflight->projection()]);
        }
        if (! $this->health->allows($router)) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::ROUTER_HEALTH_UNKNOWN, ControlledOperationReason::PRE11_ROUTER_HEALTH);
        }
        if ($this->operationSafety->hasUnresolvedState($account)) {
            return ControlledNetworkOperationDecision::deny(ControlledOperationReason::CONCURRENT_OPERATION, ControlledOperationReason::PRE12_NO_CONCURRENT_MUTATION);
        }

        return ControlledNetworkOperationDecision::allow()->withEvidence([
            'identity' => $identity->projection(),
            'preflight' => $preflight->projection(),
        ]);
    }

    public function allowedOperations(): array
    {
        return self::ALLOWED_OPERATIONS;
    }
}
