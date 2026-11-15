<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\UrlMetadataFetcher;
use PHPUnit\Framework\TestCase;

final class UrlMetadataFetcherTest extends TestCase
{
    public function testExtractsMetadataFromJsonLdProduct(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html>
            <head>
                <title>Page Title</title>
                <script type="application/ld+json">
                {
                    "@context": "https://schema.org/",
                    "@type": "Product",
                    "name": "Casque Sans Fil Pro",
                    "description": "Réduction de bruit active et autonomie 30h",
                    "image": "https://example.com/images/casque.jpg",
                    "offers": {
                        "@type": "Offer",
                        "priceCurrency": "EUR",
                        "price": "149,90"
                    }
                }
                </script>
            </head>
            <body></body>
            </html>
            HTML;

        $fetcher = new UrlMetadataFetcher(static fn(string $url): string => $html);
        $metadata = $fetcher->fetch('https://example.com/produit/casque');

        self::assertFalse($metadata->isEmpty());
        self::assertSame('Casque Sans Fil Pro', $metadata->title);
        self::assertSame('Réduction de bruit active et autonomie 30h', $metadata->description);
        self::assertSame('149.90', $metadata->price);
        self::assertSame('https://example.com/images/casque.jpg', $metadata->imageUrl);
    }

    public function testExtractsMetadataFromOpenGraphAndMetaTags(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html>
            <head>
                <meta property="og:title" content="Jeu de société &amp; extension">
                <meta property="og:description" content="Pour 2 à 5 joueurs">
                <meta property="product:price:amount" content="34.5">
                <meta property="og:image" content="https://example.com/jeu.png">
            </head>
            <body></body>
            </html>
            HTML;

        $fetcher = new UrlMetadataFetcher(static fn(string $url): string => $html);
        $metadata = $fetcher->fetch('https://example.com/jeu');

        self::assertSame('Jeu de société & extension', $metadata->title);
        self::assertSame('Pour 2 à 5 joueurs', $metadata->description);
        self::assertSame('34.50', $metadata->price);
        self::assertSame('https://example.com/jeu.png', $metadata->imageUrl);
    }

    public function testBlocksUnsafeOrInvalidUrls(): void
    {
        $fetcher = new UrlMetadataFetcher();

        self::assertTrue($fetcher->fetch('not-a-url')->isEmpty());
        self::assertTrue($fetcher->fetch('ftp://example.com/file')->isEmpty());
        self::assertTrue($fetcher->fetch('http://localhost/admin')->isEmpty());
        self::assertTrue($fetcher->fetch('http://127.0.0.1:8080/secret')->isEmpty());
        self::assertTrue($fetcher->fetch('http://[::1]:8080/secret')->isEmpty());
        self::assertTrue($fetcher->fetch('http://[fc00::1]/secret')->isEmpty());
    }

    public function testPopulateFromFormDataFillsEmptyFieldsOnly(): void
    {
        $html = <<<'HTML'
            <meta property="og:title" content="Titre Auto">
            <meta property="og:description" content="Description Auto">
            <meta property="product:price:amount" content="99.95">
            <meta property="og:image" content="https://example.com/auto.jpg">
            HTML;

        $fetcher = new UrlMetadataFetcher(static fn(string $url): string => $html);
        $wish = new \App\Entity\Wish();

        $result = $fetcher->populateFromFormData($wish, [
            'url' => 'https://example.com/produit',
            'name' => '',
            'description' => 'Description déjà saisie',
            'price' => '',
            'priority' => '3',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame('https://example.com/produit', $wish->getUrl());
        self::assertSame('Titre Auto', $wish->getName());
        self::assertSame('Description déjà saisie', $wish->getDescription());
        self::assertSame('99.95', $wish->getPrice());
        self::assertSame('https://example.com/auto.jpg', $wish->getImageUrl());
        self::assertSame(3, $wish->getPriority());
        self::assertSame('Coup de cœur', $wish->getPriorityEnum()->getLabel());
    }
}
