<?php

namespace App\Helpers;

function normalizeUrl(string $url): string
{
    $parsed = parse_url($url);
    $scheme = $parsed['scheme'] ?? '';
    $host = $parsed['host'] ?? "";

    return strtolower("{$scheme}://{$host}");
}

function parseHtmlData(string $html, string $url): array
{
    $data = [
        'h1' => '',
        'title' => '',
        'description' => ''
    ];

    if (empty($html)) {
        return $data;
    }

    $dom = new \DOMDocument();

    try {
        $dom->loadHTML($html);

        $h1Tags = $dom->getElementsByTagName('h1');
        if ($h1Tags->length > 0 && $h1Tags->item(0) !== null) {
            $data['h1'] = trim($h1Tags->item(0)->textContent);
        }
        $titleTags = $dom->getElementsByTagName('title');
        if ($titleTags->length > 0 && $titleTags->item(0) !== null) {
            $data['title'] = trim($titleTags->item(0)->textContent);
        }
        $metaTags = $dom->getElementsByTagName('meta');
        foreach ($metaTags as $meta) {
            if ($meta->getAttribute('name') === 'description') {
                $data['description'] = trim($meta->getAttribute('content'));
                break;
            }
        }
    } catch (\Exception $e) {
        error_log("Failed to load HTML: " . $e->getMessage());
    }
    return $data;
}
