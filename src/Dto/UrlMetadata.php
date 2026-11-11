<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class UrlMetadata
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $price = null,
        public ?string $imageUrl = null,
    ) {}

    public function isEmpty(): bool
    {
        return (
            $this->title === null
            && $this->description === null
            && $this->price === null
            && $this->imageUrl === null
        );
    }
}
