# keyboardmania-input-to-virtual-midi

Linux joystick device input reader for Keyboardmania-style controllers.

## Setup

```sh
composer install
```

## Usage

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use KeyboardManiaInputToVirtualMidi\KeyboardManiaInputToVirtualMidi;

$config = json_decode(
    file_get_contents(__DIR__ . '/config/keymap.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

$app = new KeyboardManiaInputToVirtualMidi('/dev/input/js0', $config);
$app->run();
```

The program emits one JSON object per input event.
