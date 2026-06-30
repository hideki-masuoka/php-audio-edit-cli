<?php

namespace App\Audio;

use Symfony\Component\Process\Process;

class AudioRestorer
{
    private string $ffmpegPath;
    private string $ffprobePath;

    public function __construct(string $ffmpegPath, string $ffprobePath)
    {
        $this->ffmpegPath = $ffmpegPath;
        $this->ffprobePath = $ffprobePath;
    }

    /**
     * Prepares a Symfony Process for restoring/enhancing an audio file.
     */
    public function createProcess(
        string $inputFile,
        string $outputFile,
        AudioRestoreOptions $options
    ): Process {
        if (!file_exists($inputFile)) {
            throw new \InvalidArgumentException("Input file does not exist: {$inputFile}");
        }

        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $filters = [];

        // 1. Decline attenuation for clipping headroom
        // Attenuating the volume is critical before interpolating peaks to prevent secondary clipping.
        if ($options->declip && $options->attenuation < 0.0) {
            $filters[] = "volume=volume=" . sprintf("%.1f", $options->attenuation) . "dB";
        }

        // 2. Declip filter to reconstruct clipped waveforms
        if ($options->declip) {
            $filters[] = "adeclip=w=55:o=50:a=2:t=10";
        }

        // 3. Declick to remove digital artifacts/clicks from noise reduction upfront
        if ($options->declick) {
            $filters[] = "adeclick";
        }

        // 4. High frequency boost (Treble restoration)
        if ($options->highFreqBoost > 0) {
            // Apply shelving/peaking equalizers at 8kHz, 10kHz, and 12kHz
            $filters[] = "equalizer=f=8000:width_type=h:width=1000:g=" . sprintf("%.1f", $options->highFreqBoost * 0.7);
            $filters[] = "equalizer=f=10000:width_type=h:width=1200:g=" . sprintf("%.1f", $options->highFreqBoost);
            $filters[] = "equalizer=f=14000:width_type=h:width=1500:g=" . sprintf("%.1f", $options->highFreqBoost * 0.8);
        }

        // 5. Presence boost (Vocal clarity)
        if ($options->presenceBoost > 0) {
            $filters[] = "equalizer=f=4000:width_type=h:width=800:g=" . sprintf("%.1f", $options->presenceBoost);
        }

        // 6. Noise gate to quiet down high-frequency floor boosted by EQ
        if ($options->noiseGate) {
            $filters[] = "agate=threshold=-45dB:ratio=2:range=0.005";
        }

        // 7. Dynamic range restoration (Compand) to restore dynamic range
        if ($options->dynamicRestore) {
            $filters[] = "compand=attacks=0.01:decays=0.1:points=-80/-80|-40/-35|-20/-15|0/0";
        }

        $cmd = [
            $this->ffmpegPath,
            '-y',
            '-i', $inputFile
        ];

        if (!empty($filters)) {
            $cmd[] = '-af';
            $cmd[] = implode(',', $filters);
        }

        if ($options->compressionLevel !== null) {
            $cmd[] = '-compression_level';
            $cmd[] = (string) $options->compressionLevel;
        }

        if ($options->sampleRate !== null) {
            $cmd[] = '-ar';
            $cmd[] = (string) $options->sampleRate;
        }

        if ($options->bitDepth !== null) {
            $cmd[] = '-sample_fmt';
            $cmd[] = $options->bitDepth === 24 ? 's32' : 's16';
        }

        $cmd[] = $outputFile;

        return new Process($cmd, null, null, null, null);
    }

    /**
     * Gets the duration of the audio file in seconds using ffprobe.
     */
    public function getDuration(string $inputFile): float
    {
        if (!file_exists($inputFile)) {
            return 0.0;
        }

        $cmd = [
            $this->ffprobePath,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $inputFile
        ];

        try {
            $process = new Process($cmd, null, null, null, null);
            $process->run();
            if ($process->isSuccessful()) {
                return (float) trim($process->getOutput());
            }
        } catch (\Exception $e) {
            // Fallback
        }

        return 0.0;
    }
}
