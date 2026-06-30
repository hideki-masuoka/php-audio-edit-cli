<?php

namespace Tests\Audio;

use PHPUnit\Framework\TestCase;
use App\Audio\AudioRestorer;
use App\Audio\AudioRestoreOptions;

class AudioRestorerTest extends TestCase
{
    private string $tempDir;
    private string $inputFilePath;
    private string $outputFilePath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/php_audio_restore_test_' . uniqid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
        $this->inputFilePath = $this->tempDir . '/input.flac';
        file_put_contents($this->inputFilePath, 'dummy FLAC content');
        $this->outputFilePath = $this->tempDir . '/output.flac';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->inputFilePath)) {
            unlink($this->inputFilePath);
        }
        if (file_exists($this->outputFilePath)) {
            unlink($this->outputFilePath);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testCreateProcessWithRestoreFilters(): void
    {
        $ffmpegPath = '/usr/bin/ffmpeg';
        $ffprobePath = '/usr/bin/ffprobe';
        $restorer = new AudioRestorer($ffmpegPath, $ffprobePath);

        $options = new AudioRestoreOptions(
            profile: 'auto',
            highFreqBoost: 6.0,
            presenceBoost: 3.0,
            dynamicRestore: true,
            noiseGate: true,
            declick: true,
            declip: true,
            attenuation: -3.0
        );

        $process = $restorer->createProcess(
            $this->inputFilePath,
            $this->outputFilePath,
            $options
        );

        $command = $process->getCommandLine();

        // イコライザーフィルタ
        $this->assertStringContainsString('equalizer=f=10000', $command);
        $this->assertStringContainsString('equalizer=f=4000', $command);
        
        // ダイナミックレンジ復元
        $this->assertStringContainsString('compand', $command);

        // ノイズゲート
        $this->assertStringContainsString('agate', $command);

        // デクリック
        $this->assertStringContainsString('adeclick', $command);

        // デクリップ (declip) および減衰 (volume)
        $this->assertStringContainsString('volume=volume=-3.0dB', $command);
        $this->assertStringContainsString('adeclip', $command);
    }
}
