<?php

namespace App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AudioProcessCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('process')
            ->setDescription('Process audio files (Normalize / Noise reduction)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Audio Edit CLI - Ready to process audio files!</info>');
        return Command::SUCCESS;
    }
}
