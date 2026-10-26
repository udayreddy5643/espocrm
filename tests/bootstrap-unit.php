<?php

$vendorAutoload = '/var/www/html/vendor/autoload.php';

if (!file_exists($vendorAutoload)) {
    $vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $vendorAutoload;

$baseDir = dirname(__DIR__);

$loader->addPsr4('tests\\unit\\', __DIR__ . '/unit/');
$loader->addPsr4('tests\\integration\\', __DIR__ . '/integration/');
$loader->addPsr4('EspoDev\\', $baseDir . '/dev/');
$loader->addPsr4('Espo\\Custom\\', $baseDir . '/custom/Espo/Custom/', true);
$loader->addPsr4('Espo\\', $baseDir . '/application/Espo/', true);
