<?php

namespace App\Services\Network;

enum CredentialPurpose: string
{
    case OBSERVER = 'OBSERVER';
    case OPERATOR = 'OPERATOR';
}