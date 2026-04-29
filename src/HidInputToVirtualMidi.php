<?php

declare(strict_types=1);

namespace KeyboardManiaInputToVirtualMidi;

use FFI;
use RuntimeException;
use Throwable;

final class HidInputToVirtualMidi
{
    private const int DEVICE_RETRY_MICROSECONDS = 1_000_000;
    private const int POLL_MICROSECONDS = 4_000;
    private const int CF_STRING_ENCODING_UTF8 = 0x08000100;
    private const int CF_NUMBER_INT_TYPE = 9;
    private const int HID_PAGE_GENERIC_DESKTOP = 0x01;
    private const int HID_PAGE_BUTTON = 0x09;
    private const int HID_USAGE_X = 0x30;
    private const int HID_USAGE_Y = 0x31;

    private readonly ControllerEventMapper $mapper;

    private readonly FFI $ffi;

    private mixed $manager = null;

    private mixed $elementArray = null;

    private mixed $deviceSetValues = null;

    private array $retainedCoreFoundationValues = [];

    public function __construct(
        private readonly int $vendorId,
        private readonly int $productId,
        array $config,
        ControllerEventOutput $output = new JsonEventOutput(),
    ) {
        $this->mapper = new ControllerEventMapper($config, $output);
        $this->ffi = $this->createFfi();
    }

    public function run(): never
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new RuntimeException('HID input is only available on macOS.');
        }

        fwrite(STDERR, "Press Ctrl+C to stop.\n\n");

        while (true) {
            $device = $this->waitForDevice();
            $elements = $this->discoverElements($device);

            fwrite(
                STDERR,
                sprintf(
                    "Reading HID input: vendor 0x%04x, product 0x%04x (%d buttons, %d axes)\n",
                    $this->vendorId,
                    $this->productId,
                    count($elements['buttons']),
                    count($elements['axes']),
                ),
            );

            $this->pollDevice($device, $elements);
            $this->mapper->reset();

            fwrite(STDERR, "HID input stopped. Waiting for device to return...\n");
        }
    }

    private function createFfi(): FFI
    {
        if (!extension_loaded('FFI')) {
            throw new RuntimeException('PHP FFI extension is required for HID input.');
        }

        $cdef = <<<'CDEF'
typedef const void * CFTypeRef;
typedef const struct __CFString * CFStringRef;
typedef const struct __CFDictionary * CFDictionaryRef;
typedef struct __CFDictionary * CFMutableDictionaryRef;
typedef const struct __CFNumber * CFNumberRef;
typedef const struct __CFSet * CFSetRef;
typedef const struct __CFArray * CFArrayRef;
typedef const struct __IOHIDManager * IOHIDManagerRef;
typedef const struct __IOHIDDevice * IOHIDDeviceRef;
typedef const struct __IOHIDElement * IOHIDElementRef;
typedef const struct __IOHIDValue * IOHIDValueRef;
typedef unsigned int IOOptionBits;
typedef int IOReturn;
typedef unsigned int IOHIDElementCookie;
CFStringRef CFStringCreateWithCString(const void *alloc, const char *cStr, unsigned int encoding);
CFMutableDictionaryRef CFDictionaryCreateMutable(const void *allocator, long capacity, const void *keyCallBacks, const void *valueCallBacks);
void CFDictionarySetValue(CFMutableDictionaryRef theDict, const void *key, const void *value);
CFNumberRef CFNumberCreate(const void *allocator, int theType, const void *valuePtr);
long CFSetGetCount(CFSetRef theSet);
void CFSetGetValues(CFSetRef theSet, const void **values);
long CFArrayGetCount(CFArrayRef theArray);
const void *CFArrayGetValueAtIndex(CFArrayRef theArray, long idx);
IOHIDManagerRef IOHIDManagerCreate(const void *allocator, IOOptionBits options);
void IOHIDManagerSetDeviceMatching(IOHIDManagerRef manager, CFDictionaryRef matching);
IOReturn IOHIDManagerOpen(IOHIDManagerRef manager, IOOptionBits options);
CFSetRef IOHIDManagerCopyDevices(IOHIDManagerRef manager);
IOReturn IOHIDDeviceOpen(IOHIDDeviceRef device, IOOptionBits options);
CFArrayRef IOHIDDeviceCopyMatchingElements(IOHIDDeviceRef device, CFDictionaryRef matching, IOOptionBits options);
uint32_t IOHIDElementGetUsagePage(IOHIDElementRef element);
uint32_t IOHIDElementGetUsage(IOHIDElementRef element);
IOHIDElementCookie IOHIDElementGetCookie(IOHIDElementRef element);
size_t IOHIDElementGetReportSize(IOHIDElementRef element);
IOReturn IOHIDDeviceGetValue(IOHIDDeviceRef device, IOHIDElementRef element, IOHIDValueRef *pValue);
long IOHIDValueGetIntegerValue(IOHIDValueRef value);
CDEF;

        try {
            return FFI::cdef($cdef, '/System/Library/Frameworks/IOKit.framework/IOKit');
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Failed to load IOKit through PHP FFI: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    private function waitForDevice(): mixed
    {
        $reportedWaiting = false;

        while (true) {
            $this->manager = $this->ffi->IOHIDManagerCreate(null, 0);
            $matching = $this->createMatchingDictionary();
            $this->ffi->IOHIDManagerSetDeviceMatching($this->manager, $matching);

            $openResult = $this->ffi->IOHIDManagerOpen($this->manager, 0);

            if ($openResult !== 0) {
                throw new RuntimeException(
                    sprintf('Failed to open IOHIDManager: 0x%08x', $openResult),
                );
            }

            $device = $this->firstMatchedDevice();

            if ($device !== null) {
                $this->ffi->IOHIDDeviceOpen($device, 0);
                return $device;
            }

            if (!$reportedWaiting) {
                fwrite(
                    STDERR,
                    sprintf(
                        "Waiting for HID device: vendor 0x%04x, product 0x%04x\n",
                        $this->vendorId,
                        $this->productId,
                    ),
                );
                $reportedWaiting = true;
            }

            usleep(self::DEVICE_RETRY_MICROSECONDS);
        }
    }

    private function createMatchingDictionary(): mixed
    {
        $dictionary = $this->ffi->CFDictionaryCreateMutable(null, 0, null, null);
        $vendorKey = $this->ffi->CFStringCreateWithCString(
            null,
            'VendorID',
            self::CF_STRING_ENCODING_UTF8,
        );
        $productKey = $this->ffi->CFStringCreateWithCString(
            null,
            'ProductID',
            self::CF_STRING_ENCODING_UTF8,
        );
        $vendorBuffer = $this->ffi->new('int[1]');
        $vendorBuffer[0] = $this->vendorId;
        $productBuffer = $this->ffi->new('int[1]');
        $productBuffer[0] = $this->productId;
        $vendorValue = $this->ffi->CFNumberCreate(
            null,
            self::CF_NUMBER_INT_TYPE,
            $vendorBuffer,
        );
        $productValue = $this->ffi->CFNumberCreate(
            null,
            self::CF_NUMBER_INT_TYPE,
            $productBuffer,
        );

        $this->ffi->CFDictionarySetValue($dictionary, $vendorKey, $vendorValue);
        $this->ffi->CFDictionarySetValue($dictionary, $productKey, $productValue);

        // Keep CF objects alive because the dictionary uses null callbacks.
        $this->retainedCoreFoundationValues = [
            $dictionary,
            $vendorKey,
            $productKey,
            $vendorValue,
            $productValue,
            $vendorBuffer,
            $productBuffer,
        ];

        return $dictionary;
    }

    private function firstMatchedDevice(): mixed
    {
        $deviceSet = $this->ffi->IOHIDManagerCopyDevices($this->manager);

        if ($deviceSet === null) {
            return null;
        }

        $deviceCount = $this->ffi->CFSetGetCount($deviceSet);

        if ($deviceCount < 1) {
            return null;
        }

        $this->deviceSetValues = $this->ffi->new("const void *[{$deviceCount}]");
        $this->ffi->CFSetGetValues($deviceSet, $this->deviceSetValues);

        return $this->ffi->cast('IOHIDDeviceRef', $this->deviceSetValues[0]);
    }

    /**
     * @return array{
     *     buttons: list<array{button: int, usage: int, element: mixed}>,
     *     axes: list<array{axis: int, usage: int, element: mixed}>
     * }
     */
    private function discoverElements(mixed $device): array
    {
        $this->elementArray = $this->ffi->IOHIDDeviceCopyMatchingElements($device, null, 0);

        if ($this->elementArray === null) {
            return [
                'buttons' => [],
                'axes' => [],
            ];
        }

        $buttonsByNumber = [];
        $extraButtonElements = [];
        $axes = [];
        $elementCount = $this->ffi->CFArrayGetCount($this->elementArray);

        for ($index = 0; $index < $elementCount; $index++) {
            $element = $this->ffi->cast(
                'IOHIDElementRef',
                $this->ffi->CFArrayGetValueAtIndex($this->elementArray, $index),
            );
            $usagePage = $this->ffi->IOHIDElementGetUsagePage($element);
            $usage = $this->ffi->IOHIDElementGetUsage($element);
            $reportSize = $this->ffi->IOHIDElementGetReportSize($element);

            if ($reportSize < 1) {
                continue;
            }

            if ($usagePage === self::HID_PAGE_BUTTON) {
                if ($usage >= 1 && $usage <= 32) {
                    $buttonsByNumber[$usage - 1] = [
                        'button' => $usage - 1,
                        'usage' => $usage,
                        'element' => $element,
                    ];
                } else {
                    $extraButtonElements[] = [
                        'cookie' => $this->ffi->IOHIDElementGetCookie($element),
                        'usage' => $usage,
                        'element' => $element,
                    ];
                }
                continue;
            }

            if ($usagePage !== self::HID_PAGE_GENERIC_DESKTOP) {
                continue;
            }

            if ($usage === self::HID_USAGE_X) {
                $axes[] = [
                    'axis' => 0,
                    'usage' => $usage,
                    'element' => $element,
                ];
                continue;
            }

            if ($usage === self::HID_USAGE_Y) {
                $axes[] = [
                    'axis' => 1,
                    'usage' => $usage,
                    'element' => $element,
                ];
            }
        }

        ksort($buttonsByNumber);

        $nextButton = $buttonsByNumber === [] ? 0 : max(array_keys($buttonsByNumber)) + 1;

        usort(
            $extraButtonElements,
            static fn (array $left, array $right): int => $left['cookie'] <=> $right['cookie'],
        );

        foreach ($extraButtonElements as $element) {
            $buttonsByNumber[$nextButton] = [
                'button' => $nextButton,
                'usage' => $element['usage'],
                'element' => $element['element'],
            ];
            $nextButton++;
        }

        return [
            'buttons' => array_values($buttonsByNumber),
            'axes' => $axes,
        ];
    }

    /**
     * @param array{
     *     buttons: list<array{button: int, usage: int, element: mixed}>,
     *     axes: list<array{axis: int, usage: int, element: mixed}>
     * } $elements
     */
    private function pollDevice(mixed $device, array $elements): void
    {
        $previousButtons = [];
        $previousAxes = [];
        $activeLowButtons = [];
        $inputCount = count($elements['buttons']) + count($elements['axes']);

        if ($inputCount === 0) {
            return;
        }

        while (true) {
            $failedReads = 0;

            foreach ($elements['buttons'] as $button) {
                $rawValue = $this->readElementValue($device, $button['element']);

                if ($rawValue === null) {
                    $failedReads++;
                    continue;
                }

                $buttonNumber = $button['button'];

                if (!array_key_exists($buttonNumber, $activeLowButtons)) {
                    $activeLowButtons[$buttonNumber] = $rawValue === 1;
                }

                $value = $activeLowButtons[$buttonNumber]
                    ? ($rawValue === 0 ? 1 : 0)
                    : ($rawValue === 0 ? 0 : 1);

                if (!array_key_exists($buttonNumber, $previousButtons)) {
                    $previousButtons[$buttonNumber] = $value;
                    continue;
                }

                if ($previousButtons[$buttonNumber] === $value) {
                    continue;
                }

                $previousButtons[$buttonNumber] = $value;
                $this->mapper->handleButton($buttonNumber, $value, $this->timeMilliseconds());
            }

            foreach ($elements['axes'] as $axis) {
                $rawValue = $this->readElementValue($device, $axis['element']);

                if ($rawValue === null) {
                    $failedReads++;
                    continue;
                }

                $axisNumber = $axis['axis'];
                $value = $this->scaledAxisValue($rawValue);

                if (!array_key_exists($axisNumber, $previousAxes)) {
                    $previousAxes[$axisNumber] = $value;
                    continue;
                }

                if ($previousAxes[$axisNumber] === $value) {
                    continue;
                }

                $previousAxes[$axisNumber] = $value;
                $this->mapper->handleAxis($axisNumber, $value, $this->timeMilliseconds());
            }

            if ($failedReads >= $inputCount) {
                return;
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    private function readElementValue(mixed $device, mixed $element): ?int
    {
        $valuePointer = $this->ffi->new('IOHIDValueRef[1]');
        $result = $this->ffi->IOHIDDeviceGetValue(
            $device,
            $element,
            FFI::addr($valuePointer[0]),
        );

        if ($result !== 0 || $valuePointer[0] === null) {
            return null;
        }

        return $this->ffi->IOHIDValueGetIntegerValue($valuePointer[0]);
    }

    private function scaledAxisValue(int $value): int
    {
        if ($value < 0) {
            return -32767;
        }

        if ($value > 0) {
            return 32767;
        }

        return 0;
    }

    private function timeMilliseconds(): int
    {
        return (int) round(hrtime(true) / 1_000_000);
    }
}
