<?php

namespace App\Support\Network;

/**
 * A safe, non-secret projection of how a RouterOS target (PPP secret or active
 * session) is addressed on the router.
 *
 * RouterOS identifies sentences either by their transient `.id` (for example
 * `*7` or `=*7`), by a stable `name`, or - for simulation only - by a `sim:`
 * reference that never corresponds to a real router sentence. Nothing in this
 * value object carries credential material: it holds a reference form and a
 * deterministic fingerprint of that form, and it is deliberately comparable
 * across the Laravel/Go boundary using the same spelling rules.
 */
final readonly class RouterResourceIdentity
{
    public const TYPE_ROUTEROS_ID = 'ROUTEROS_ID';

    public const TYPE_NAME = 'NAME';

    public const TYPE_SIMULATED = 'SIMULATED';

    public const MAX_LENGTH = 128;

    private const SIMULATED_PREFIX = 'sim:';

    private const ROUTEROS_ID_PATTERN = '/^\*[0-9a-f]{1,6}$/';

    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._@+\-]{0,63}$/';

    private const SIMULATED_PATTERN = '/^sim:[A-Za-z0-9*][A-Za-z0-9._@+\-*:]{0,122}$/';

    private function __construct(
        public string $type,
        public string $ref,
    ) {}

    /**
     * Parse a RouterOS-visible reference. Returns null when the reference is
     * absent, unsafe, oversized, or expressed in a form this release refuses to
     * target (fail closed: no identity, no controlled action).
     */
    public static function parse(mixed $reference): ?self
    {
        if (! is_string($reference)) {
            return null;
        }

        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > self::MAX_LENGTH) {
            return null;
        }

        // Control characters, whitespace, quotes, and separators that could be
        // reinterpreted by a RouterOS sentence never become an identity.
        if (preg_match('/[\p{C}\s\'"`,;\\\\|&<>]/u', $reference) === 1) {
            return null;
        }

        if (preg_match(self::SIMULATED_PATTERN, $reference) === 1) {
            return new self(self::TYPE_SIMULATED, strtolower($reference));
        }

        // `.id` sentences are rendered as `*7` or `=*7`; both address the same
        // sentence, so they normalise to one spelling and one fingerprint.
        $normalised = str_starts_with($reference, '=') ? substr($reference, 1) : $reference;
        $normalised = strtolower($normalised);

        if (preg_match(self::ROUTEROS_ID_PATTERN, $normalised) === 1) {
            return new self(self::TYPE_ROUTEROS_ID, $normalised);
        }

        if (preg_match(self::NAME_PATTERN, $reference) === 1) {
            return new self(self::TYPE_NAME, $reference);
        }

        return null;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function ref(): string
    {
        return $this->ref;
    }

    public function isRouterOsId(): bool
    {
        return $this->type === self::TYPE_ROUTEROS_ID;
    }

    public function isNameFallback(): bool
    {
        return $this->type === self::TYPE_NAME;
    }

    public function isSimulated(): bool
    {
        return $this->type === self::TYPE_SIMULATED;
    }

    /**
     * True when the given reference addresses the same target as this identity.
     * Comparison is on the normalised fingerprint, so spellings of the same
     * RouterOS `.id` match while a name and an id never match each other.
     */
    public function matches(mixed $reference): bool
    {
        $other = self::parse($reference);

        return $other !== null && hash_equals($this->fingerprint(), $other->fingerprint());
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'identity_type' => $this->type,
            'identity_ref' => $this->ref,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{identity_type: string, identity_ref: string, identity_fingerprint: string}
     */
    public function projection(): array
    {
        return [
            'identity_type' => $this->type,
            'identity_ref' => $this->ref,
            'identity_fingerprint' => $this->fingerprint(),
        ];
    }
}
