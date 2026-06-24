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
use App\Audio\AudioProcessingOptions;
use App\Audio\ProcessPool;

class AudioProcessCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('process')
            ->setDescription('Process audio files (Normalize / Noise reduction / Podcast optimization)')
            ->addArgument('files', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Audio file(s) or directories to process')
            ->addOption('normalize', 'm', InputOption::VALUE_NONE, 'Enable loudness normalization')
            ->addOption('target-db', 't', InputOption::VALUE_REQUIRED, 'Target loudness in LUFS/dB', '-14.0')
            ->addOption('noise-reduction', 'r', InputOption::VALUE_NONE, 'Enable noise reduction')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Directory to save processed files (defaults to same directory as input)')
            ->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Number of files to process in parallel', '4')
            ->addOption('compression-level', 'p', InputOption::VALUE_REQUIRED, 'FLAC compression level (0-12)', '5')
            ->addOption('sample-rate', 's', InputOption::VALUE_REQUIRED, 'Output sample rate in Hz (e.g., 44100, 48000)')
            ->addOption('bit-depth', 'd', InputOption::VALUE_REQUIRED, 'Output bit depth (16 or 24)')
            ->addOption('low-cut', 'l', InputOption::VALUE_NONE, 'Enable low-cut (high-pass) filter to reduce low-frequency rumble')
            ->addOption('low-cut-freq', null, InputOption::VALUE_REQUIRED, 'Low-cut filter cutoff frequency in Hz', '80')
            ->addOption('deesser', null, InputOption::VALUE_NONE, 'Enable de-esser to reduce vocal sibilance')
            ->addOption('gate', 'g', InputOption::VALUE_NONE, 'Enable noise gate to mute background noise during silence')
            ->addOption('compressor', null, InputOption::VALUE_NONE, 'Enable compressor to balance vocal dynamic range');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputPatterns = $input->getArgument('files');
        $normalize = $input->getOption('normalize');
        $targetDb = (float) $input->getOption('target-db');
        $noiseReduction = $input->getOption('noise-reduction');
        $outputDir = $input->getOption('output-dir');
        $concurrency = (int) $input->getOption('concurrency');
        $compressionLevel = (int) $input->getOption('compression-level');
        $sampleRate = $input->getOption('sample-rate') ? (int) $input->getOption('sample-rate') : null;
        $bitDepth = $input->getOption('bit-depth') ? (int) $input->getOption('bit-depth') : null;
        $lowCut = $input->getOption('low-cut');
        $lowCutFreq = (int) $input->getOption('low-cut-freq');
        $deesser = $input->getOption('deesser');
        $gate = $input->getOption('gate');
        $compressor = $input->getOption('compressor');

        if ($compressionLevel < 0 || $compressionLevel > 12) {
            $output->writeln('<error>Error: Compression level must be an integer between 0 and 12.</error>');
            return Command::FAILURE;
        }

        if ($sampleRate !== null && $sampleRate <= 0) {
            $output->writeln('<error>Error: Sample rate must be a positive integer.</error>');
            return Command::FAILURE;
        }

        if ($bitDepth !== null && $bitDepth !== 16 && $bitDepth !== 24) {
            $output->writeln('<error>Error: Bit depth must be either 16 or 24.</error>');
            return Command::FAILURE;
        }

        if ($lowCutFreq <= 0) {
            $output->writeln('<error>Error: Low-cut frequency must be a positive integer.</error>');
            return Command::FAILURE;
        }

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
        $output->writeln(sprintf('<info>Low-Cut Filter:    </info>%s', $lowCut ? "Enabled ({$lowCutFreq} Hz)" : "Disabled"));
        $output->writeln(sprintf('<info>De-esser:          </info>%s', $deesser ? "Enabled" : "Disabled"));
        $output->writeln(sprintf('<info>Noise Gate:        </info>%s', $gate ? "Enabled" : "Disabled"));
        $output->writeln(sprintf('<info>Compressor:        </info>%s', $compressor ? "Enabled" : "Disabled"));
        $output->writeln(sprintf('<info>Compression Level:</info>%d (0-12)', $compressionLevel));
        $output->writeln(sprintf('<info>Sample Rate:      </info>%s', $sampleRate ? "{$sampleRate} Hz" : "Keep original"));
        $output->writeln(sprintf('<info>Bit Depth:        </info>%s', $bitDepth ? "{$bitDepth}-bit" : "Keep original"));
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

            // Setup options DTO
            $options = new AudioProcessingOptions(
                normalize: $normalize,
                targetDb: $targetDb,
                noiseReduction: $noiseReduction,
                compressionLevel: $compressionLevel,
                sampleRate: $sampleRate,
                bitDepth: $bitDepth,
                lowCut: $lowCut,
                lowCutFrequency: $lowCutFreq,
                deesser: $deesser,
                gate: $gate,
                compressor: $compressor
            );

            // 事前に各ファイルの長さを取得する（進捗計算のため）
            $output->writeln('<comment>Analyzing audio durations...</comment>');
            $durations = [];
            foreach ($files as $file) {
                $durations[$file] = $processor->getDuration($file);
            }
            $output->writeln('<info>Analysis complete. Starting processing...</info>');
            $output->writeln('');

            $useSections = method_exists($output, 'section');
            $sections = [];

            if ($useSections) {
                foreach ($tasks as $idx => $task) {
                    $sections[$task['input']] = [
                        'section' => $output->section(),
                        'index' => $idx + 1,
                        'total' => count($tasks),
                        'filename' => basename($task['input']),
                        'status' => 'queued',
                        'percent' => 0,
                        'speed' => 'N/A'
                    ];
                    // 初期描画
                    $sections[$task['input']]['section']->writeln(sprintf(
                        '[%d/%d] <comment>%s</comment>: queued',
                        $idx + 1,
                        count($tasks),
                        basename($task['input'])
                    ));
                }
            } else {
                $progressBar = new ProgressBar($output, count($tasks));
                $progressBar->start();
            }

            $pool = new ProcessPool(
                $tasks,
                function (array $task) use ($processor, $options) {
                    return $processor->createProcess(
                        $task['input'],
                        $task['output'],
                        $options
                    );
                },
                $concurrency
            );

            $pool->run(
                function (array $task) use (&$sections, $useSections) {
                    if ($useSections) {
                        $input = $task['input'];
                        $sec = $sections[$input];
                        $sec['status'] = 'processing';
                        $sections[$input] = $sec;
                        
                        $sec['section']->overwrite(sprintf(
                            '[%d/%d] <info>%s</info>: 0%% [                              ]',
                            $sec['index'],
                            $sec['total'],
                            $sec['filename']
                        ));
                    }
                },
                function (array $task) use (&$sections, $useSections, $progressBar) {
                    if ($useSections) {
                        $input = $task['input'];
                        $sec = $sections[$input];
                        $sec['status'] = 'done';
                        $sections[$input] = $sec;
                        
                        $sec['section']->overwrite(sprintf(
                            '[%d/%d] <info>%s</info>: 100%% [==============================] <info>Done</info>',
                            $sec['index'],
                            $sec['total'],
                            $sec['filename']
                        ));
                    } else {
                        $progressBar->advance();
                    }
                },
                function (array $task, string $error) use (&$sections, $useSections, $progressBar, $output) {
                    if ($useSections) {
                        $input = $task['input'];
                        $sec = $sections[$input];
                        $sec['status'] = 'failed';
                        $sections[$input] = $sec;
                        
                        $sec['section']->overwrite(sprintf(
                            '[%d/%d] <error>%s</error>: <error>Failed</error> (%s)',
                            $sec['index'],
                            $sec['total'],
                            $sec['filename'],
                            $error
                        ));
                    } else {
                        $progressBar->advance();
                        $output->writeln("\n<error>Failed to process " . basename($task['input']) . ": {$error}</error>");
                    }
                },
                function (array $task, string $log) use (&$sections, $useSections, $durations) {
                    if (!$useSections) {
                        return;
                    }
                    $input = $task['input'];
                    $sec = $sections[$input];
                    $duration = $durations[$input] ?? 0.0;
                    
                    // time=00:00:10.50 のような記述を探す
                    if ($duration > 0 && preg_match('/time=(\d+):(\d+):(\d+\.\d+)/', $log, $matches)) {
                        $hours = (int)$matches[1];
                        $minutes = (int)$matches[2];
                        $seconds = (float)$matches[3];
                        $currentTime = ($hours * 3600) + ($minutes * 60) + $seconds;
                        
                        $percent = min(100, max(0, (int)(($currentTime / $duration) * 100)));
                        $sec['percent'] = $percent;
                    }
                    
                    // speed= 1.5x のような記述を探す
                    if (preg_match('/speed=\s*(\d+\.\d+x)/', $log, $matches)) {
                        $sec['speed'] = $matches[1];
                    }
                    
                    $sections[$input] = $sec;
                    
                    // プログレスバーを描画
                    $barWidth = 30;
                    $filledWidth = (int)($barWidth * ($sec['percent'] / 100));
                    $emptyWidth = $barWidth - $filledWidth;
                    $bar = str_repeat('=', $filledWidth) . ($filledWidth < $barWidth ? '>' : '') . str_repeat(' ', max(0, $emptyWidth - 1));
                    
                    $sec['section']->overwrite(sprintf(
                        '[%d/%d] <info>%s</info>: %d%% [%s] (speed: %s)',
                        $sec['index'],
                        $sec['total'],
                        $sec['filename'],
                        $sec['percent'],
                        $bar,
                        $sec['speed']
                    ));
                }
            );

            if (!$useSections) {
                $progressBar->finish();
            }
            $output->writeln("\n\n<info>Processing completed successfully!</info>");

        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}




