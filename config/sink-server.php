<?php

use App\Http\Middleware\AuthenticateConsoleOrLocal;

return [
    'ui_middleware' => ['web', AuthenticateConsoleOrLocal::class, 'verified'],
];
