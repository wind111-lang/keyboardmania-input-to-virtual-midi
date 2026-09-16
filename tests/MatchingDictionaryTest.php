<?php

declare(strict_types=1);

use KeyboardManiaInputToVirtualMidi\Input\HidInputToVirtualMidi;

if (PHP_OS_FAMILY !== 'Darwin' || !extension_loaded('FFI')) {
    fwrite(STDOUT, "SKIP: macOS and PHP FFI are required.\n");
    exit(0);
}

require __DIR__ . '/../src/Input/HidInputToVirtualMidi.php';

$cf = FFI::cdef(<<<'CDEF'
long CFDictionaryGetCount(const void *dictionary);
void CFDictionaryGetKeysAndValues(const void *dictionary, const void **keys, const void **values);
unsigned long CFGetTypeID(const void *value);
unsigned long CFStringGetTypeID(void);
unsigned long CFNumberGetTypeID(void);
unsigned char CFStringGetCString(const void *string, char *buffer, long size, unsigned int encoding);
unsigned char CFNumberGetValue(const void *number, int type, void *value);
CDEF, '/System/Library/Frameworks/CoreFoundation.framework/CoreFoundation');

$readString = static function ($value) use ($cf): string {
    if ($cf->CFGetTypeID($value) !== $cf->CFStringGetTypeID()) {
        throw new RuntimeException('Expected a CFString.');
    }
    $buffer = $cf->new('char[256]');
    if (!$cf->CFStringGetCString($value, $buffer, 256, 0x08000100)) {
        throw new RuntimeException('Failed to read CFString.');
    }
    return FFI::string($buffer);
};

$input = new HidInputToVirtualMidi([], null);
$method = new ReflectionMethod($input, 'createMatchingDictionary');

// 実際のCore Foundation辞書を読み戻し、キー・値・ネイティブ型を検証する。
foreach ([
    ['Manufacturer' => 'KONAMI', 'ProductID' => 0x0010],
    ['Manufacturer' => '別の製造元', 'ProductID' => 0x0020],
] as $expected) {
    $dictionary = $method->invoke($input, $expected);
    if ($cf->CFDictionaryGetCount($dictionary) !== 2) {
        throw new RuntimeException('Expected exactly two matching criteria.');
    }
    $keys = $cf->new('const void *[2]');
    $values = $cf->new('const void *[2]');
    $cf->CFDictionaryGetKeysAndValues($dictionary, $keys, $values);

    $actual = [];
    for ($i = 0; $i < 2; ++$i) {
        $name = $readString($keys[$i]);
        if ($name === 'Manufacturer') {
            $actual[$name] = $readString($values[$i]);
        } elseif ($name === 'ProductID') {
            if ($cf->CFGetTypeID($values[$i]) !== $cf->CFNumberGetTypeID()) {
                throw new RuntimeException('ProductID must be a CFNumber.');
            }
            $number = $cf->new('int[1]');
            if (!$cf->CFNumberGetValue($values[$i], 9, $number)) {
                throw new RuntimeException('Failed to read ProductID.');
            }
            $actual[$name] = $number[0];
        } else {
            throw new RuntimeException('Unexpected matching key: ' . $name);
        }
    }
    ksort($actual);
    ksort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException('Matching criteria did not round-trip correctly.');
    }
}

fwrite(STDOUT, "PASS: Manufacturer (CFString) and ProductID (CFNumber), including UTF-8.\n");
