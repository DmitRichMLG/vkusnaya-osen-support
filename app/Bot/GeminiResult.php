<?php

namespace App\Bot;

final class GeminiResult
{
    public function __construct(
        public readonly string $model,
        public readonly string $text,
        public readonly int $latencyMs,
    ) {}
}
