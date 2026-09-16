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

$method = new ReflectionMethod(HidInputToVirtualMidi::class, 'createMatchingDictionary');

// 実際のCore Foundation辞書を読み戻し、キー・値・ネイティブ型を検証する。
foreach ([
    [null, 0x0010, ['Manufacturer' => 'KONAMI', 'ProductID' => 0x0010]],
    [null, 0x0020, ['Manufacturer' => 'KONAMI', 'ProductID' => 0x0020]],
    [0x0507, 0x0010, ['VendorID' => 0x0507, 'ProductID' => 0x0010]],
    [0, 0, ['VendorID' => 0, 'ProductID' => 0]],
] as [$vendorId, $productId, $expected]) {
    $input = new HidInputToVirtualMidi([], null, vendorId: $vendorId, productId: $productId);
    $dictionary = $method->invoke($input);
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
        } elseif ($name === 'ProductID' || $name === 'VendorID') {
            if ($cf->CFGetTypeID($values[$i]) !== $cf->CFNumberGetTypeID()) {
                throw new RuntimeException($name . ' must be a CFNumber.');
            }
            $number = $cf->new('int[1]');
            if (!$cf->CFNumberGetValue($values[$i], 9, $number)) {
                throw new RuntimeException('Failed to read ' . $name);
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

fwrite(STDOUT, "PASS: Manufacturer and ProductID matching, including explicit vendor/product ID overrides.\n");
