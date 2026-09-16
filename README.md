# keyboardmania-input-to-virtual-midi
Keyboardmania 専用コントローラーの HID input を読み取り、CoreMIDI の仮想MIDI source として出力するための PHP ツールです。
GarageBand、MainStage、Logic Pro、REAPER など、CoreMIDI 入力を扱える macOS アプリで利用できます。

## Target Device

このプロジェクトの利用には Keyboardmania 専用コントローラーが必要です。
HIDの製造元名（`Manufacturer`）が `KONAMI`、`ProductID` が `0x0010` のデバイスを検索します。
`--product-id` で製品IDを変更できます。`--vendor-id` を明示すると、製造元名の代わりに指定した数値のVendor IDで検索します。
対象の Keyboardmania 専用コントローラーがまだ認識されていない場合、入力読み取りはデバイスが認識されるまで待機します。

## Setup
macOS と PHP 8.5 が必要です。

```sh
composer install
```

## Structure

`src/Application` : CLI の引数処理と各部品の組み立てを担当します。

`src/Input` : HID device の検出、IOHID element の読み取り、raw HID dump を担当します。

`src/Mapping` : HID の button/axis event を note/control event に変換します。鍵盤割り当ては `config/keymap.json` で調整します。

`src/Output` : 変換後の event をJSON、CoreMIDI、または両方に出力します。

`src/Contract` : output 実装が満たす interface を置いています。

## Usage
Keyboardmania 専用コントローラーの HID device を読み取り、既定で `KeyboardMania Virtual MIDI` という名前の CoreMIDI source を作ります。

```sh
php run.php
```

JSON eventとMIDIを同時に出力する場合:
```sh
php run.php --output both
```

MIDIを作らず、JSON eventだけを確認する場合:
```sh
php run.php --output json
```

出力は `--output json|midi|both`（短縮形 `-o`）で選択でき、既定は `midi` です。
`--output=both` の形式も使えます。

別のキー割り当てファイルや仮想MIDIソース名を指定する場合:
```sh
php run.php --output both --config ./my-keymap.json --midi-source "My Keyboard"
```

`--config`（短縮形 `-c`）の既定はプロジェクト内の `config/keymap.json`、
`--midi-source` の既定は `KeyboardMania Virtual MIDI` です。
`--config=PATH`、`--midi-source=NAME` の形式も使えます。

デバイスIDを明示する場合（10進数・16進数に対応）:
```sh
php run.php --vendor-id 0x0507 --product-id 0x0010
```

`--vendor-id=ID`、`--product-id=ID` の形式も使えます。

CoreMIDI を出しながら HID raw event と変換後の event を確認したい場合:
```sh
php run.php --debug-events
```

`--debug-events` は各出力モードと併用できます。`both` でも変換後のJSON eventは重複出力しません。
`--test-note` は出力モードに関係なくCoreMIDIへテスト音を送り、`--dump-hid` はMIDIを作らずHIDの生データだけを表示します。

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
テストノートは鳴るのに Keyboardmania controller で鳴らない場合は、次のコマンドで HID input が変化しているか、さらに `note_down` / `note_up` に変換されているか確認してください。MIDI 送信は通常どおり行います。
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
