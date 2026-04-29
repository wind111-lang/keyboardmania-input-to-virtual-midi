<?php

declare(strict_types=1);

namespace KeyboardManiaInputToVirtualMidi;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class ConsoleApplication
{
    private const string DEFAULT_DEVICE_PATH = '/dev/input/js0';

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

            $runner = new KeyboardManiaInputToVirtualMidi(
                $options['device'],
                $config,
            );

            $runner->run();
        } catch (JsonException $exception) {
            return $this->fail("Failed to parse keymap JSON: {$exception->getMessage()}");
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $this->fail($exception->getMessage());
        }
    }

    /**
     * @param list<string> $argv
     * @return array{device: string, config: string, help: bool}
     */
    private function parseArguments(array $argv): array
    {
        $options = [
            'device' => self::DEFAULT_DEVICE_PATH,
            'config' => $this->projectRoot . '/config/keymap.json',
            'help' => false,
        ];

        for ($index = 1; $index < count($argv); $index++) {
            $argument = $argv[$index];

            if ($argument === '-h' || $argument === '--help') {
                $options['help'] = true;
                continue;
            }

            if ($argument === '-d' || $argument === '--device') {
                $options['device'] = $this->readOptionValue($argv, $index, $argument);
                continue;
            }

            if (str_starts_with($argument, '--device=')) {
                $options['device'] = $this->nonEmptyOptionValue(
                    substr($argument, strlen('--device=')),
                    '--device',
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

            throw new InvalidArgumentException("Unknown argument: {$argument}");
        }

        return $options;
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

    private function nonEmptyOptionValue(string $value, string $option): string
    {
        if ($value === '') {
            throw new InvalidArgumentException("Missing value for {$option}");
        }

        return $value;
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
  php run.php [--device PATH] [--config PATH]

Options:
  -d, --device PATH   Linux joystick device to read (default: /dev/input/js0)
  -c, --config PATH   Keymap JSON file (default: {$defaultConfig})
  -h, --help          Show this help

Examples:
  php run.php
  php run.php --device /dev/input/js1

The command waits if the device does not exist yet.

USAGE;
    }
}
