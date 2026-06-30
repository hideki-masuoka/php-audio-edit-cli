<?php

namespace App\Audio;

use Symfony\Component\Process\Process;

class AudioAnalyzer
{
    private string $ffmpegPath;
    private string $ffprobePath;

    public function __construct(string $ffmpegPath, string $ffprobePath)
    {
        $this->ffmpegPath = $ffmpegPath;
        $this->ffprobePath = $ffprobePath;
    }

    /**
     * Executes a command and returns its output (combined stdout/stderr).
     * This is protected so it can be overridden in unit tests to avoid calling real binaries.
     */
    protected function executeCommand(array $cmd): string
    {
        $process = new Process($cmd, null, null, null, null);
        $process->run();
        return $process->getOutput() . "\n" . $process->getErrorOutput();
    }

    /**
     * Analyzes the audio file and returns recommended restore options.
     */
    public function analyze(string $inputFile): AudioRestoreOptions
    {
        // 1. Analyze entire frequency stats using astats
        $cmdStats = [
            $this->ffmpegPath,
            '-i', $inputFile,
            '-af', 'astats=metadata=1',
            '-f', 'null',
            '-'
        ];
        $outputStats = $this->executeCommand($cmdStats);
        
        $overallRms = $this->parseRmsLevel($outputStats);
        $crestFactor = $this->parseCrestFactor($outputStats);
        $flatFactor = $this->parseFlatFactor($outputStats);

        // 2. Analyze high-frequency energy (apply highpass at 8kHz then run astats)
        $cmdHighpassStats = [
            $this->ffmpegPath,
            '-i', $inputFile,
            '-af', 'highpass=f=8000,astats=metadata=1',
            '-f', 'null',
            '-'
        ];
        $outputHighpassStats = $this->executeCommand($cmdHighpassStats);
        $highpassRms = $this->parseRmsLevel($outputHighpassStats);

        // Calculate high frequency drop
        $diff = abs($overallRms - $highpassRms);

        $highFreqBoost = 0.0;
        $presenceBoost = 0.0;
        $dynamicRestore = false;
        $declip = false;
        $attenuation = 0.0;

        // Auto-tuning high frequency correction based on the analysis
        if ($diff > 25.0) {
            $highFreqBoost = 8.0;   // Strongly degraded treble
            $presenceBoost = 4.0;
        } elseif ($diff > 20.0) {
            $highFreqBoost = 5.0;   // Moderately degraded treble
            $presenceBoost = 2.0;
        } elseif ($diff > 15.0) {
            $highFreqBoost = 2.0;   // Slightly degraded treble
        }

        // If crest factor is very low (e.g. < 6.0), the dynamics might be crushed.
        if ($crestFactor > 0 && $crestFactor < 6.0) {
            $dynamicRestore = true;
        }

        // If flat factor is greater than 0, clipping is likely present
        if ($flatFactor > 0.0) {
            $declip = true;
            $attenuation = -3.0; // Restoring clipped waveforms needs headroom to avoid secondary clipping
        }

        return new AudioRestoreOptions(
            profile: 'auto',
            highFreqBoost: $highFreqBoost,
            presenceBoost: $presenceBoost,
            dynamicRestore: $dynamicRestore,
            noiseGate: false,
            declick: false,
            declip: $declip,
            attenuation: $attenuation
        );
    }

    private function parseRmsLevel(string $output): float
    {
        if (preg_match('/RMS level(?:\s*dB)?\s*:\s*(-?\d+(?:\.\d+)?)/i', $output, $matches)) {
            return (float) $matches[1];
        }
        return -20.0;
    }

    private function parseCrestFactor(string $output): float
    {
        if (preg_match('/Crest factor\s*:\s*(\d+(?:\.\d+)?)/i', $output, $matches)) {
            return (float) $matches[1];
        }
        return 8.0;
    }

    private function parseFlatFactor(string $output): float
    {
        if (preg_match('/Flat factor\s*:\s*(\d+(?:\.\d+)?)/i', $output, $matches)) {
            return (float) $matches[1];
        }
        return 0.0;
    }
}
