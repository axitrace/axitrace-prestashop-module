<?php

declare(strict_types=1);

// Module classes exit when _PS_VERSION_ is undefined; define the PrestaShop constants they read
// before the autoloader loads any of them.
define('_PS_VERSION_', '8.1.7');
define('_DB_PREFIX_', 'ps_');

require __DIR__ . '/../vendor/autoload.php';
