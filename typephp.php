<?php

return [
    'enabled' => true,
    'cache' => false,
    'include' => [
        __DIR__ . '/src/**',
    ],
    'exclude' => [
        __DIR__ . '/vendor/**',
        __DIR__ . '/tests/**',
        __DIR__ . '/var/**',
        __DIR__ . '/app/**',
        __DIR__ . '/internals/**',
        __DIR__ . '/cache/**',
        __DIR__ . '/storage/**',
    ],
];
