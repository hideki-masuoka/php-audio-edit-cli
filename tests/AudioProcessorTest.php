<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Audio\AudioProcessor;
use App\Audio\AudioProcessingOptions;

class AudioProcessorTest extends TestCase
{
    private string $tempDir;
    private string $inputFilePath;
    private string $outputFilePath;

    protected function setUp(): void
    {
        // 日本語を含む一時ディレクトリを作成
        $this->tempDir = sys_get_temp_dir() . '/php_audio_test_ポッドキャスト_' . uniqid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }

        // 日本語を含む一時入力ファイルを作成
        $this->inputFilePath = $this->tempDir . '/入力_音声_番組エピソード１.flac';
        file_put_contents($this->inputFilePath, 'dummy FLAC content');

        // 日本語を含む出力先ファイルパスを設定
        $this->outputFilePath = $this->tempDir . '/出力_フォルダ_処理済み/エピソード１_高音質化.flac';
    }

    protected function tearDown(): void
    {
        // テスト用の一時ファイルをクリーンアップ
        if (file_exists($this->inputFilePath)) {
            unlink($this->inputFilePath);
        }
        $outputDir = $this->tempDir . '/出力_フォルダ_処理済み';
        if (is_dir($outputDir)) {
            $outputFile = $this->outputFilePath;
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
            rmdir($outputDir);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testCreateProcessWithJapanesePaths(): void
    {
        $ffmpegPath = '/usr/bin/ffmpeg';
        $ffprobePath = '/usr/bin/ffprobe';
        $processor = new AudioProcessor($ffmpegPath, $ffprobePath);

        $options = new AudioProcessingOptions(
            normalize: true,
            targetDb: -16.0,
            noiseReduction: true,
            lowCut: true,
            lowCutFrequency: 85,
            deesser: true,
            gate: true,
            compressor: true
        );

        $process = $processor->createProcess(
            $this->inputFilePath,
            $this->outputFilePath,
            $options
        );

        $command = $process->getCommandLine();

        // コマンド文字列のなかに日本語の入出力パスが正しく含まれていることを確認
        $this->assertStringContainsString('入力_音声_番組エピソード１.flac', $command);
        $this->assertStringContainsString('出力_フォルダ_処理済み/エピソード１_高音質化.flac', $command);

        // 各フィルタのパラメータが正しくFFmpegのコマンドに含まれていることを確認
        $this->assertStringContainsString('highpass=f=85', $command);
        $this->assertStringContainsString('agate=threshold=-40dB:ratio=2:range=0.01', $command);
        $this->assertStringContainsString('deesser=i=0.5:f=0.5:m=0.5:s=o', $command);
        $this->assertStringContainsString('afftdn', $command);
        $this->assertStringContainsString('acompressor=threshold=-20dB:ratio=4:attack=20:release=200:makeup=2', $command);
        $this->assertStringContainsString('loudnorm=I=-16:TP=-1.5:LRA=11', $command);
    }
}
