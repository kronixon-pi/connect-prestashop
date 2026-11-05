<?php

declare(strict_types=1);

// The module's own autoloader loads src/ exactly as it does inside PrestaShop; composer only
// provides PHPUnit, the schema validator and the test namespace.
require dirname(__DIR__).'/tools/vendor/autoload.php';
require dirname(__DIR__).'/src/Autoloader.php';

Kronixon\Connect\Autoloader::register();
