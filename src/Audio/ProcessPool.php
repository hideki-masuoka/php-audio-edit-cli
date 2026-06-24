<?php

namespace App\Audio;

use Symfony\Component\Process\Process;

class ProcessPool
{
    private array $queue;
    /** @var array<array{item: mixed, process: Process}> */
    private array $running = [];
    private int $concurrency;
    /** @var callable */
    private $processCreator;

    /**
     * @param array $items Array of items to be processed (e.g., file paths)
     * @param callable $processCreator Callback that takes an item and returns a Process
     * @param int $concurrency Max number of parallel processes
     */
    public function __construct(array $items, callable $processCreator, int $concurrency = 4)
    {
        $this->queue = $items;
        $this->processCreator = $processCreator;
        $this->concurrency = max(1, $concurrency);
    }

    /**
     * Executes the pool, blocking until all processes are complete.
     */
    public function run(
        callable $onStart,
        callable $onSuccess,
        callable $onFailure,
        ?callable $onProgress = null
    ): void {
        while (!empty($this->queue) || !empty($this->running)) {
            // Fill the running pool up to the allowed concurrency limit
            while (count($this->running) < $this->concurrency && !empty($this->queue)) {
                $item = array_shift($this->queue);
                try {
                    /** @var Process $process */
                    $process = ($this->processCreator)($item);
                    $process->start();
                    
                    $this->running[] = [
                        'item' => $item,
                        'process' => $process,
                    ];
                    
                    $onStart($item);
                } catch (\Exception $e) {
                    $onFailure($item, $e->getMessage());
                }
            }

            // Monitor and handle running processes
            foreach ($this->running as $index => $active) {
                /** @var Process $process */
                $process = $active['process'];
                $item = $active['item'];

                if ($onProgress !== null && $process->isRunning()) {
                    $inc = $process->getIncrementalErrorOutput();
                    if ($inc !== '') {
                        $onProgress($item, $inc);
                    }
                }

                if (!$process->isRunning()) {
                    // Check leftover output before removing
                    if ($onProgress !== null) {
                        $inc = $process->getIncrementalErrorOutput();
                        if ($inc !== '') {
                            $onProgress($item, $inc);
                        }
                    }

                    // Remove from running list
                    unset($this->running[$index]);
                    
                    if ($process->isSuccessful()) {
                        $onSuccess($item);
                    } else {
                        $onFailure($item, trim($process->getErrorOutput() ?: "Process failed with exit code " . $process->getExitCode()));
                    }
                }
            }

            // Normalize array indexes
            $this->running = array_values($this->running);

            // Avoid CPU spinning with a brief sleep (50ms)
            usleep(50000);
        }
    }
}
