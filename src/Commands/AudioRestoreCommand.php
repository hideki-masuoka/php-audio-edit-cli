<?php

namespace App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use App\Audio\FFmpegLocator;
use App\Audio\AudioAnalyzer;
use App\Audio\AudioRestorer;
use App\Audio\AudioRestoreOptions;
use App\Audio\ProcessPool;

class AudioRestoreCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('restore')
            ->setDescription('Analyze and restore audio files degraded by noise reduction or clipping')
            ->addArgument('files', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Audio file(s) or directories to restore')
            ->addOption('analyze-only', 'a', InputOption::VALUE_NONE, 'Only run analysis and print suggestions without processing')
            ->addOption('high-freq-boost', null, InputOption::VALUE_REQUIRED, 'Override suggested high-frequency boost (dB)')
            ->addOption('presence-boost', null, InputOption::VALUE_REQUIRED, 'Override suggested presence boost (dB)')
            ->addOption('dynamic-restore', null, InputOption::VALUE_NONE, 'Force enable dynamic range restoration')
            ->addOption('noise-gate', null, InputOption::VALUE_NONE, 'Enable noise gate')
            ->addOption('declick', null, InputOption::VALUE_NONE, 'Enable de-click / de-artifact filter')
            ->addOption('declip', null, InputOption::VALUE_NONE, 'Enable de-clip / clipping repair filter')
            ->addOption('attenuation', null, InputOption::VALUE_REQUIRED, 'Declip attenuation level in dB (e.g., -3.0)')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Directory to save restored files')
            ->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Number of files to process in parallel', '4')
            ->addOption('compression-level', 'p', InputOption::VALUE_REQUIRED, 'FLAC compression level (0-12)', '5')
            ->addOption('sample-rate', 's', InputOption::VALUE_REQUIRED, 'Output sample rate in Hz')
            ->addOption('bit-depth', 'd', InputOption::VALUE_REQUIRED, 'Output bit depth (16 or 24)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputPatterns = $input->getArgument('files');
        $analyzeOnly = $input->getOption('analyze-only');
        $outputDir = $input->getOption('output-dir');
        $concurrency = (int) $input->getOption('concurrency');
        $compressionLevel = (int) $input->getOption('compression-level');
        $sampleRate = $input->getOption('sample-rate') ? (int) $input->getOption('sample-rate') : null;
        $bitDepth = $input->getOption('bit-depth') ? (int) $input->getOption('bit-depth') : null;

        if ($compressionLevel < 0 || $compressionLevel > 12) {
            $output->writeln('<error>Error: Compression level must be an integer between 0 and 12.</error>');
            return Command::FAILURE;
        }

        // Resolve input files
        $files = [];
        foreach ($inputPatterns as $pattern) {
            if (is_dir($pattern)) {
                $dirFiles = glob(rtrim($pattern, '/') . '/*.flac');
                if ($dirFiles) {
                    $files = array_merge($files, $dirFiles);
                }
            } elseif (str_contains($pattern, '*')) {
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
            $output->writeln('<error>No valid audio files found to restore.</error>');
            return Command::FAILURE;
        }

        try {
            $locator = new FFmpegLocator();
            $paths = $locator->locate(function (string $message) use ($output) {
                // Keep dynamic path location log silent or light in non-verbose
            });

            $analyzer = new AudioAnalyzer($paths['ffmpeg'], $paths['ffprobe']);
            $restorer = new AudioRestorer($paths['ffmpeg'], $paths['ffprobe']);

            $output->writeln('<info>=== Audio Restore Analysis ===</info>');
            $output->writeln(sprintf('<info>Analyzing %d file(s)...</info>', count($files)));
            $output->writeln('');

            $fileConfigs = [];
            foreach ($files as $file) {
                $filename = basename($file);
                $output->writeln("Analyzing <comment>{$filename}</comment>...");
                
                // Perform acoustic analysis
                $options = $analyzer->analyze($file);

                // Apply manual overrides if specified
                if ($input->getOption('high-freq-boost') !== null) {
                    $options->highFreqBoost = (float) $input->getOption('high-freq-boost');
                }
                if ($input->getOption('presence-boost') !== null) {
                    $options->presenceBoost = (float) $input->getOption('presence-boost');
                }
                if ($input->getOption('dynamic-restore')) {
                    $options->dynamicRestore = true;
                }
                if ($input->getOption('noise-gate')) {
                    $options->noiseGate = true;
                }
                if ($input->getOption('declick')) {
                    $options->declick = true;
                }
                if ($input->getOption('declip')) {
                    $options->declip = true;
                }
                if ($input->getOption('attenuation') !== null) {
                    $options->attenuation = (float) $input->getOption('attenuation');
                }
                if ($sampleRate !== null) {
                    $options->sampleRate = $sampleRate;
                }
                if ($bitDepth !== null) {
                    $options->bitDepth = $bitDepth;
                }
                $options->compressionLevel = $compressionLevel;

                $fileConfigs[$file] = $options;

                $output->writeln(sprintf("  Suggested High-Freq Boost: <info>%.1fdB</info>", $options->highFreqBoost));
                $output->writeln(sprintf("  Suggested Presence Boost:  <info>%.1fdB</info>", $options->presenceBoost));
                $output->writeln(sprintf("  Suggested Dynamic Restore: <info>%s</info>", $options->dynamicRestore ? "Yes" : "No"));
                $output->writeln(sprintf("  Noise Gate:                <info>%s</info>", $options->noiseGate ? "Enabled" : "Disabled"));
                $output->writeln(sprintf("  De-click / De-artifact:    <info>%s</info>", $options->declick ? "Enabled" : "Disabled"));
                $output->writeln(sprintf("  De-clip (Clipping Repair): <info>%s</info>%s", $options->declip ? "Enabled" : "Disabled", $options->declip ? " (Attenuation: {$options->attenuation}dB)" : ""));
                $output->writeln('');
            }

            if ($analyzeOnly) {
                $output->writeln('<info>Analysis complete. Processing skipped due to --analyze-only flag.</info>');
                return Command::SUCCESS;
            }

            $output->writeln('<info>=== Audio Restoration Processing ===</info>');

            // Build map of inputs to outputs
            $tasks = [];
            foreach ($files as $file) {
                $basename = pathinfo($file, PATHINFO_BASENAME);
                $filename = pathinfo($file, PATHINFO_FILENAME);
                $extension = pathinfo($file, PATHINFO_EXTENSION);

                if ($outputDir) {
                    $targetOutputFile = rtrim($outputDir, '/') . '/' . $basename;
                } else {
                    $targetOutputFile = dirname($file) . '/' . $filename . '_restored.' . $extension;
                }

                $tasks[] = [
                    'input' => $file,
                    'output' => $targetOutputFile,
                    'options' => $fileConfigs[$file]
                ];
            }

            $output->writeln('<comment>Calculating audio durations...</comment>');
            $durations = [];
            foreach ($files as $file) {
                $durations[$file] = $restorer->getDuration($file);
            }
            $output->writeln('');

            $useSections = method_exists($output, 'section');
            $sections = [];
            $progressBar = null;

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
                function (array $task) use ($restorer) {
                    return $restorer->createProcess(
                        $task['input'],
                        $task['output'],
                        $task['options']
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
                            '[%d/%d] <info>%s</info>: 100%% [==============================] <info>Restored</info>',
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
                        $output->writeln("\n<error>Failed to restore " . basename($task['input']) . ": {$error}</error>");
                    }
                },
                function (array $task, string $log) use (&$sections, $useSections, $durations) {
                    if (!$useSections) {
                        return;
                    }
                    $input = $task['input'];
                    $sec = $sections[$input];
                    $duration = $durations[$input] ?? 0.0;
                    
                    if ($duration > 0 && preg_match('/time=(\d+):(\d+):(\d+\.\d+)/', $log, $matches)) {
                        $hours = (int)$matches[1];
                        $minutes = (int)$matches[2];
                        $seconds = (float)$matches[3];
                        $currentTime = ($hours * 3600) + ($minutes * 60) + $seconds;
                        
                        $percent = min(100, max(0, (int)(($currentTime / $duration) * 100)));
                        $sec['percent'] = $percent;
                    }
                    
                    if (preg_match('/speed=\s*(\d+\.\d+x)/', $log, $matches)) {
                        $sec['speed'] = $matches[1];
                    }
                    
                    $sections[$input] = $sec;
                    
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
            $output->writeln("\n\n<info>Restoration completed successfully!</info>");

        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
