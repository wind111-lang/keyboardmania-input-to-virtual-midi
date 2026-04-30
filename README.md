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

## Structure

`src/Application` は CLI の引数処理と各部品の組み立てを担当します。

`src/Input` は HID device の検出、IOHID element の読み取り、raw HID dump を担当します。

`src/Mapping` は HID の button/axis event を note/control event に変換します。鍵盤割り当ては `config/keymap.json` で調整します。

`src/Output` は変換後の event を JSON または CoreMIDI に出力します。

`src/Contract` には output 実装が満たす interface を置いています。

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

HID input を使わず、CoreMIDI source からテストノートだけを送る場合:

```sh
php run.php --test-note C4
```

CoreMIDI output を作らず、HID input の raw 値だけを確認する場合:

```sh
php run.php --dump-hid
```

押しっぱなし状態を1秒ごとの snapshot でも確認したい場合:

```sh
php run.php --dump-hid --dump-hid-snapshots
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

## Troubleshooting

VCV Rack Free に `KeyboardMania Virtual MIDI` が見えているのに音が鳴らない場合は、まず次のコマンドで CoreMIDI だけを確認してください。

```sh
php run.php --test-note C4
```

VCV Rack 側では `MIDI-CV` module の Driver を `Core MIDI`、Device を `KeyboardMania Virtual MIDI`、Channel を `1` または `All` にします。`V/OCT` を oscillator に、`GATE` を envelope や VCA に接続した状態でテストノートが鳴れば、CoreMIDI と VCV Rack の設定は通っています。

テストノートは鳴るのに Keyboardmania controller で鳴らない場合は、次のコマンドで HID input が `note_down` / `note_up` に変換されているか確認してください。

```sh
php run.php --output both
```

`--output both` で何も出ない場合は、変換前の HID raw 値を見ます。

```sh
php run.php --dump-hid
```

鍵盤を押しても `hid_button_change` が出ない場合は、鍵盤を押したまま1秒ごとの `hid_snapshot` を確認してください。

```sh
php run.php --dump-hid --dump-hid-snapshots
```

snapshot 内の `raw_value` が変わっていれば keymap/変換側、変わっていなければ IOHID の読み取り側を見直します。
