<?php

namespace App\Audio;

class AudioRestoreOptions
{
    public string $profile;
    public float $highFreqBoost;      // dB
    public float $presenceBoost;      // dB
    public bool $dynamicRestore;
    public bool $noiseGate;
    public bool $declick;
    public bool $declip;
    public float $attenuation;         // dB
    public ?int $sampleRate;
    public ?int $bitDepth;
    public ?int $compressionLevel;

    public function __construct(
        string $profile = 'auto',
        float $highFreqBoost = 0.0,
        float $presenceBoost = 0.0,
        bool $dynamicRestore = false,
        bool $noiseGate = false,
        bool $declick = false,
        bool $declip = false,
        float $attenuation = 0.0,
        ?int $sampleRate = null,
        ?int $bitDepth = null,
        ?int $compressionLevel = 5
    ) {
        $this->profile = $profile;
        $this->highFreqBoost = $highFreqBoost;
        $this->presenceBoost = $presenceBoost;
        $this->dynamicRestore = $dynamicRestore;
        $this->noiseGate = $noiseGate;
        $this->declick = $declick;
        $this->declip = $declip;
        $this->attenuation = $attenuation;
        $this->sampleRate = $sampleRate;
        $this->bitDepth = $bitDepth;
        $this->compressionLevel = $compressionLevel;
    }
}
