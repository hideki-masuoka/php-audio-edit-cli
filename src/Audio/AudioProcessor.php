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
        AudioProcessingOptions $options
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

        // 1. Low-cut (High-pass) filter to remove low-frequency rumble first
        if ($options->lowCut) {
            $filters[] = "highpass=f={$options->lowCutFrequency}";
        }

        // 2. Noise Gate to mute background noise during silence
        if ($options->gate) {
            $filters[] = "agate=threshold=-40dB:ratio=2:range=0.01";
        }

        // 3. De-esser to reduce sibilance ("s", "t" sounds)
        if ($options->deesser) {
            $filters[] = "deesser=i=0.5:f=0.5:m=0.5:s=o";
        }

        // 4. Noise reduction
        if ($options->noiseReduction) {
            // afftdn is a very powerful FFT-based denoise filter in FFmpeg
            $filters[] = 'afftdn';
        }

        // 5. Compressor to balance dynamic range of vocals
        if ($options->compressor) {
            $filters[] = "acompressor=threshold=-20dB:ratio=4:attack=20:release=200:makeup=2";
        }

        // 6. Loudness normalization (EBU R128 standard)
        if ($options->normalize) {
            // loudnorm params: I = Integrated loudness, TP = True Peak, LRA = Loudness Range
            $filters[] = "loudnorm=I={$options->targetDb}:TP=-1.5:LRA=11";
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
        if ($options->compressionLevel !== null) {
            $cmd[] = '-compression_level';
            $cmd[] = (string) $options->compressionLevel;
        }

        // Add sample rate if specified
        if ($options->sampleRate !== null) {
            $cmd[] = '-ar';
            $cmd[] = (string) $options->sampleRate;
        }

        // Add bit depth if specified (16-bit or 24-bit)
        if ($options->bitDepth !== null) {
            $cmd[] = '-sample_fmt';
            $cmd[] = $options->bitDepth === 24 ? 's32' : 's16';
        }

        // Output file
        $cmd[] = $outputFile;

        // Create Symfony Process (disable timeout for long files)
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
            // Fallback to 0 if ffprobe fails or duration cannot be read
        }

        return 0.0;
    }
}

