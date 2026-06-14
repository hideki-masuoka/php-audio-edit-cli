# PHP Audio Edit CLI

FrankenPHP の Standalone モードを活用してビルドされた、Linux & Windows 向けの高性能ポータブル音声編集 CLI ツールです。
任意のオーディオファイル（主に FLAC 形式を想定）に対して、音圧のノーマライズ処理やノイズ除去処理を複数ファイル同時に並行で高速に実行できます。

## 🌟 主な特徴

- **高性能な音声処理**: `FFmpeg` を裏側のエンジンとして採用し、プロ水準の処理を実行します。
  - **Loudness Normalization**: 放送規格に準拠した EBU R128 規格（デフォルト `-14.0 LUFS/dB`）に沿った、聴感上自然な音圧調整。
  - **Noise Reduction**: FFT-based 降ノイズフィルタ (`afftdn`) による効果的な背景雑音カット。
- **超高速並行バッチ処理**:
  - `Symfony Process` をベースにした**カスタム並行プロセスプール・ワーカー**を内蔵。
  - CPU 負荷や要件に合わせて並行数（スレッド数）を自在にチューニング可能。
- **スマートなファイル解決**:
  - 複数ファイルの直接指定、ディレクトリ内の `.flac` 全スキャン、ワイルドカード（`*.flac`）による一括指定にネイティブ対応。
- **FFmpeg のゼロ・コンフィギュレーション（自動調達）**:
  - 実行環境のシステムに `ffmpeg` / `ffprobe` がない場合、実行OS（Linux/Windows/macOS）に最適な公式のスタティック・バイナリを自動的にバックグラウンドダウンロードします。
- **FrankenPHP スタンドアロン**:
  - Docker 環境を利用して、PHPランタイムとアプリコードを1つのポータブルな実行ファイルにビルド可能。

---

## 🛠️ 必要環境

### 開発・ローカル実行時
- PHP 8.2 以上
- Composer

### スタンドアロンバイナリビルド時
- Docker

---

## 📦 クイックスタート (開発環境)

1. **依存関係のインストール**:
   ```bash
   composer install
   ```

2. **コマンドの実行ヘルプ確認**:
   ```bash
   php bin/audio-edit process --help
   ```

---

## 🚀 コマンドの使用方法

メインコマンド `process` を使用して音声を処理します。

```bash
php bin/audio-edit process <ファイルまたはディレクトリ...> [オプション]
```

### 引数 (Arguments)
- `files`: 処理対象となる FLAC ファイル、またはフォルダ、あるいは `*.flac` などのパターン指定（スペース区切りで複数指定可能）。

### 主なオプション (Options)
| オプション | 短縮 | デフォルト | 説明 |
| :--- | :--- | :--- | :--- |
| `--normalize` | `-m` | なし | 音圧のノーマライズ処理（EBU R128規格）を有効にします。 |
| `--target-db=VAL` | `-t` | `-14.0` | ノーマライズ時の目標音圧（LUFS/dB）。例：`--target-db=-16` |
| `--noise-reduction`| `-r` | なし | ノイズ除去処理（FFT降ノイズ）を有効にします。 |
| `--output-dir=VAL` | `-o` | 入力元と同じ | 処理後のファイルを保存するディレクトリ。指定しない場合は、元ファイルと同じフォルダに `<元名前>_processed.flac` として保存されます。 |
| `--concurrency=VAL`| `-c` | `4` | 同時に処理する最大ファイル数（並行スレッド数）。 |

### 💡 実行例

#### 1. 単一ファイルをノイズ除去 & `-16 LUFS` にノーマライズ
```bash
php bin/audio-edit process song.flac --normalize --target-db=-16 --noise-reduction
```

#### 2. ディレクトリ内のすべての FLAC ファイルを並行（2スレッド）でノイズ除去のみ実行
```bash
php bin/audio-edit process /path/to/audio/dir -r -c 2
```

#### 3. 複数ファイルをまとめて処理して、別フォルダへ書き出す
```bash
php bin/audio-edit process file1.flac file2.flac -m -o /path/to/output/
```

---

## 🐳 スタンドアロンバイナリのビルド (FrankenPHP)

Docker を用いて、Linux（x86_64）環境向けの単一実行可能ファイル `./audio-edit-cli` を作成します。

```bash
# ビルドスクリプトの実行 (Dockerが必要です)
./build-standalone.sh
```

ビルドが完了すると、プロジェクトのルートディレクトリに `./audio-edit-cli` という実行バイナリが生成されます。
このバイナリは、PHPがインストールされていない Linux サーバー等に持っていくだけでそのまま動作します。

### スタンドアロンでの実行例
```bash
./audio-edit-cli php-cli bin/audio-edit process /path/to/audio/*.flac -m -r
```

---

## 🗂️ ディレクトリ構成

```text
├── bin/
│   ├── audio-edit          # CLIのエントリーポイントスクリプト
│   └── ffmpeg-bin/         # [自動生成] ダウンロードされた FFmpeg バイナリ保存先
├── src/
│   ├── Audio/
│   │   ├── AudioProcessor.php # FFmpegフィルタチェーン構成
│   │   ├── FFmpegLocator.php  # OS検知・バイナリ自動調達
│   │   └── ProcessPool.php    # カスタム並行プロセスプール
│   └── Commands/
│       └── AudioProcessCommand.php # Symfony Console コマンド定義
├── build-standalone.sh     # FrankenPHPビルド自動化スクリプト
└── static-build.Dockerfile # スタンドアロンコンパイル用 Dockerfile
```

## 📄 ライセンス
[MIT](LICENSE)
