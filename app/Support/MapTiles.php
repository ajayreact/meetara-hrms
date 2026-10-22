<?php

namespace App\Support;

class MapTiles
{
    public const DEFAULT_TEMPLATE = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

    /**
     * @return array{template: string, disclosure: string}
     */
    public static function payload(): array
    {
        $template = self::template();
        $host = (string) parse_url($template, PHP_URL_HOST);

        if ($host === 'tile.openstreetmap.org') {
            return [
                'template' => $template,
                'disclosure' => 'Opening this map loads tiles from OpenStreetMap and sends the area you are viewing to that service. No API key is used.',
            ];
        }

        return [
            'template' => $template,
            'disclosure' => 'Opening this map loads tiles from '.$host.' and sends the area you are viewing to that service.',
        ];
    }

    public static function template(): string
    {
        $configured = config('hrms.map_tiles.url_template');

        if (! is_string($configured) || ! self::templateIsSafe($configured)) {
            return self::DEFAULT_TEMPLATE;
        }

        return $configured;
    }

    public static function templateIsSafe(string $template): bool
    {
        return preg_match('#\Ahttps://[a-z0-9.-]+(?::\d+)?/(?:[a-z0-9._~%-]+/)*\{z\}/\{x\}/\{y\}\.png\z#i', $template) === 1;
    }
}
