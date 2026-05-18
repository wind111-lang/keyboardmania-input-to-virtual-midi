<?php

declare(strict_types=1);

namespace KeyboardManiaInputToVirtualMidi\Contract;

interface ControllerEventOutput
{
    /**
     * @param array<string, mixed> $payload
     */
    public function emit(array $payload): void;
}
