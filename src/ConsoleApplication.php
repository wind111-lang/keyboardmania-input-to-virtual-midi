<?php

declare(strict_types=1);

namespace KeyboardManiaInputToVirtualMidi;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class ConsoleApplication
{
    private const int DEFAULT_VENDOR_ID = 0x0507;
    private const int DEFAULT_PRODUCT_ID = 0x0010;
    private const string DEFAULT_MIDI_SOURCE = 'KeyboardMania Virtual MIDI';

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

            $config = $this->loadConfig($options['config']);

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
     *     config: string,
     *     vendor_id: int,
     *     product_id: int,
     *     output: string,
     *     midi_source: string,
     *     midi_channel: int,
     *     help: bool
     * }
     */
    private function parseArguments(array $argv): array
    {
        $options = [
            'config' => $this->projectRoot . '/config/keymap.json',
            'vendor_id' => self::DEFAULT_VENDOR_ID,
            'product_id' => self::DEFAULT_PRODUCT_ID,
            'output' => 'midi',
            'midi_source' => self::DEFAULT_MIDI_SOURCE,
            'midi_channel' => 1,
            'help' => false,
        ];

        for ($index = 1; $index < count($argv); $index++) {
            $argument = $argv[$index];

            if ($argument === '-h' || $argument === '--help') {
                $options['help'] = true;
                continue;
            }

            if ($argument === '-o' || $argument === '--output') {
                $options['output'] = $this->outputMode(
                    $this->readOptionValue($argv, $index, $argument),
                );
                continue;
            }

            if (str_starts_with($argument, '--output=')) {
                $options['output'] = $this->outputMode(
                    substr($argument, strlen('--output=')),
                );
                continue;
            }

            if ($argument === '-c' || $argument === '--config') {
                $options['config'] = $this->readOptionValue($argv, $index, $argument);
                continue;
            }

            if (str_starts_with($argument, '--config=')) {
                $options['config'] = $this->nonEmptyOptionValue(
                    substr($argument, strlen('--config=')),
                    '--config',
                );
                continue;
            }

            if ($argument === '--vendor-id') {
                $options['vendor_id'] = $this->readIntegerOptionValue($argv, $index, $argument);
                continue;
            }

            if (str_starts_with($argument, '--vendor-id=')) {
                $options['vendor_id'] = $this->integerOptionValue(
                    substr($argument, strlen('--vendor-id=')),
                    '--vendor-id',
                );
                continue;
            }

            if ($argument === '--product-id') {
                $options['product_id'] = $this->readIntegerOptionValue($argv, $index, $argument);
                continue;
            }

            if (str_starts_with($argument, '--product-id=')) {
                $options['product_id'] = $this->integerOptionValue(
                    substr($argument, strlen('--product-id=')),
                    '--product-id',
                );
                continue;
            }

            if ($argument === '--midi-source') {
                $options['midi_source'] = $this->readOptionValue($argv, $index, $argument);
                continue;
            }

            if (str_starts_with($argument, '--midi-source=')) {
                $options['midi_source'] = $this->nonEmptyOptionValue(
                    substr($argument, strlen('--midi-source=')),
                    '--midi-source',
                );
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

            throw new InvalidArgumentException("Unknown argument: {$argument}");
        }

        return $options;
    }

    /**
     * @param array{
     *     vendor_id: int,
     *     product_id: int,
     *     output: string,
     *     midi_source: string,
     *     midi_channel: int
     * } $options
     * @param array<mixed> $config
     */
    private function runInput(array $options, array $config): never
    {
        $runner = new HidInputToVirtualMidi(
            $options['vendor_id'],
            $options['product_id'],
            $config,
            $this->createOutput($options),
        );

        $runner->run();
    }

    /**
     * @param array{output: string, midi_source: string, midi_channel: int} $options
     */
    private function createOutput(array $options): ControllerEventOutput
    {
        $output = $options['output'];

        return match ($output) {
            'json' => new JsonEventOutput(),
            'midi' => new CoreMidiOutput(
                $options['midi_source'],
                $options['midi_channel'],
            ),
            'both' => new CompositeEventOutput([
                new JsonEventOutput(),
                new CoreMidiOutput(
                    $options['midi_source'],
                    $options['midi_channel'],
                ),
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

    private function outputMode(string $value): string
    {
        $value = $this->nonEmptyOptionValue($value, '--output');

        if (!in_array($value, ['json', 'midi', 'both'], true)) {
            throw new InvalidArgumentException(
                "Invalid output mode: {$value}. Expected json, midi, or both.",
            );
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
     * @return array<mixed>
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
        $defaultConfig = $this->projectRoot . '/config/keymap.json';

        return <<<USAGE
Usage:
  php run.php [--output json|midi|both] [--config PATH]

Options:
  -o, --output MODE   Output: json, midi, or both (default: midi)
  -c, --config PATH   Keymap JSON file (default: {$defaultConfig})
      --vendor-id ID  HID USB vendor ID (default: 0x0507)
      --product-id ID HID USB product ID (default: 0x0010)
      --midi-source N CoreMIDI source name (default: KeyboardMania Virtual MIDI)
      --midi-channel N MIDI channel, 1-16 (default: 1)
  -h, --help          Show this help

Examples:
  php run.php
  php run.php --output midi
  php run.php --output both

The command waits if the selected HID device does not exist yet.

USAGE;
    }
}
