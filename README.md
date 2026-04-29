# keyboardmania-input-to-virtual-midi

Keyboardmania 専用コントローラーの Linux joystick device input を読み取るための PHP ライブラリです。

## Target Device

このプロジェクトの利用には Keyboardmania 専用コントローラーが必要です。

Linux の joystick device として認識され、`config/keymap.json` の割り当てに合わせられる USB ゲームコントローラーであれば動作する可能性はあります。ただし、このリポジトリは Keyboardmania 専用コントローラーでの利用を目的としているため、それ以外のコントローラーでの利用は目的外です。

指定した joystick device がまだ存在しない場合、入力読み取りはデバイスが認識されるまで待機します。

## Setup

PHP 8.5 以上が必要です。

```sh
composer install
```

## Usage

デフォルトでは `/dev/input/js0` を読み取ります。

```sh
php run.php
```

または Composer script から:

```sh
composer start
```

別の joystick device を指定する場合:

```sh
php run.php --device /dev/input/js1
```

別の keymap を指定する場合:

```sh
php run.php --config config/keymap.json
```

起動すると `Press Ctrl+C to stop.` が表示されます。指定した joystick device がまだ存在しない場合は、デバイスが認識されるまで `Waiting for controller input device: ...` と表示して待機します。
