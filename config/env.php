<?php

declare(strict_types=1);

return [
    'app_env' => getenv('APP_ENV') ?: 'local',
    'debug' => (getenv('APP_DEBUG') ?: 'true') === 'true',
];
