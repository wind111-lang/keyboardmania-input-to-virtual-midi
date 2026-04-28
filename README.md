# keyboardmania-input-to-virtual-midi

Linux joystick device input reader for Keyboardmania-style controllers.

## Setup

```sh
composer install
```

## Usage

```sh
php bin/keyboardmania-input-to-virtual-midi /dev/input/js0 config/keymap.json
```

If arguments are omitted, the script uses `/dev/input/js0` and `config/keymap.json`.

The program emits one JSON object per input event.
