<?php

declare(strict_types=1);

namespace KeyboardManiaInputToVirtualMidi;

use FFI;
use RuntimeException;
use Throwable;

final class CoreMidiOutput implements ControllerEventOutput
{
    private const int CF_STRING_ENCODING_UTF8 = 0x08000100;
    private const int MIDI_NOTE_OFF = 0x80;
    private const int MIDI_NOTE_ON = 0x90;
    private const int MIDI_CONTROL_CHANGE = 0xB0;
    private const int MIDI_ALL_NOTES_OFF = 123;

    private readonly FFI $ffi;

    private readonly int $midiChannel;

    private int $client = 0;

    private int $source = 0;

    /**
     * @var array<int, true>
     */
    private array $activeNotes = [];

    /**
     * @var array<string, true>
     */
    private array $reportedInvalidNotes = [];

    /**
     * @var list<mixed>
     */
    private array $retainedCoreFoundationValues = [];

    public function __construct(
        private readonly string $sourceName,
        int $channel = 1,
    ) {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new RuntimeException('CoreMIDI output is only available on macOS.');
        }

        if ($channel < 1 || $channel > 16) {
            throw new RuntimeException('MIDI channel must be between 1 and 16.');
        }

        $this->midiChannel = $channel - 1;
        $this->ffi = $this->createFfi();
        $this->createVirtualSource();

        register_shutdown_function($this->panic(...));

        fwrite(STDERR, "Created CoreMIDI source: {$this->sourceName}\n");
    }

    public function emit(array $payload): void
    {
        $type = $payload['type'] ?? null;

        if ($type !== 'note_down' && $type !== 'note_up') {
            return;
        }

        $noteName = (string) ($payload['note'] ?? '');
        $noteNumber = $this->noteNameToMidiNumber($noteName);

        if ($noteNumber === null) {
            $this->reportInvalidNote($noteName);
            return;
        }

        if ($type === 'note_down') {
            $velocity = $this->clampSevenBit((int) ($payload['volume'] ?? 100));
            $this->sendNoteOn($noteNumber, max(1, $velocity));
            return;
        }

        $this->sendNoteOff($noteNumber);
    }

    public function panic(): void
    {
        foreach (array_keys($this->activeNotes) as $noteNumber) {
            $this->sendMessage([
                self::MIDI_NOTE_OFF | $this->midiChannel,
                $noteNumber,
                0,
            ]);
        }

        $this->activeNotes = [];
        $this->sendMessage([
            self::MIDI_CONTROL_CHANGE | $this->midiChannel,
            self::MIDI_ALL_NOTES_OFF,
            0,
        ]);
    }

    private function createFfi(): FFI
    {
        if (!extension_loaded('FFI')) {
            throw new RuntimeException('PHP FFI extension is required for CoreMIDI output.');
        }

        $cdef = <<<'CDEF'
typedef const struct __CFString * CFStringRef;
typedef unsigned int MIDIClientRef;
typedef unsigned int MIDIEndpointRef;
typedef int OSStatus;
typedef unsigned long long MIDITimeStamp;
typedef unsigned int UInt32;
typedef unsigned short UInt16;
typedef unsigned char Byte;
typedef struct MIDIPacket {
    MIDITimeStamp timeStamp;
    UInt16 length;
    Byte data[256];
} MIDIPacket;
typedef struct MIDIPacketList {
    UInt32 numPackets;
    MIDIPacket packet[1];
} MIDIPacketList;
CFStringRef CFStringCreateWithCString(const void *alloc, const char *cStr, unsigned int encoding);
OSStatus MIDIClientCreate(CFStringRef name, void *notifyProc, void *notifyRefCon, MIDIClientRef *outClient);
OSStatus MIDISourceCreate(MIDIClientRef client, CFStringRef name, MIDIEndpointRef *outSrc);
void MIDIReceived(MIDIEndpointRef src, const MIDIPacketList *pktlist);
CDEF;

        try {
            return FFI::cdef($cdef, '/System/Library/Frameworks/CoreMIDI.framework/CoreMIDI');
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Failed to load CoreMIDI through PHP FFI: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    private function createVirtualSource(): void
    {
        $name = $this->ffi->CFStringCreateWithCString(
            null,
            $this->sourceName,
            self::CF_STRING_ENCODING_UTF8,
        );
        $client = $this->ffi->new('MIDIClientRef[1]');
        $source = $this->ffi->new('MIDIEndpointRef[1]');

        $clientStatus = $this->ffi->MIDIClientCreate($name, null, null, $client);

        if ($clientStatus !== 0) {
            throw new RuntimeException(
                sprintf('Failed to create CoreMIDI client: 0x%08x', $clientStatus),
            );
        }

        $sourceStatus = $this->ffi->MIDISourceCreate($client[0], $name, $source);

        if ($sourceStatus !== 0) {
            throw new RuntimeException(
                sprintf('Failed to create CoreMIDI source: 0x%08x', $sourceStatus),
            );
        }

        $this->client = $client[0];
        $this->source = $source[0];
        $this->retainedCoreFoundationValues = [$name, $client, $source];
    }

    private function sendNoteOn(int $noteNumber, int $velocity): void
    {
        $this->activeNotes[$noteNumber] = true;
        $this->sendMessage([
            self::MIDI_NOTE_ON | $this->midiChannel,
            $noteNumber,
            $velocity,
        ]);
    }

    private function sendNoteOff(int $noteNumber): void
    {
        unset($this->activeNotes[$noteNumber]);
        $this->sendMessage([
            self::MIDI_NOTE_OFF | $this->midiChannel,
            $noteNumber,
            0,
        ]);
    }

    /**
     * @param list<int> $bytes
     */
    private function sendMessage(array $bytes): void
    {
        if ($this->source === 0) {
            return;
        }

        $packetList = $this->ffi->new('MIDIPacketList');
        $packetList->numPackets = 1;
        $packetList->packet[0]->timeStamp = 0;
        $packetList->packet[0]->length = count($bytes);

        foreach ($bytes as $index => $byte) {
            $packetList->packet[0]->data[$index] = $this->clampSevenBit($byte);
        }

        // Status bytes are 8-bit values, so restore the first byte after seven-bit clamping.
        $packetList->packet[0]->data[0] = $bytes[0] & 0xFF;

        $this->ffi->MIDIReceived($this->source, FFI::addr($packetList));
    }

    private function noteNameToMidiNumber(string $noteName): ?int
    {
        if (!preg_match('/^([A-Ga-g])([#b]?)(-?\d+)$/', $noteName, $matches)) {
            return null;
        }

        $base = match (strtoupper($matches[1])) {
            'C' => 0,
            'D' => 2,
            'E' => 4,
            'F' => 5,
            'G' => 7,
            'A' => 9,
            'B' => 11,
        };
        $accidental = match ($matches[2]) {
            '#' => 1,
            'b' => -1,
            default => 0,
        };
        $octave = (int) $matches[3];
        $noteNumber = (($octave + 1) * 12) + $base + $accidental;

        if ($noteNumber < 0 || $noteNumber > 127) {
            return null;
        }

        return $noteNumber;
    }

    private function clampSevenBit(int $value): int
    {
        if ($value < 0) {
            return 0;
        }

        if ($value > 127) {
            return 127;
        }

        return $value;
    }

    private function reportInvalidNote(string $noteName): void
    {
        if (isset($this->reportedInvalidNotes[$noteName])) {
            return;
        }

        $this->reportedInvalidNotes[$noteName] = true;
        fwrite(STDERR, "Ignoring invalid MIDI note name: {$noteName}\n");
    }
}
