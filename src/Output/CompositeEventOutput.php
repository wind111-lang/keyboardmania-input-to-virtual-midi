<?php

declare(strict_types=1);

namespace KeyboardManiaInputToVirtualMidi\Output;

use KeyboardManiaInputToVirtualMidi\Contract\ControllerEventOutput;

readonly class CompositeEventOutput implements ControllerEventOutput
{
    /**
     * @param list<ControllerEventOutput> $outputs
     */
    public function __construct(private array $outputs)
    {
    }

    public function emit(array $payload): void
    {
        foreach ($this->outputs as $output) {
            $output->emit($payload);
        }
    }
}
