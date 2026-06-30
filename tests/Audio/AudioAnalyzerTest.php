<?php

namespace Tests\Audio;

use PHPUnit\Framework\TestCase;
use App\Audio\AudioAnalyzer;
use App\Audio\AudioRestoreOptions;

class AudioAnalyzerTest extends TestCase
{
    public function testAnalyzeComputesCorrectMetricsAndReturnsOptions(): void
    {
        $analyzer = new class('/usr/bin/ffmpeg', '/usr/bin/ffprobe') extends AudioAnalyzer {
            public array $commandsExecuted = [];
            public array $mockOutputs = [];

            protected function executeCommand(array $cmd): string
            {
                $this->commandsExecuted[] = $cmd;
                $cmdStr = implode(' ', $cmd);
                foreach ($this->mockOutputs as $pattern => $output) {
                    if (str_contains($cmdStr, $pattern)) {
                        return $output;
                    }
                }
                return '';
            }
        };

        // モックデータを設定
        // astatsの全帯域出力（Flat factor含む）と、高域帯域(highpass filtered)出力を模擬
        $analyzer->mockOutputs = [
            'highpass' => "Channel 1\nRMS level: -45.0 dB\n",
            'astats' => "Channel 1\nRMS level: -18.5 dB\nCrest factor: 8.5\nFlat factor: 0.001230\n",
        ];

        // ダミーのファイルパスを用意
        $inputFile = '/tmp/dummy_podcast.flac';

        // 実際に解析処理を実行
        $result = $analyzer->analyze($inputFile);

        $this->assertNotEmpty($analyzer->commandsExecuted);
        $this->assertInstanceOf(AudioRestoreOptions::class, $result);
        
        // こもった音（高域が落ちている）に対して、高音域ブーストが有効
        $this->assertTrue($result->highFreqBoost > 0);
        $this->assertEquals('auto', $result->profile);

        // クリッピングが検出されたため、declip が有効で、attenuation が適用されていること
        $this->assertTrue($result->declip);
        $this->assertEquals(-3.0, $result->attenuation);
    }
}
