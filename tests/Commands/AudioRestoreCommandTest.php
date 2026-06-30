<?php

namespace Tests\Commands;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use App\Commands\AudioRestoreCommand;

class AudioRestoreCommandTest extends TestCase
{
    private string $tempDir;
    private string $inputFilePath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/php_audio_restore_cmd_test_' . uniqid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
        $this->inputFilePath = $this->tempDir . '/input_test.flac';
        file_put_contents($this->inputFilePath, 'dummy FLAC content');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->inputFilePath)) {
            unlink($this->inputFilePath);
        }
        $outputFile = $this->tempDir . '/input_test_restored.flac';
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testExecuteAnalyzeOnly(): void
    {
        $application = new Application();
        $application->add(new AudioRestoreCommand());

        $command = $application->find('restore');
        $commandTester = new CommandTester($command);

        // --analyze-only を指定して実行
        $commandTester->execute([
            'files' => [$this->inputFilePath],
            '--analyze-only' => true
        ]);

        $output = $commandTester->getDisplay();
        
        $this->assertStringContainsString('=== Audio Restore Analysis ===', $output);
        $this->assertStringContainsString('input_test.flac', $output);
        $this->assertStringContainsString('Suggested High-Freq Boost:', $output);
        $this->assertStringContainsString('Suggested Presence Boost:', $output);
        $this->assertStringContainsString('Suggested Dynamic Restore:', $output);
        $this->assertStringContainsString('De-clip (Clipping Repair):', $output);
    }
}
