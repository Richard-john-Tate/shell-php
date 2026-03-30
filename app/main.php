<?php

declare(strict_types=1);

error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

use App\Shell;

$shell = new Shell();
$shell->run();
