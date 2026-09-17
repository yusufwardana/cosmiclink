<?php

namespace App\Services\Network;

use App\Models\Router;

interface NetworkDiscoveryClient
{
    public function discover(Router $router): DiscoveryResult;
}
