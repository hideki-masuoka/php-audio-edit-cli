<?php

namespace App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use App\Audio\FFmpegLocator;
use App\Audio\AudioProcessor;
use App\Audio\ProcessPool;

class AudioProcessCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('process')
            ->setDescription('Process audio files (Normalize / Noise reduction)')
            ->addArgument('files', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Audio file(s) or directories to process')
            ->addOption('normalize', 'm', InputOption::VALUE_NONE, 'Enable loudness normalization')
            ->addOption('target-db', 't', InputOption::VALUE_REQUIRED, 'Target loudness in LUFS/dB', '-14.0')
            ->addOption('noise-reduction', 'r', InputOption::VALUE_NONE, 'Enable noise reduction')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Directory to save processed files (defaults to same directory as input)')
            ->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Number of files to process in parallel', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputPatterns = $input->getArgument('files');
        $normalize = $input->getOption('normalize');
        $targetDb = (float) $input->getOption('target-db');
        $noiseReduction = $input->getOption('noise-reduction');
        $outputDir = $input->getOption('output-dir');
        $concurrency = (int) $input->getOption('concurrency');

        // Resolve input files (supporting directories and wildcards/globs)
        $files = [];
        foreach ($inputPatterns as $pattern) {
            if (is_dir($pattern)) {
                // Find all FLAC files in directory
                $dirFiles = glob(rtrim($pattern, '/') . '/*.flac');
                if ($dirFiles) {
                    $files = array_merge($files, $dirFiles);
                }
            } elseif (str_contains($pattern, '*')) {
                // Expand glob pattern
                $globFiles = glob($pattern);
                if ($globFiles) {
                    $files = array_merge($files, $globFiles);
                }
            } else {
                if (file_exists($pattern)) {
                    $files[] = $pattern;
                } else {
                    $output->writeln("<error>File not found: {$pattern}</error>");
                }
            }
        }

        $files = array_unique(array_map('realpath', $files));

        if (empty($files)) {
            $output->writeln('<error>No valid audio files found to process.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>=== Audio Edit CLI ===</info>');
        $output->writeln(sprintf('<info>Files to process: </info>%d', count($files)));
        $output->writeln(sprintf('<info>Normalization:    </info>%s', $normalize ? "Enabled (Target: {$targetDb} LUFS/dB)" : "Disabled"));
        $output->writeln(sprintf('<info>Noise Reduction:  </info>%s', $noiseReduction ? "Enabled" : "Disabled"));
        $output->writeln(sprintf('<info>Output Directory: </info>%s', $outputDir ?: "Same as input (suffixed with _processed)"));
        $output->writeln(sprintf('<info>Concurrency:      </info>%d', $concurrency));
        $output->writeln('');

        try {
            $locator = new FFmpegLocator();
            $paths = $locator->locate(function (string $message) use ($output) {
                $output->writeln("<comment>{$message}</comment>");
            });

            $output->writeln("<info>FFmpeg verified: {$paths['ffmpeg']}</info>");
            $output->writeln('');

            $processor = new AudioProcessor($paths['ffmpeg'], $paths['ffprobe']);

            // Build map of inputs to outputs
            $tasks = [];
            foreach ($files as $file) {
                $basename = pathinfo($file, PATHINFO_BASENAME);
                $filename = pathinfo($file, PATHINFO_FILENAME);
                $extension = pathinfo($file, PATHINFO_EXTENSION);

                if ($outputDir) {
                    $targetOutputFile = rtrim($outputDir, '/') . '/' . $basename;
                } else {
                    $targetOutputFile = dirname($file) . '/' . $filename . '_processed.' . $extension;
                }

                $tasks[] = [
                    'input' => $file,
                    'output' => $targetOutputFile,
                ];
            }

            // Setup a progress bar
            $progressBar = new ProgressBar($output, count($tasks));
            $progressBar->start();

            $pool = new ProcessPool(
                $tasks,
                function (array $task) use ($processor, $normalize, $targetDb, $noiseReduction) {
                    return $processor->createProcess(
                        $task['input'],
                        $task['output'],
                        $normalize,
                        $targetDb,
                        $noiseReduction
                    );
                },
                $concurrency
            );

            $pool->run(
                function (array $task) use ($output, $progressBar) {
                    // Optional: log when a process starts if not using progress bar,
                    // but with progress bar we can just let it run or use sections.
                },
                function (array $task) use ($progressBar) {
                    $progressBar->advance();
                },
                function (array $task, string $error) use ($output, $progressBar) {
                    $progressBar->advance();
                    $output->writeln("\n<error>Failed to process " . basename($task['input']) . ": {$error}</error>");
                }
            );

            $progressBar->finish();
            $output->writeln("\n\n<info>Processing completed successfully!</info>");

        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}



