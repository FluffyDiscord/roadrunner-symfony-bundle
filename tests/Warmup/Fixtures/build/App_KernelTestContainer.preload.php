<?php

// Fixture mirroring the header + class-list format Symfony's PhpDumper generates
// (see var/cache/prod/App_Kernel*Container.preload.php in any Symfony app).

use Symfony\Component\DependencyInjection\Dumper\Preloader;

if (in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
    return;
}

require dirname(__DIR__, 3).'/vendor/autoload.php';

$classes = [];
$classes[] = 'App\Kernel';
$classes[] = 'App\Controller\HomepageController';
$classes[] = 'Symfony\Component\HttpFoundation\Response';

$preloaded = Preloader::preload($classes);
