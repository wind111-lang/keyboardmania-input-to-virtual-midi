<?php

declare(strict_types=1);

namespace KeyboardManiaInputToVirtualMidi\Output;

use KeyboardManiaInputToVirtualMidi\Contract\ControllerEventOutput;

class JsonEventOutput implements ControllerEventOutput
{
    public function emit(array $payload): void
    {
        echo json_encode($payload, flags: JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
