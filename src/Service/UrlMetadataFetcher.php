<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\UrlMetadata;
use App\Entity\GiftIdea;
use App\Entity\Wish;

// @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods
final class UrlMetadataFetcher
{
    private const int MAX_BYTES = 524_288;

    /**
     * @var null|\Closure(string): ?string
     */
    private readonly ?\Closure $httpDownloader;

    /**
     * @param null|\Closure(string): ?string $httpDownloader
     */
    public function __construct(?\Closure $httpDownloader = null)
    {
        $this->httpDownloader = $httpDownloader;
    }

    /**
     * Populates a Wish or GiftIdea from submitted form data and URL metadata.
     *
     * @param array<string, string|null> $formData
     * @return array{message: string, status: string}
     */
    public function populateFromFormData(Wish|GiftIdea $entity, array $formData): array
    {
        $url = trim($formData['url'] ?? '');
        $name = trim($formData['name'] ?? '');
        $description = trim($formData['description'] ?? '');
        $rawPrice = trim($formData['price'] ?? '');
        $imageUrl = trim($formData['imageUrl'] ?? '');
        $rawPriority = trim($formData['priority'] ?? '');

        if ($url !== '') {
            $entity->setUrl($url);
        }
        if ($name !== '') {
            $entity->setName($name);
        }
        if ($description !== '') {
            $entity->setDescription($description);
        }
        if ($imageUrl !== '') {
            $entity->setImageUrl($imageUrl);
        }
        if ($rawPriority !== '' && is_numeric($rawPriority)) {
            $entity->setPriority((int) $rawPriority);
        }
        $normalizedInputPrice = $this->normalizePrice($rawPrice);
        if ($normalizedInputPrice !== null) {
            $entity->setPrice($normalizedInputPrice);
        }

        if ($url === '') {
            return [
                'message' => 'Veuillez saisir une URL avant de pré-remplir.',
                'status' => 'warning',
            ];
        }

        $meta = $this->fetch($url);
        if ($meta->isEmpty()) {
            return [
                'message' => "Impossible d'extraire automatiquement les informations depuis ce lien.",
                'status' => 'warning',
            ];
        }

        if ($meta->title !== null && $name === '') {
            $entity->setName($meta->title);
        }
        if ($meta->description !== null && $description === '') {
            $entity->setDescription($meta->description);
        }
        if ($meta->price !== null && $normalizedInputPrice === null) {
            $entity->setPrice($meta->price);
        }
        if ($meta->imageUrl !== null && $imageUrl === '') {
            $entity->setImageUrl($meta->imageUrl);
        }

        return [
            'message' => 'Informations récupérées depuis le lien !',
            'status' => 'success',
        ];
    }

    public function fetch(string $url): UrlMetadata
    {
        $trimmedUrl = trim($url);
        if (!$this->isSafeUrl($trimmedUrl)) {
            return new UrlMetadata();
        }

        $html = $this->downloadHtml($trimmedUrl);
        if ($html === null || trim($html) === '') {
            return new UrlMetadata();
        }

        return $this->parseHtml($html);
    }

    public function parseHtml(string $html): UrlMetadata
    {
        $jsonLd = $this->extractFromJsonLd($html);
        $metaTags = $this->extractMetaTags($html);

        $rawTitle = $jsonLd['title'] ?? $metaTags['og:title'] ?? $metaTags['twitter:title'] ?? null;
        $titleMatches = [];
        if ($rawTitle === null && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $titleMatches)) {
            $rawTitle = $titleMatches[1];
        }

        $description =
            $jsonLd['description']
            ?? $metaTags['og:description']
            ?? $metaTags['twitter:description']
            ?? $metaTags['description']
            ?? null;

        $rawPrice =
            $jsonLd['price']
            ?? $metaTags['product:price:amount']
            ?? $metaTags['og:price:amount']
            ?? $metaTags['price']
            ?? null;

        $imageUrl = $jsonLd['image'] ?? $metaTags['og:image'] ?? $metaTags['twitter:image'] ?? null;

        return new UrlMetadata(
            title: $this->cleanText($rawTitle, 255),
            description: $this->cleanText($description, 2000),
            price: $this->normalizePrice($rawPrice),
            imageUrl: $this->cleanText($imageUrl, 500),
        );
    }

    private function isSafeUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], strict: true)) {
            return false;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || strtolower($host) === 'localhost') {
            return false;
        }

        if ($this->httpDownloader !== null) {
            return true;
        }

        $ips = gethostbynamel($host);
        if ($ips === false || $ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    private function downloadHtml(string $url): ?string
    {
        if ($this->httpDownloader !== null) {
            return ($this->httpDownloader)($url);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 3.5,
                'follow_location' => 1,
                'max_redirects' => 3,
                'user_agent' => 'Mozilla/5.0 (compatible; KDOBot/1.0)',
                'header' => "Accept: text/html,application/xhtml+xml\r\nAccept-Language: fr-FR,fr;q=0.9,en;q=0.8\r\n",
            ],
        ]);

        set_error_handler(static fn(): bool => true);
        try {
            $content = file_get_contents($url, false, $context, 0, self::MAX_BYTES);
        } finally {
            restore_error_handler();
        }

        return is_string($content) ? $content : null;
    }

    /**
     * @return array{title?: string, description?: string, price?: string, image?: string}
     */
    private function extractFromJsonLd(string $html): array
    {
        $result = [];
        $matches = [];
        $pattern = '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is';
        if (!preg_match_all($pattern, $html, $matches)) {
            return $result;
        }

        foreach ($matches[1] as $rawJson) {
            $product = $this->decodeProductJson($rawJson);
            if ($product === null) {
                continue;
            }

            if ($product['title'] !== '') {
                $result['title'] = $product['title'];
            }
            if ($product['description'] !== '') {
                $result['description'] = $product['description'];
            }
            if ($product['image'] !== '') {
                $result['image'] = $product['image'];
            }
            if ($product['price'] !== '') {
                $result['price'] = $product['price'];
            }

            break;
        }

        return $result;
    }

    /**
     * @return null|array{title: string, description: string, image: string, price: string}
     */
    private function decodeProductJson(string $rawJson): ?array
    {
        if (!preg_match('/"@type"\s*:\s*(?:\[\s*)?"[^"]*Product/i', $rawJson)) {
            return null;
        }

        $nameMatch = [];
        $descMatch = [];
        $imgMatch = [];
        $priceMatch = [];

        $title = preg_match('/"name"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $rawJson, $nameMatch)
            ? stripcslashes($nameMatch[1])
            : '';
        $description = preg_match('/"description"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $rawJson, $descMatch)
            ? stripcslashes($descMatch[1])
            : '';
        $image = preg_match('/"image"\s*:\s*(?:\[\s*)?"((?:[^"\\\\]|\\\\.)*)"/s', $rawJson, $imgMatch)
            ? stripcslashes($imgMatch[1])
            : '';
        $price = preg_match('/"(?:lowPrice|price)"\s*:\s*"?([0-9]+(?:[.,][0-9]{1,2})?)"?/i', $rawJson, $priceMatch)
            ? $priceMatch[1]
            : '';

        return [
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'price' => $price,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractMetaTags(string $html): array
    {
        $tags = [];
        $matches = [];
        if (!preg_match_all('/<meta\s+[^>]*>/i', $html, $matches)) {
            return $tags;
        }

        foreach ($matches[0] as $metaHtml) {
            $key = null;
            $keyMatch = [];
            if (preg_match('/(?:property|name|itemprop)=["\']([^"\']+)["\']/i', $metaHtml, $keyMatch)) {
                $key = strtolower(trim($keyMatch[1]));
            }
            if ($key === null || array_key_exists($key, $tags)) {
                continue;
            }
            $contentMatch = [];
            if (preg_match('/content=["\']([^"\']+)["\']/i', $metaHtml, $contentMatch)) {
                $tags[$key] = $contentMatch[1];
            }
        }

        return $tags;
    }

    private function cleanText(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $decoded = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $decoded));

        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, $maxLength);
    }

    private function normalizePrice(?string $rawPrice): ?string
    {
        if ($rawPrice === null || $rawPrice === '') {
            return null;
        }

        $cleaned = str_replace(',', '.', trim($rawPrice));
        $matches = [];
        if (!preg_match('/(\d+(?:\.\d{1,2})?)/', $cleaned, $matches)) {
            return null;
        }

        $candidate = $matches[1];
        if (!is_numeric($candidate)) {
            return null;
        }

        $numeric = (float) $candidate;
        if ($numeric <= 0) {
            return null;
        }

        return number_format($numeric, 2, '.', '');
    }
}
