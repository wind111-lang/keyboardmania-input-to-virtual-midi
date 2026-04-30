# keyboardmania-input-to-virtual-midi

Keyboardmania 専用コントローラーの HID input を読み取り、CoreMIDI の仮想MIDI source として出力するための PHP ツールです。

GarageBand、MainStage、Logic Pro、REAPER など、CoreMIDI 入力を扱える macOS アプリで利用できます。

## Target Device

このプロジェクトの利用には Keyboardmania 専用コントローラーが必要です。

USB vendor/product ID が `0x0507:0x0010` の Keyboardmania 専用コントローラーのみを対象にしています。この ID は内部固定で、別のデバイス ID を指定するための option はありません。

対象の Keyboardmania 専用コントローラーがまだ認識されていない場合、入力読み取りはデバイスが認識されるまで待機します。

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

`src/Output` は変換後の event を CoreMIDI に出力します。debug 用の event 表示も CoreMIDI output 側に集約しています。

`src/Contract` には output 実装が満たす interface を置いています。

## Usage

Keyboardmania 専用コントローラーの HID device を読み取り、`KeyboardMania Virtual MIDI` という固定名の CoreMIDI source を作ります。

```sh
php run.php
```

または Composer script から:

```sh
composer start
```

CoreMIDI を出しながら変換後の event も確認したい場合:

```sh
php run.php --debug-events
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

MIDI channel を指定する場合:

```sh
php run.php --midi-channel 1
```

起動すると `Press Ctrl+C to stop.` が表示されます。対象の Keyboardmania 専用コントローラーがまだ認識されていない場合は、デバイスが認識されるまで待機します。

音楽アプリ側では MIDI 入力として `KeyboardMania Virtual MIDI` を選択してください。

## Troubleshooting

GarageBand で音が鳴らない場合は、まず次のコマンドで CoreMIDI だけを確認してください。

```sh
php run.php --test-note C4
```

GarageBand 側ではソフトウェア音源トラックを作成し、そのトラックを選択した状態にします。`php run.php --test-note C4` で音が鳴れば、CoreMIDI と GarageBand の設定は通っています。

テストノートは鳴るのに Keyboardmania controller で鳴らない場合は、次のコマンドで HID input が `note_down` / `note_up` に変換されているか確認してください。MIDI 送信は通常どおり行い、同じ CoreMIDI output が debug event も出します。

```sh
php run.php --debug-events
```

`--debug-events` で何も出ない場合は、変換前の HID raw 値を見ます。

```sh
php run.php --dump-hid
```

鍵盤を押しても `hid_button_change` が出ない場合は、鍵盤を押したまま1秒ごとの `hid_snapshot` を確認してください。

```sh
php run.php --dump-hid --dump-hid-snapshots
```

snapshot 内の `raw_value` が変わっていれば keymap/変換側、変わっていなければ IOHID の読み取り側を見直します。
