<?php

return [
    'minimum_connections' => (int) env('OUTAGE_MINIMUM_CONNECTIONS', 3),
    'window_minutes' => (int) env('OUTAGE_WINDOW_MINUTES', 10),
];
