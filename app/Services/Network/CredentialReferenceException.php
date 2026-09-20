<?php

namespace App\Services\Network;

use InvalidArgumentException;

final class CredentialReferenceException extends InvalidArgumentException
{
    public static function invalid(): self
    {
        return new self('Credential reference is invalid.');
    }
}