<?php

declare(strict_types=1);

namespace {
    require __DIR__ . '/../vendor/autoload.php';
}

// OS境界だけをテストダブルに置き換え、実機や仮想MIDIソースを必要としない。
namespace KeyboardManiaInputToVirtualMidi\Output {
    final class CoreMidiOutput implements \KeyboardManiaInputToVirtualMidi\Contract\ControllerEventOutput
    {
        public static array $instances = [];
        public array $events = [];
        public static bool $stopAfterEmit = false;

        public function __construct(public string $sourceName, public int $channel = 1, public bool $debugEvents = false)
        {
            self::$instances[] = $this;
        }

        public function emit(array $payload): void
        {
            $this->events[] = $payload;
            if ($this->debugEvents) {
                echo json_encode($payload, JSON_THROW_ON_ERROR) . "\n";
            }
            if (self::$stopAfterEmit) {
                throw new \LogicException('test loop stopped');
            }
        }
    }
}

namespace KeyboardManiaInputToVirtualMidi\Input {
    class HidInputToVirtualMidi
    {
        public static ?self $last = null;

        public function __construct(
            public array $config,
            public ?\KeyboardManiaInputToVirtualMidi\Contract\ControllerEventOutput $output,
            public bool $dumpRawHid = false,
            public bool $dumpRawHidSnapshots = false,
            public ?int $vendorId = null,
            public int $productId = 0x0010,
        ) {
            self::$last = $this;
        }

        public function run(): never
        {
            throw new \LogicException('test loop stopped');
        }
    }
}

namespace {
    use KeyboardManiaInputToVirtualMidi\Application\ConsoleApplication;
    use KeyboardManiaInputToVirtualMidi\Input\HidInputToVirtualMidi;
    use KeyboardManiaInputToVirtualMidi\Mapping\ControllerEventMapper;
    use KeyboardManiaInputToVirtualMidi\Output\CoreMidiOutput;

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $root = dirname(__DIR__);
    $app = new ConsoleApplication($root);
    $parse = new ReflectionMethod($app, 'parseArguments');
    $factory = new ReflectionMethod($app, 'createOutput');

    $defaults = $parse->invoke($app, ['run.php']);
    check($defaults['output'] === 'midi', 'Default output must remain MIDI.');
    check($defaults['vendor_id'] === null && $defaults['product_id'] === 16, 'Default device must use manufacturer matching.');
    check($defaults['config'] === $root . '/config/keymap.json', 'Default keymap path changed.');

    foreach (['json', 'midi', 'both'] as $mode) {
        foreach ([['--output', $mode], ['-o', $mode], ['--output=' . $mode]] as $arguments) {
            foreach ([false, true] as $debug) {
                $options = $parse->invoke($app, ['run.php', ...$arguments, ...($debug ? ['--debug-events'] : [])]);
                CoreMidiOutput::$instances = [];
                $output = $factory->invoke($app, $options);
                check(count(CoreMidiOutput::$instances) === ($mode === 'json' ? 0 : 1), 'Incorrect MIDI output count.');

                // JSONのキー割り当てからnote_down / note_upを生成し、両出力先を確認。
                $mapper = new ControllerEventMapper(['keys' => ['0' => 'C4']], $output);
                ob_start();
                $mapper->handleButton(0, 1, 100);
                $mapper->handleButton(0, 0, 200);
                $json = ob_get_clean();
                $expectJson = $mode !== 'midi' || $debug;
                $lines = $json === '' ? [] : explode("\n", trim($json));
                check(count($lines) === ($expectJson ? 2 : 0), 'JSON events are missing or duplicated.');
                if ($expectJson) {
                    $events = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
                    check(array_column($events, 'type') === ['note_down', 'note_up'], 'Incorrect mapped JSON events.');
                    check(array_column($events, 'note') === ['C4', 'C4'], 'Keymap was not applied.');
                }
                if ($mode !== 'json') {
                    check(array_column(CoreMidiOutput::$instances[0]->events, 'type') === ['note_down', 'note_up'], 'MIDI did not receive both events.');
                }
            }
        }
    }

    $fixture = __DIR__ . '/fixtures/keymap.json';
    foreach ([
        ['--output', 'both', '-c', $fixture, '--midi-source', 'Custom Source', '--midi-channel', '2', '--vendor-id', '0x0507', '--product-id', '0x0020'],
        ['--output=both', '--config=' . $fixture, '--midi-source=Custom Source', '--midi-channel=2', '--vendor-id=1287', '--product-id=32'],
        ['-o', 'both', '--config', $fixture, '--midi-source=Custom Source', '--midi-channel=2', '--vendor-id=0x0507', '--product-id=0x0020'],
    ] as $arguments) {
        CoreMidiOutput::$instances = [];
        try {
            $app->run(['run.php', ...$arguments, '--debug-events']);
            throw new RuntimeException('Expected the HID input loop.');
        } catch (LogicException $exception) {
            check($exception->getMessage() === 'test loop stopped', 'Unexpected input failure.');
        }
        $input = HidInputToVirtualMidi::$last;
        check($input->config['keys'][0] === 'F#4', 'Custom config was not loaded.');
        check($input->config['axes'][0] === 'volume' && $input->config['buttons'][13] === 'select', 'Axis/button settings were lost.');
        check($input->vendorId === 1287 && $input->productId === 32, 'Device IDs were not forwarded.');
        check($input->dumpRawHid, 'Debug HID output was lost.');
        check(CoreMidiOutput::$instances[0]->sourceName === 'Custom Source', 'MIDI source name was not forwarded.');
        check(CoreMidiOutput::$instances[0]->channel === 2, 'MIDI channel was not forwarded.');
    }

    CoreMidiOutput::$instances = [];
    try {
        $app->run(['run.php', '--output=both', '--dump-hid-snapshots', '--product-id=32']);
    } catch (LogicException) {
    }
    check(CoreMidiOutput::$instances === [], 'HID dump must not create a MIDI source.');
    check(HidInputToVirtualMidi::$last->output === null && HidInputToVirtualMidi::$last->dumpRawHidSnapshots, 'HID snapshot behavior changed.');
    check(HidInputToVirtualMidi::$last->productId === 32, 'HID dump lost the device override.');

    CoreMidiOutput::$instances = [];
    CoreMidiOutput::$stopAfterEmit = true;
    try {
        $app->run(['run.php', '--test-note=C4', '--output=json', '--midi-source=Test Source']);
    } catch (LogicException) {
    }
    check(count(CoreMidiOutput::$instances) === 1 && CoreMidiOutput::$instances[0]->sourceName === 'Test Source', 'Test-note must use the selected MIDI source.');
    check(CoreMidiOutput::$instances[0]->events[0]['note'] === 'C4', 'Test note changed.');

    foreach ([
        ['--output'], ['-o'], ['--output='], ['--output=invalid'],
        ['--config'], ['-c'], ['--config='], ['--midi-source'], ['--midi-source='],
        ['--vendor-id'], ['--vendor-id=xyz'], ['--product-id='], ['--product-id=-1'],
        ['--midi-channel=17'], ['--unknown'],
    ] as $arguments) {
        try {
            $parse->invoke($app, ['run.php', ...$arguments]);
            throw new RuntimeException('Invalid arguments were accepted: ' . implode(' ', $arguments));
        } catch (InvalidArgumentException) {
        }
    }

    fwrite(STDOUT, "PASS: CLI modes, aliases, custom config/source/IDs, event routing, debug combinations and validation.\n");
}
