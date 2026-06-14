<?php

namespace App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Audio\FFmpegLocator;

class AudioProcessCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('process')
            ->setDescription('Process audio files (Normalize / Noise reduction)')
            ->addArgument('files', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Audio file(s) to process')
            ->addOption('normalize', 'm', InputOption::VALUE_NONE, 'Enable loudness normalization')
            ->addOption('target-db', 't', InputOption::VALUE_REQUIRED, 'Target loudness in LUFS/dB', '-14.0')
            ->addOption('noise-reduction', 'r', InputOption::VALUE_NONE, 'Enable noise reduction')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Directory to save processed files (defaults to same directory as input)')
            ->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Number of files to process in parallel', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = $input->getArgument('files');
        $normalize = $input->getOption('normalize');
        $targetDb = (float) $input->getOption('target-db');
        $noiseReduction = $input->getOption('noise-reduction');
        $outputDir = $input->getOption('output-dir');
        $concurrency = (int) $input->getOption('concurrency');

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
            
            // TODO: Process the audio files using the custom parallel process pool

        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}


