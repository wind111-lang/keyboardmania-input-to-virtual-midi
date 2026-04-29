# keyboardmania-input-to-virtual-midi

Keyboardmania 専用コントローラーの HID input を読み取り、CoreMIDI の仮想MIDI source として出力するための PHP ツールです。

GarageBand、MainStage、Logic Pro、REAPER など、CoreMIDI 入力を扱える macOS アプリで利用できます。

## Target Device

このプロジェクトの利用には Keyboardmania 専用コントローラーが必要です。

`config/keymap.json` の割り当てに合わせられる USB HID ゲームコントローラーであれば動作する可能性はあります。ただし、このリポジトリは Keyboardmania 専用コントローラーでの利用を目的としているため、それ以外のコントローラーでの利用は目的外です。

指定した input device がまだ存在しない場合、入力読み取りはデバイスが認識されるまで待機します。

## Setup

macOS と PHP 8.5 以上が必要です。

macOS で HID input を読み取る場合は、PHP の FFI extension が必要です。

```sh
composer install
```

## Usage

デフォルトでは `0x0507:0x0010` の HID device を読み取り、`KeyboardMania Virtual MIDI` という CoreMIDI source を作ります。

```sh
php run.php
```

または Composer script から:

```sh
composer start
```

JSON event も同時に確認したい場合:

```sh
php run.php --output both
```

別の USB vendor/product ID を指定する場合:

```sh
php run.php --vendor-id 0x0507 --product-id 0x0010
```

CoreMIDI source 名や MIDI channel を指定する場合:

```sh
php run.php --midi-source "KeyboardMania Virtual MIDI" --midi-channel 1
```

別の keymap を指定する場合:

```sh
php run.php --config config/keymap.json
```

起動すると `Press Ctrl+C to stop.` が表示されます。指定した HID device がまだ存在しない場合は、デバイスが認識されるまで待機します。

音楽アプリ側では MIDI 入力として `KeyboardMania Virtual MIDI` を選択してください。
