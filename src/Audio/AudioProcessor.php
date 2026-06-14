<?php

namespace App\Audio;

use Symfony\Component\Process\Process;

class AudioProcessor
{
    private string $ffmpegPath;
    private string $ffprobePath;

    public function __construct(string $ffmpegPath, string $ffprobePath)
    {
        $this->ffmpegPath = $ffmpegPath;
        $this->ffprobePath = $ffprobePath;
    }

    /**
     * Prepares a Symfony Process for processing an audio file.
     * The process is returned unstarted so that the caller (like ProcessPool)
     * can manage its execution lifecycle and parallelization.
     */
    public function createProcess(
        string $inputFile,
        string $outputFile,
        bool $normalize,
        float $targetDb,
        bool $noiseReduction,
        ?int $compressionLevel = null,
        ?int $sampleRate = null,
        ?int $bitDepth = null
    ): Process {
        // Ensure input file exists
        if (!file_exists($inputFile)) {
            throw new \InvalidArgumentException("Input file does not exist: {$inputFile}");
        }

        // Ensure output directory exists
        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $filters = [];

        // 1. Noise reduction first
        if ($noiseReduction) {
            // afftdn is a very powerful FFT-based denoise filter in FFmpeg
            $filters[] = 'afftdn';
        }

        // 2. Loudness normalization (EBU R128 standard)
        if ($normalize) {
            // loudnorm params: I = Integrated loudness, TP = True Peak, LRA = Loudness Range
            $filters[] = "loudnorm=I={$targetDb}:TP=-1.5:LRA=11";
        }

        $cmd = [
            $this->ffmpegPath,
            '-y',             // Overwrite output files without asking
            '-i', $inputFile, // Input file
        ];

        if (!empty($filters)) {
            $cmd[] = '-af';
            $cmd[] = implode(',', $filters); // Combine filters sequentially
        }

        // Add FLAC compression level if specified
        if ($compressionLevel !== null) {
            $cmd[] = '-compression_level';
            $cmd[] = (string) $compressionLevel;
        }

        // Add sample rate if specified
        if ($sampleRate !== null) {
            $cmd[] = '-ar';
            $cmd[] = (string) $sampleRate;
        }

        // Add bit depth if specified (16-bit or 24-bit)
        if ($bitDepth !== null) {
            $cmd[] = '-sample_fmt';
            $cmd[] = $bitDepth === 24 ? 's32' : 's16';
        }

        // Output file
        $cmd[] = $outputFile;

        // Create Symfony Process (disable timeout for long files)
        return new Process($cmd, null, null, null, null);
    }
}
