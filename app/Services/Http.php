<?php
/*
 * Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved.
 *
 * Proprietary and confidential. This software is LICENSED, NOT SOLD.
 * Unauthorized copying, modification, distribution, or use of this file,
 * via any medium, is strictly prohibited and will be prosecuted to the
 * fullest extent of the law (17 U.S.C. Sections 501-505). See LICENSE.txt.
 * Licensing: licensing@wkrllc.com
 */
declare(strict_types=1);

/**
 * The only place in the application that opens a socket.
 *
 * Every integration driver goes through here, so timeouts, TLS verification and
 * error handling are decided once. A failing outside service degrades the
 * feature it belongs to; it never takes the request down with it.
 */
final class Http
{
    public const TIMEOUT = 12;

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:array<mixed>, raw:string, error:string}
     */
    public static function json(string $method, string $url, array $headers = [], ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => [], 'raw' => '', 'error' => 'The curl extension is not available on this host.'];
        }

        $h = ['Accept: application/json'];
        foreach ($headers as $k => $v) { $h[] = $k . ': ' . $v; }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $h,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);

        $raw    = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        $body = json_decode($raw, true);
        return [
            'status' => $status,
            'body'   => is_array($body) ? $body : [],
            'raw'    => $raw,
            'error'  => $err,
        ];
    }

    /** Request headers, normalised to lower-case keys. Works under any SAPI. */
    public static function headers(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $out[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) { $out['content-type'] = (string) $_SERVER['CONTENT_TYPE']; }
        return $out;
    }

    /** The public base URL of this install — what webhooks are registered against. */
    public static function baseUrl(): string
    {
        $configured = trim((string) App::setting('app_base_url', ''));
        if ($configured !== '') { return rtrim($configured, '/'); }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . base_path();
    }
}
