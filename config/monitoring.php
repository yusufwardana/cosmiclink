<?php

return [
    'driver' => env('MONITORING_DRIVER', 'fake'),
    'simulation' => env('MONITORING_DRIVER', 'fake') === 'fake' && in_array(env('APP_ENV', 'local'), ['local', 'demo'], true),
];
