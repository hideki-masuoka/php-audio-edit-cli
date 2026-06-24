<?php

namespace App\Audio;

class AudioProcessingOptions
{
    public function __construct(
        public readonly bool $normalize = false,
        public readonly float $targetDb = -14.0,
        public readonly bool $noiseReduction = false,
        public readonly ?int $compressionLevel = null,
        public readonly ?int $sampleRate = null,
        public readonly ?int $bitDepth = null,
        public readonly bool $lowCut = false,
        public readonly int $lowCutFrequency = 80,
        public readonly bool $deesser = false,
        public readonly bool $gate = false,
        public readonly bool $compressor = false
    ) {}
}
