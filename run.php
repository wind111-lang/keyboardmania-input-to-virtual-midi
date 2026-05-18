<?php

declare(strict_types=1);

use KeyboardManiaInputToVirtualMidi\Application\ConsoleApplication;

require __DIR__ . '/vendor/autoload.php';

exit((new ConsoleApplication(__DIR__))->run($_SERVER['argv']));