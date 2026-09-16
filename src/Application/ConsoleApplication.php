<?php

declare(strict_types=1);

namespace KeyboardManiaInputToVirtualMidi\Application;

use KeyboardManiaInputToVirtualMidi\Contract\ControllerEventOutput;
use KeyboardManiaInputToVirtualMidi\Input\HidInputToVirtualMidi;
use KeyboardManiaInputToVirtualMidi\Output\CompositeEventOutput;
use KeyboardManiaInputToVirtualMidi\Output\CoreMidiOutput;
use KeyboardManiaInputToVirtualMidi\Output\JsonEventOutput;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

readonly class ConsoleApplication
{
    private const string MIDI_SOURCE = 'KeyboardMania Virtual MIDI';
    private const string KEYMAP_PATH = '/config/keymap.json';

    public function __construct(private string $projectRoot)
    {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        try {
            $options = $this->parseArguments($argv);

            if ($options['help']) {
                fwrite(STDOUT, $this->usage());
                return 0;
            }

            if ($options['test_note'] !== null) {
                $this->runMidiTest($options);
            }

            $config = $this->loadConfig($options['config']);

            if ($options['dump_hid']) {
                $this->runHidDump($options, $config);
            }

            $this->runInput($options, $config);
        } catch (JsonException $exception) {
            return $this->fail("Failed to parse keymap JSON: {$exception->getMessage()}");
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $this->fail($exception->getMessage());
        }
    }

    /**
     * @param list<string> $argv
     * @return array{
     *     output: 'json'|'midi'|'both',
     *     config: string,
     *     vendor_id: int|null,
     *     product_id: int,
     *     midi_source: string,
     *     midi_channel: int,
     *     test_note: string|null,
     *     debug_events: bool,
     *     dump_hid: bool,
     *     dump_hid_snapshots: bool,
     *     help: bool
     * }
     */
    private function parseArguments(array $argv): array
    {
        $options = [
            'output' => 'midi',
            'config' => $this->projectRoot . self::KEYMAP_PATH,
            'vendor_id' => null,
            'product_id' => 0x0010,
            'midi_source' => self::MIDI_SOURCE,
            'midi_channel' => 1,
            'test_note' => null,
            'debug_events' => false,
            'dump_hid' => false,
            'dump_hid_snapshots' => false,
            'help' => false,
        ];

        for ($index = 1; $index < count($argv); $index++) {
            $argument = $argv[$index];

            if ($argument === '-h' || $argument === '--help') {
                $options['help'] = true;
                continue;
            }

            if ($argument === '-o' || $argument === '--output') {
                $options['output'] = $this->outputMode($this->readOptionValue($argv, $index, $argument));
                continue;
            }

            if (str_starts_with($argument, '--output=')) {
                $options['output'] = $this->outputMode(substr($argument, strlen('--output=')));
                continue;
            }

            if ($argument === '-c' || $argument === '--config') {
                $options['config'] = $this->readOptionValue($argv, $index, $argument);
                continue;
            }

            if (str_starts_with($argument, '--config=')) {
                $options['config'] = $this->nonEmptyOptionValue(substr($argument, strlen('--config=')), '--config');
                continue;
            }

            foreach (['--vendor-id' => 'vendor_id', '--product-id' => 'product_id'] as $option => $key) {
                if ($argument === $option) {
                    $options[$key] = $this->readIntegerOptionValue($argv, $index, $option);
                    continue 2;
                }
                if (str_starts_with($argument, $option . '=')) {
                    $options[$key] = $this->integerOptionValue(substr($argument, strlen($option) + 1), $option);
                    continue 2;
                }
            }

            if ($argument === '--midi-source') {
                $options['midi_source'] = $this->readOptionValue($argv, $index, $argument);
                continue;
            }

            if (str_starts_with($argument, '--midi-source=')) {
                $options['midi_source'] = $this->nonEmptyOptionValue(substr($argument, strlen('--midi-source=')), '--midi-source');
                continue;
            }

            if ($argument === '--midi-channel') {
                $options['midi_channel'] = $this->midiChannel(
                    $this->readIntegerOptionValue($argv, $index, $argument),
                );
                continue;
            }

            if (str_starts_with($argument, '--midi-channel=')) {
                $options['midi_channel'] = $this->midiChannel(
                    $this->integerOptionValue(
                        substr($argument, strlen('--midi-channel=')),
                        '--midi-channel',
                    ),
                );
                continue;
            }

            if ($argument === '--test-note') {
                $options['test_note'] = $this->midiNoteName(
                    $this->readOptionValue($argv, $index, $argument),
                    $argument,
                );
                continue;
            }

            if (str_starts_with($argument, '--test-note=')) {
                $options['test_note'] = $this->midiNoteName(
                    substr($argument, strlen('--test-note=')),
                    '--test-note',
                );
                continue;
            }

            if ($argument === '--debug-events') {
                $options['debug_events'] = true;
                continue;
            }

            if ($argument === '--dump-hid') {
                $options['dump_hid'] = true;
                continue;
            }

            if ($argument === '--dump-hid-snapshots') {
                $options['dump_hid'] = true;
                $options['dump_hid_snapshots'] = true;
                continue;
            }

            throw new InvalidArgumentException("Unknown argument: {$argument}");
        }

        return $options;
    }

    /**
     * @param array{
     *     output: 'json'|'midi'|'both',
     *     midi_source: string,
     *     vendor_id: int|null,
     *     product_id: int,
     *     midi_channel: int,
     *     debug_events: bool,
     *     dump_hid: bool,
     *     dump_hid_snapshots: bool
     * } $options
     * @param array<string, mixed> $config
     */
    private function runInput(array $options, array $config): never
    {
        $runner = new HidInputToVirtualMidi(
            $config,
            $this->createOutput($options),
            $options['dump_hid'] || $options['debug_events'],
            $options['dump_hid_snapshots'],
            $options['vendor_id'],
            $options['product_id'],
        );

        $runner->run();
    }

    /**
     * @param array{
     *     vendor_id: int|null,
     *     product_id: int,
     *     dump_hid: bool,
     *     dump_hid_snapshots: bool
     * } $options
     * @param array<string, mixed> $config
     */
    private function runHidDump(array $options, array $config): never
    {
        $runner = new HidInputToVirtualMidi(
            $config,
            null,
            $options['dump_hid'],
            $options['dump_hid_snapshots'],
            $options['vendor_id'],
            $options['product_id'],
        );

        $runner->run();
    }

    /**
     * @param array{midi_source: string, midi_channel: int, test_note: string, debug_events: bool} $options
     */
    private function runMidiTest(array $options): never
    {
        $output = new CoreMidiOutput(
            $options['midi_source'],
            $options['midi_channel'],
            $options['debug_events'],
        );
        $note = $options['test_note'];

        fwrite(
            STDERR,
            sprintf(
                "Sending test note %s on MIDI channel %d. Press Ctrl+C to stop.\n",
                $note,
                $options['midi_channel'],
            ),
        );

        while (true) {
            $output->emit([
                'type' => 'note_down',
                'note' => $note,
                'volume' => 100,
                'time_ms' => $this->timeMilliseconds(),
            ]);
            usleep(300_000);

            $output->emit([
                'type' => 'note_up',
                'note' => $note,
                'volume' => 100,
                'time_ms' => $this->timeMilliseconds(),
            ]);
            usleep(700_000);
        }
    }

    /**
     * @param array{output: 'json'|'midi'|'both', midi_source: string, midi_channel: int, debug_events: bool} $options
     */
    private function createOutput(array $options): ControllerEventOutput
    {
        return match ($options['output']) {
            'json' => new JsonEventOutput(),
            'midi' => new CoreMidiOutput($options['midi_source'], $options['midi_channel'], $options['debug_events']),
            // JSONはJsonEventOutputが担当し、CoreMIDI側での二重出力を避ける。
            'both' => new CompositeEventOutput([
                new JsonEventOutput(),
                new CoreMidiOutput($options['midi_source'], $options['midi_channel']),
            ]),
        };
    }

    /**
     * @param list<string> $argv
     */
    private function readOptionValue(array $argv, int &$index, string $option): string
    {
        $index++;

        if (!isset($argv[$index])) {
            throw new InvalidArgumentException("Missing value for {$option}");
        }

        return $this->nonEmptyOptionValue($argv[$index], $option);
    }

    /**
     * @param list<string> $argv
     */
    private function readIntegerOptionValue(array $argv, int &$index, string $option): int
    {
        return $this->integerOptionValue(
            $this->readOptionValue($argv, $index, $option),
            $option,
        );
    }

    private function nonEmptyOptionValue(string $value, string $option): string
    {
        if ($value === '') {
            throw new InvalidArgumentException("Missing value for {$option}");
        }

        return $value;
    }

    /**
     * @return 'json'|'midi'|'both'
     */
    private function outputMode(string $value): string
    {
        $value = $this->nonEmptyOptionValue($value, '--output');
        if (!in_array($value, ['json', 'midi', 'both'], true)) {
            throw new InvalidArgumentException("Invalid output mode: {$value}. Expected json, midi, or both.");
        }
        return $value;
    }

    private function midiChannel(int $value): int
    {
        if ($value < 1 || $value > 16) {
            throw new InvalidArgumentException('MIDI channel must be between 1 and 16.');
        }

        return $value;
    }

    private function midiNoteName(string $value, string $option): string
    {
        $value = $this->nonEmptyOptionValue($value, $option);

        if (!preg_match('/^([A-Ga-g])([#b]?)(-?\d+)$/', $value, $matches)) {
            throw new InvalidArgumentException(
                "Invalid value for {$option}: {$value}. Expected a note like C4 or F#3.",
            );
        }

        $base = match (strtoupper($matches[1])) {
            'C' => 0,
            'D' => 2,
            'E' => 4,
            'F' => 5,
            'G' => 7,
            'A' => 9,
            'B' => 11,
            default => throw new InvalidArgumentException(
                "Invalid value for {$option}: {$value}. Expected a note like C4 or F#3.",
            ),
        };
        $accidental = match ($matches[2]) {
            '#' => 1,
            'b' => -1,
            default => 0,
        };
        $octave = (int) $matches[3];
        $noteNumber = (($octave + 1) * 12) + $base + $accidental;

        if ($noteNumber < 0 || $noteNumber > 127) {
            throw new InvalidArgumentException(
                "Invalid value for {$option}: {$value}. MIDI note is outside 0-127.",
            );
        }

        return strtoupper($matches[1]) . $matches[2] . $matches[3];
    }

    private function integerOptionValue(string $value, string $option): int
    {
        $value = $this->nonEmptyOptionValue($value, $option);

        if (str_starts_with(strtolower($value), '0x')) {
            $hex = substr($value, 2);

            if ($hex === '' || !ctype_xdigit($hex)) {
                throw new InvalidArgumentException("Invalid value for {$option}: {$value}");
            }

            return (int) hexdec($hex);
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($parsed === false) {
            throw new InvalidArgumentException("Invalid value for {$option}: {$value}");
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(string $configPath): array
    {
        if (!is_file($configPath)) {
            throw new RuntimeException("Keymap config not found: {$configPath}");
        }

        $contents = file_get_contents($configPath);

        if ($contents === false) {
            throw new RuntimeException("Failed to read keymap config: {$configPath}");
        }

        $config = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($config)) {
            throw new RuntimeException("Keymap config must be a JSON object: {$configPath}");
        }

        return $config;
    }

    private function fail(string $message): int
    {
        fwrite(STDERR, "Error: {$message}\n\n");
        fwrite(STDERR, $this->usage());

        return 1;
    }

    private function usage(): string
    {
        return <<<USAGE
Usage:
  php run.php [--output json|midi|both] [--config PATH]
  php run.php --test-note NOTE
  php run.php --dump-hid [--dump-hid-snapshots]

Options:
  -o, --output MODE   Output: json, midi, or both (default: midi)
  -c, --config PATH   Keymap JSON file (default: project config/keymap.json)
      --vendor-id ID  Match Vendor ID instead of manufacturer KONAMI
      --product-id ID HID USB product ID (default: 0x0010)
      --midi-source N CoreMIDI source name (default: KeyboardMania Virtual MIDI)
      --midi-channel N MIDI channel, 1-16 (default: 1)
      --debug-events Also print HID and mapped events in the selected output mode
      --test-note N   Send a repeating CoreMIDI test note without reading HID input
      --dump-hid      Print raw HID initial values and changes without MIDI output
      --dump-hid-snapshots
                     Also print one raw HID snapshot per second
  -h, --help          Show this help

Examples:
  php run.php
  php run.php --output both
  php run.php --output json --config config/keymap.json
  php run.php --debug-events
  php run.php --test-note C4
  php run.php --dump-hid
  php run.php --dump-hid --dump-hid-snapshots

The command waits until a KeyboardMania controller is detected.

USAGE;
    }

    private function timeMilliseconds(): int
    {
        return (int) round(hrtime(true) / 1_000_000);
    }
}
