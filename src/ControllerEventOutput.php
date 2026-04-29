<?php

declare(strict_types=1);

namespace KeyboardManiaInputToVirtualMidi;

interface ControllerEventOutput
{
    /**
     * @param array<mixed> $payload
     */
    public function emit(array $payload): void;
}
