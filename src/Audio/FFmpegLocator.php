<?php

namespace App\Audio;

use Symfony\Component\Process\Process;

class FFmpegLocator
{
    private string $binDir;

    public function __construct(?string $binDir = null)
    {
        // Default to a subdirectory in the project root: bin/ffmpeg-bin/
        $this->binDir = $binDir ?? dirname(__DIR__, 2) . '/bin/ffmpeg-bin';
    }

    /**
     * Locates the ffmpeg binary, downloading it if not present anywhere.
     * 
     * @param callable|null $onProgress Callback for download progress feedback
     * @return array{ffmpeg: string, ffprobe: string}
     */
    public function locate(?callable $onProgress = null): array
    {
        // 1. Check if globally available in PATH
        $globalFfmpeg = $this->findGlobalBinary('ffmpeg');
        $globalFfprobe = $this->findGlobalBinary('ffprobe');

        if ($globalFfmpeg && $globalFfprobe) {
            return [
                'ffmpeg' => $globalFfmpeg,
                'ffprobe' => $globalFfprobe,
            ];
        }

        // 2. Check if already downloaded locally
        $localFfmpeg = $this->getLocalBinaryPath('ffmpeg');
        $localFfprobe = $this->getLocalBinaryPath('ffprobe');

        if (file_exists($localFfmpeg) && file_exists($localFfprobe)) {
            return [
                'ffmpeg' => $localFfmpeg,
                'ffprobe' => $localFfprobe,
            ];
        }

        // 3. Download and extract
        $this->ensureBinDirExists();
        $this->download($onProgress);

        return [
            'ffmpeg' => $this->getLocalBinaryPath('ffmpeg'),
            'ffprobe' => $this->getLocalBinaryPath('ffprobe'),
        ];
    }

    private function findGlobalBinary(string $name): ?string
    {
        $command = PHP_OS_FAMILY === 'Windows' ? 'where ' . $name : 'which ' . $name;
        $process = Process::fromShellCommandline($command);
        $process->run();

        if ($process->isSuccessful()) {
            $path = trim($process->getOutput());
            // where command can return multiple lines, take the first one
            $paths = explode("\n", $path);
            $targetPath = trim($paths[0]);
            if (file_exists($targetPath) && is_executable($targetPath)) {
                return $targetPath;
            }
        }

        return null;
    }

    private function getLocalBinaryPath(string $name): string
    {
        $extension = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        return $this->binDir . '/' . $name . $extension;
    }

    private function ensureBinDirExists(): void
    {
        if (!is_dir($this->binDir)) {
            mkdir($this->binDir, 0755, true);
        }
    }

    private function download(?callable $onProgress): void
    {
        $os = PHP_OS_FAMILY;
        if ($onProgress) {
            $onProgress("FFmpeg binaries not found. Downloading for {$os}...");
        }

        if ($os === 'Linux') {
            $this->downloadForLinux($onProgress);
        } elseif ($os === 'Windows') {
            $this->downloadForWindows($onProgress);
        } elseif ($os === 'Darwin') {
            $this->downloadForMac($onProgress);
        } else {
            throw new \RuntimeException("Unsupported OS: {$os}. Please install ffmpeg and ffprobe manually.");
        }

        // Set execute permissions
        if ($os !== 'Windows') {
            chmod($this->getLocalBinaryPath('ffmpeg'), 0755);
            chmod($this->getLocalBinaryPath('ffprobe'), 0755);
        }
    }

    private function downloadForLinux(?callable $onProgress): void
    {
        // Using stable static builds from johnvansickle.com (amd64)
        $url = 'https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz';
        $tarFile = $this->binDir . '/ffmpeg.tar.xz';

        $this->downloadFile($url, $tarFile, $onProgress);

        if ($onProgress) {
            $onProgress("Extracting tar.xz file (Linux)...");
        }

        // Extract using system tar command (most reliable for xz on Linux)
        $process = new Process(['tar', '-xJf', $tarFile, '-C', $this->binDir]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Failed to extract FFmpeg tar.xz: " . $process->getErrorOutput());
        }

        // Find the extracted binaries in the subdirectory and move them to $this->binDir
        $dirs = glob($this->binDir . '/ffmpeg-*-static');
        if (!empty($dirs)) {
            $extractedDir = $dirs[0];
            rename($extractedDir . '/ffmpeg', $this->binDir . '/ffmpeg');
            rename($extractedDir . '/ffprobe', $this->binDir . '/ffprobe');
            // Clean up the extracted folder
            $this->recursiveDelete($extractedDir);
        }

        // Clean up tar file
        unlink($tarFile);
    }

    private function downloadForWindows(?callable $onProgress): void
    {
        // Gyan.dev release builds
        $url = 'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip';
        $zipFile = $this->binDir . '/ffmpeg.zip';

        $this->downloadFile($url, $zipFile, $onProgress);

        if ($onProgress) {
            $onProgress("Extracting zip file (Windows)...");
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) === true) {
            $zip->extractTo($this->binDir);
            $zip->close();
        } else {
            throw new \RuntimeException("Failed to extract FFmpeg zip file.");
        }

        // Find binary files and move them to root of binDir
        $dirs = glob($this->binDir . '/ffmpeg-*-essentials_build');
        if (!empty($dirs)) {
            $extractedDir = $dirs[0];
            rename($extractedDir . '/bin/ffmpeg.exe', $this->binDir . '/ffmpeg.exe');
            rename($extractedDir . '/bin/ffprobe.exe', $this->binDir . '/ffprobe.exe');
            $this->recursiveDelete($extractedDir);
        }

        // Clean up zip file
        unlink($zipFile);
    }

    private function downloadForMac(?callable $onProgress): void
    {
        // Evermeet static builds for macOS
        $ffmpegUrl = 'https://evermeet.cx/ffmpeg/getrelease/zip';
        $ffprobeUrl = 'https://evermeet.cx/ffmpeg/getrelease/ffprobe/zip';

        $ffmpegZip = $this->binDir . '/ffmpeg.zip';
        $ffprobeZip = $this->binDir . '/ffprobe.zip';

        $this->downloadFile($ffmpegUrl, $ffmpegZip, $onProgress);
        $this->downloadFile($ffprobeUrl, $ffprobeZip, $onProgress);

        if ($onProgress) {
            $onProgress("Extracting macOS binaries...");
        }

        foreach ([$ffmpegZip, $ffprobeZip] as $zipPath) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) === true) {
                $zip->extractTo($this->binDir);
                $zip->close();
            }
            unlink($zipPath);
        }
    }

    private function downloadFile(string $url, string $dest, ?callable $onProgress): void
    {
        $context = stream_context_create([], [
            'notification' => function ($code, $severity, $message, $message_code, $bytes_transferred, $bytes_max) use ($onProgress) {
                if ($code === STREAM_NOTIFY_PROGRESS && $onProgress && $bytes_max > 0) {
                    $percent = round(($bytes_transferred / $bytes_max) * 100);
                    $onProgress("Downloading... {$percent}% ({$bytes_transferred}/{$bytes_max} bytes)");
                }
            }
        ]);

        $fp = fopen($url, 'r', false, $context);
        if (!$fp) {
            throw new \RuntimeException("Failed to open URL: {$url}");
        }

        file_put_contents($dest, $fp);
        fclose($fp);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->recursiveDelete("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
}
