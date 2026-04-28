<?php

declare(strict_types=1);

namespace KeyboardManiaInputToVirtualMidi;

class KeyboardManiaInputToVirtualMidi
{
    private const int JS_EVENT_BUTTON = 0x01;
    private const int JS_EVENT_AXIS = 0x02;
    private const int JS_EVENT_INIT = 0x80;
    private const int DEVICE_RETRY_SECONDS = 1;

    private readonly string $devicePath;

    private readonly array $keyMap;

    private readonly array $axisMap;

    private readonly array $buttonMap;

    private array $pressedNotes = [];

    private int $volume = 100;

    public function __construct(string $devicePath, array $config)
    {
        $this->devicePath = $devicePath;
        $this->keyMap = $this->normalizeStringMap($config['keys'] ?? []);
        $this->axisMap = $this->normalizeStringMap($config['axes'] ?? []);
        $this->buttonMap = $this->normalizeStringMap($config['buttons'] ?? []);
    }

    public function run(): never
    {
        fwrite(STDERR, "Press Ctrl+C to stop.\n\n");

        while (true) {
            $deviceHandle = $this->waitForDevice();

            fwrite(STDERR, "Reading controller input: {$this->devicePath}\n");

            $this->readEvents($deviceHandle);

            if (is_resource($deviceHandle)) {
                fclose($deviceHandle);
            }

            $this->pressedNotes = [];

            fwrite(STDERR, "Controller input stopped. Waiting for device to return...\n");
        }
    }

    private function waitForDevice(): mixed
    {
        $reportedWaiting = false;

        while (true) {
            $handle = @fopen($this->devicePath, 'rb');

            if ($handle !== false) {
                stream_set_blocking($handle, true);
                return $handle;
            }

            if (!$reportedWaiting) {
                fwrite(STDERR, "Waiting for controller input device: {$this->devicePath}\n");
                $reportedWaiting = true;
            }

            sleep(self::DEVICE_RETRY_SECONDS);
        }
    }

    private function readEvents(mixed $deviceHandle): void
    {
        while (!feof($deviceHandle)) {
            $data = fread($deviceHandle, 8);

            if ($data === false || $data === '') {
                break;
            }

            if (strlen($data) !== 8) {
                continue;
            }

            $event = unpack('Vtime/svalue/Ctype/Cnumber', $data);

            if ($event === false) {
                continue;
            }

            $time = (int) $event['time'];
            $value = (int) $event['value'];
            $type = (int) $event['type'];
            $number = (int) $event['number'];

            $isInitial = (bool) ($type & self::JS_EVENT_INIT);
            $type = $type & ~self::JS_EVENT_INIT;

            if ($isInitial) {
                $this->emit([
                    'type' => 'init',
                    'input_type' => $this->inputTypeName($type),
                    'number' => $number,
                    'value' => $value,
                    'time_ms' => $time,
                ]);

                continue;
            }

            if ($type === self::JS_EVENT_BUTTON) {
                $this->handleButton($number, $value, $time);
                continue;
            }

            if ($type === self::JS_EVENT_AXIS) {
                $this->handleAxis($number, $value, $time);
                continue;
            }

            $this->emit([
                'type' => 'unknown',
                'input_type' => $type,
                'number' => $number,
                'value' => $value,
                'time_ms' => $time,
            ]);
        }
    }

    private function handleButton(int $button, int $value, int $time): void
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

    private function handleAxis(int $axis, int $value, int $time): void
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

    private function axisValueToVolume(int $value): int
    {
        // Linux joystick axes are normally in the -32767 to 32767 range.
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
        echo json_encode($payload, flags: JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private function inputTypeName(int $type): string
    {
        return match ($type) {
            self::JS_EVENT_BUTTON => 'button',
            self::JS_EVENT_AXIS => 'axis',
            default => 'unknown',
        };
    }
}
