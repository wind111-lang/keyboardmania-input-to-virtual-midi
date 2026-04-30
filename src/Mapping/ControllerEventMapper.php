<?php

declare(strict_types=1);

namespace KeyboardManiaInputToVirtualMidi\Mapping;

use KeyboardManiaInputToVirtualMidi\Contract\ControllerEventOutput;
use KeyboardManiaInputToVirtualMidi\Output\JsonEventOutput;

class ControllerEventMapper
{
    private readonly array $keyMap;

    private readonly array $axisMap;

    private readonly array $buttonMap;

    private array $pressedNotes = [];

    private int $volume = 100;

    public function __construct(
        array $config,
        private readonly ControllerEventOutput $output = new JsonEventOutput(),
    ) {
        $this->keyMap = $this->normalizeStringMap($config['keys'] ?? []);
        $this->axisMap = $this->normalizeStringMap($config['axes'] ?? []);
        $this->buttonMap = $this->normalizeStringMap($config['buttons'] ?? []);
    }

    public function reset(): void
    {
        $this->pressedNotes = [];
    }

    public function handleButton(int $button, int $value, int $time): void
    {
        $buttonKey = (string) $button;

        if (isset($this->keyMap[$buttonKey])) {
            $this->handleKeyButton($button, $value, $time, $this->keyMap[$buttonKey]);
            return;
        }

        if (isset($this->buttonMap[$buttonKey])) {
            $this->handleControlButton($button, $value, $time, $this->buttonMap[$buttonKey]);
            return;
        }

        $this->emit([
            'type' => $value === 1 ? 'button_down' : 'button_up',
            'button' => $button,
            'value' => $value,
            'mapped' => false,
            'time_ms' => $time,
        ]);
    }

    public function handleAxis(int $axis, int $value, int $time): void
    {
        $axisKey = (string) $axis;

        if (!isset($this->axisMap[$axisKey])) {
            $this->emit([
                'type' => 'axis',
                'axis' => $axis,
                'value' => $value,
                'mapped' => false,
                'time_ms' => $time,
            ]);

            return;
        }

        $mapped = $this->axisMap[$axisKey];

        if ($mapped === 'volume') {
            $volume = $this->axisValueToVolume($value);

            if ($volume === $this->volume) {
                return;
            }

            $this->volume = $volume;

            $this->emit([
                'type' => 'volume_change',
                'axis' => $axis,
                'raw_value' => $value,
                'volume' => $this->volume,
                'time_ms' => $time,
            ]);

            return;
        }

        $this->emit([
            'type' => 'axis_mapped',
            'axis' => $axis,
            'mapping' => $mapped,
            'value' => $value,
            'time_ms' => $time,
        ]);
    }

    private function handleKeyButton(int $button, int $value, int $time, string $note): void
    {
        if ($value === 1) {
            if (isset($this->pressedNotes[$button])) {
                return;
            }

            $this->pressedNotes[$button] = $note;

            $this->emit([
                'type' => 'note_down',
                'button' => $button,
                'note' => $note,
                'volume' => $this->volume,
                'active_notes' => array_values($this->pressedNotes),
                'time_ms' => $time,
            ]);

            return;
        }

        if ($value === 0) {
            if (!isset($this->pressedNotes[$button])) {
                return;
            }

            unset($this->pressedNotes[$button]);

            $this->emit([
                'type' => 'note_up',
                'button' => $button,
                'note' => $note,
                'volume' => $this->volume,
                'active_notes' => array_values($this->pressedNotes),
                'time_ms' => $time,
            ]);

            return;
        }

        $this->emit([
            'type' => 'note_value',
            'button' => $button,
            'note' => $note,
            'value' => $value,
            'time_ms' => $time,
        ]);
    }

    private function handleControlButton(int $button, int $value, int $time, string $name): void
    {
        $this->emit([
            'type' => $value === 1 ? 'control_down' : 'control_up',
            'button' => $button,
            'control' => $name,
            'value' => $value,
            'time_ms' => $time,
        ]);
    }

    private function axisValueToVolume(int $value): int
    {
        // Axis inputs are normalized into the -32767 to 32767 range before mapping.
        $normalized = ($value + 32767) / 65534;

        if ($normalized < 0.0) {
            $normalized = 0.0;
        }

        if ($normalized > 1.0) {
            $normalized = 1.0;
        }

        return (int) round($normalized * 127);
    }

    private function normalizeStringMap(mixed $map): array
    {
        if (!is_array($map)) {
            return [];
        }

        $result = [];

        foreach ($map as $key => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $result[(string) $key] = $value;
        }

        return $result;
    }

    private function emit(array $payload): void
    {
        $this->output->emit($payload);
    }
}
