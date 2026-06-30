# PHP Audio Edit CLI

FrankenPHP の Standalone モードを活用してビルドされた、Linux & Windows 向けの高性能ポータブル音声編集 CLI ツールです。
任意のオーディオファイル（主に FLAC 形式を想定）に対して、音圧のノーマライズ、ノイズ除去、さらにはノイズ除去によって劣化した音質の自動復元や音割れの修復などを、複数ファイル同時に並行で高速に実行できます。

## 🌟 主な特徴

- **高性能な音声処理**: `FFmpeg` を裏側のエンジンとして採用し、プロ水準の処理を実行します。
  - **Loudness Normalization**: 放送規格に準拠した EBU R128 規格（デフォルト `-14.0 LUFS/dB`）に沿った、聴感上自然な音圧調整。
  - **Noise Reduction**: FFT-based 降ノイズフィルタ (`afftdn`) による効果的な背景雑音カット。
  - **Acoustic Analyze & Restore**: ノイズ除去で劣化したファイルの音響特性（高音域減衰、クレストファクタ等）を自動分析し、最適なイコライジングやダイナミックレンジ補正を施して原音を復元。
  - **Clipping Repair**: レベル超過によって潰れてしまった波形（音割れ）を `astats` フィルタの `Flat factor`（波形の平坦度）で検出し、自己回帰アルゴリズム（`adeclip`）を用いて元の滑らかな波形に復元。
- **超高速並行バッチ処理**:
  - `Symfony Process` をベースにした**カスタム並行プロセスプール・ワーカー**を内蔵。
  - CPU 負荷や要件に合わせて並行数（スレッド数）を自在にチューニング可能。
- **スマートなファイル解決**:
  - 複数ファイルの直接指定、ディレクトリ内の `.flac` 全スキャン、ワイルドカード（`*.flac`）による一括指定にネイティブ対応。
- **FFmpeg のゼロ・コンフィギュレーション（自動調達）**:
  - 実行環境のシステムに `ffmpeg` / `ffprobe` がない場合、実行OS（Linux/Windows/macOS）に最適な公式のスタティック・バイナリを自動的にバックグラウンドダウンロードします。
- **static-php-cli (micro SAPI)**:
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

### 1. 音声処理 (`process` コマンド)

メインコマンド `process` を使用して音声を処理します。

```bash
php bin/audio-edit process <ファイルまたはディレクトリ...> [オプション]
```

#### 引数 (Arguments)
- `files`: 処理対象となる FLAC ファイル、またはフォルダ、あるいは `*.flac` などのパターン指定（スペース区切りで複数指定可能）。

#### 主なオプション (Options)
| オプション | 短縮 | デフォルト | 説明 |
| :--- | :--- | :--- | :--- |
| `--normalize` | `-m` | なし | 音圧のノーマライズ処理（EBU R128規格）を有効にします。 |
| `--target-db=VAL` | `-t` | `-14.0` | ノーマライズ時の目標音圧（LUFS/dB）。例：`--target-db=-16` |
| `--noise-reduction`| `-r` | なし | ノイズ除去処理（FFT降ノイズ）を有効にします。 |
| `--low-cut` | `-l` | なし | ローカット（ハイパス）フィルタを有効にし、エアコンなどの低域雑音をカットします。 |
| `--low-cut-freq=VAL`| なし | `80` | ローカットの遮断周波数（Hz）。 |
| `--deesser` | なし | なし | ディエッサーを有効にし、サ行の耳障りな高音ノイズを軽減します。 |
| `--gate` | `-g` | なし | ノイズゲートを有効にし、無音時の環境ノイズをシャットアウトします。 |
| `--compressor` | なし | なし | コンプレッサーを有効にし、声の音量のばらつきを抑えて聞き取りやすくします。 |
| `--compression-level=VAL` | `-p` | `5` | FLACファイルの圧縮率。`0`（最低圧縮、最速）から `12`（最高圧縮、低速）の間で指定します。 |
| `--sample-rate=VAL` | `-s` | 元のまま | 出力のサンプリングレート（Hz）を指定します。例：`--sample-rate=48000` |
| `--bit-depth=VAL` | `-d` | 元のまま | 出力のビット深度を指定します。`16` または `24` が指定可能です。 |
| `--output-dir=VAL` | `-o` | 入力元と同じ | 処理後のファイルを保存するディレクトリ。指定しない場合は、元ファイルと同じフォルダに `<元名前>_processed.flac` として保存されます。 |
| `--concurrency=VAL`| `-c` | `4` | 同時に処理する最大ファイル数（並行スレッド数）。 |

#### 💡 実行例

##### 1.1 単一ファイルをノイズ除去 & ポッドキャスト向け最適化 (ローカット、ディエッサー、ゲート、コンプレッサー) & `-16 LUFS` にノーマライズ
```bash
php bin/audio-edit process talk.flac --normalize --target-db=-16 --noise-reduction --low-cut --deesser --gate --compressor
```

##### 1.2 サンプリングレートを `48000Hz`、ビット深度を `24-bit` に変換してハイレゾ品質で書き出し
```bash
php bin/audio-edit process song.flac --sample-rate=48000 --bit-depth=24
```

##### 1.3 ディレクトリ内のすべての FLAC ファイルを並行（2スレッド）でノイズ除去のみ実行
```bash
php bin/audio-edit process /path/to/audio/dir -r -c 2
```

##### 1.4 複数ファイルをまとめて処理して、別フォルダへ書き出す
```bash
php bin/audio-edit process file1.flac file2.flac -m -o /path/to/output/
```

### 2. 音質改善・劣化復元 (`restore` コマンド)

風音などのノイズ除去(Noise reduction)によって、高音域が削られてこもってしまったり、アタック感が失われたり、カサカサしたアーティファクトが発生したファイル、あるいは**レベル超過による音割れ（クリッピング）が発生したファイル**を**自動で解析し、音質を復元・改善**します。

```bash
php bin/audio-edit restore <ファイルまたはディレクトリ...> [オプション]
```

#### 特徴とアルゴリズム
- **自動音響解析**: 各ファイルの全体のエネルギー分布と高音域（8kHz以上）のエネルギー分布を比較・解析し、クレストファクタ（ダイナミックレンジ）を調べることでノイズ除去による劣化を診断。さらに `astats` フィルタの `Flat factor`（波形の平坦度）を利用してクリップ発生の有無を検知し、音割れの発生状況を正確に特定します。
- **動的フィルタ補正**: 解析結果に基づき、削られた高域の適正ブースト（イコライザー補正）、プレゼンス強調、アタック感の復元（マルチバンドコンパンダー処理）、ノイズゲート、デクリック処理、および**音割れ補間（デクリップ）**のためのフィルタチェーンを自動で組み立て適用します。

#### 主なオプション (Options)
| オプション | 短縮 | デフォルト | 説明 |
| :--- | :--- | :--- | :--- |
| `--analyze-only` | `-a` | なし | 改善処理は実行せず、各ファイルの音響解析結果と推奨パラメータの出力のみ行います。 |
| `--high-freq-boost=VAL`| なし | 解析結果による | 高音域（8k-14kHz）のイコライザー補正量を手動でオーバーライドします（dB）。 |
| `--presence-boost=VAL` | なし | 解析結果による | プレゼンス（4kHz）のイコライザー補正量を手動でオーバーライドします（dB）。 |
| `--dynamic-restore` | なし | なし | ダイナミックレンジ復元（コンパンダー処理）を強制的に有効化します。 |
| `--noise-gate` | なし | なし | 補正で持ち上がった残留ノイズを抑えるノイズゲートを有効化します。 |
| `--declick` | なし | なし | 降ノイズ時に発生したクリックノイズ・デジタルアーティファクトを抑制します。 |
| `--declip` | なし | なし | 音割れ（クリッピング）修復（デクリップ）フィルタを強制的に有効化します。 |
| `--attenuation=VAL` | なし | 解析結果による | デクリップ処理時のゲイン減衰量（ヘッドルーム確保用、dB）を指定します。例：`--attenuation=-3.0` |
| `--output-dir=VAL` | `-o` | 入力元と同じ | 処理後のファイルを保存するディレクトリ。指定しない場合は、元ファイルと同じフォルダに `<元名前>_restored.flac` として保存されます。 |

#### 💡 実行例

##### 2.1 音響特性・音割れ状況の診断のみを行う（ファイル書き出しは行わない）
```bash
php bin/audio-edit restore degraded.flac --analyze-only
```

##### 2.2 自動解析に基づき音割れ修復および音質復元を実行して書き出す
```bash
php bin/audio-edit restore degraded.flac
```

##### 2.3 手動でクリッピング修復を有効にし、ゲインを3dB下げた状態でデクリップ処理を実行する
```bash
php bin/audio-edit restore degraded.flac --declip --attenuation=-3.0
```

---

## 🐳 スタンドアロンバイナリのビルド (static-php-cli)

Docker を用いて、Linux（x86_64）環境向けの単一実行可能ファイル `./audio-edit-cli` を作成します。

```bash
# ビルドスクリプトの実行 (Dockerが必要です)
./build-standalone.sh
```

ビルドが完了すると、プロジェクトのルートディレクトリに `./audio-edit-cli` という実行バイナリが生成されます。
このバイナリは、PHPがインストールされていない Linux サーバー等に持っていくだけでそのまま動作します。

### スタンドアロンでの実行例
```bash
./audio-edit-cli process /path/to/audio/*.flac -m -r
```


---

## 🗂️ ディレクトリ構成

```text
├── bin/
│   ├── audio-edit          # CLIのエントリーポイントスクリプト
│   └── ffmpeg-bin/         # [自動生成] ダウンロードされた FFmpeg バイナリ保存先
├── src/
│   ├── Audio/
│   │   ├── AudioAnalyzer.php  # 音質劣化の自動解析エンジン
│   │   ├── AudioProcessor.php # FFmpegフィルタチェーン構成（通常処理）
│   │   ├── AudioRestoreOptions.php # 復元処理用設定DTO
│   │   ├── AudioRestorer.php  # 音質復元用フィルタチェーン構成
│   │   ├── FFmpegLocator.php  # OS検知・バイナリ自動調達
│   │   └── ProcessPool.php    # カスタム並行プロセスプール
│   └── Commands/
│       ├── AudioProcessCommand.php # 通常音声処理コマンド定義
│       └── AudioRestoreCommand.php # 音質復元コマンド定義
├── box.json                # PHARビルド用 Box 設定ファイル
├── build-standalone.sh     # static-php-cliビルド自動化スクリプト
└── static-build.Dockerfile # スタンドアロンコンパイル用 Dockerfile
```

## 📄 ライセンス
[MIT](LICENSE)
