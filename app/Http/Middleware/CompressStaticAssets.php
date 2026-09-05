<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzips text assets that PHP serves itself.
 *
 * Only one asset actually needs this, and it is the reason the class exists:
 * Livewire serves its 162 KB runtime from a PHP route rather than from
 * `public/`, so nothing in front of the application ever sees it as a file.
 * `FrontendAssets::returnJavaScriptAsFile()` answers with `response()->file()`,
 * which sets no `Content-Encoding` regardless of what the client offered —
 * measured against a production-shaped `artisan serve`: 166,146 bytes on the
 * wire with `Accept-Encoding: gzip` on the request. The same bytes gzip to
 * ~55 KB.
 *
 * Why that is worth a middleware rather than a shrug. The LCP budget on /events
 * is bandwidth-bound: ci/lighthouserc.cjs simulates Slow 4G at ~184 KB/s, so
 * bytes on the connection push LCP out even when they block no paint. The
 * runtime is the largest resource on the page, and two thirds of it is
 * compressible air:
 *
 *     162 KB uncompressed  -> ~901ms of transfer
 *      55 KB gzipped       -> ~306ms
 *
 * Assets under `public/` (the Vite bundle, the font) are NOT handled here. In
 * production nginx serves those directly and compresses them itself — PHP never
 * runs for them — so doing it here would be dead code that only ever fired
 * under `artisan serve`. woff2 is already compressed and gains nothing anyway.
 *
 * Deliberately narrow, and it fails open: anything it is unsure about is passed
 * through untouched. A middleware that mangles a response is a far worse outcome
 * than one that misses a compression opportunity.
 */
class CompressStaticAssets
{
    /**
     * Compress above this size only. Below roughly a kilobyte, gzip's own header
     * and the CPU on both ends cost more than the bytes saved, and a single
     * packet is a single packet either way.
     */
    private const MINIMUM_BYTES = 1024;

    /**
     * Level 6 is zlib's default and the knee of the curve: level 9 spends
     * noticeably more CPU per request for around a percent of size on this file.
     * The response is cached for a year by Livewire's own headers, but PHP
     * recompresses on every cache miss, so this stays cheap on purpose.
     */
    private const COMPRESSION_LEVEL = 6;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $body = $this->body($response);

        if ($body === null || strlen($body) < self::MINIMUM_BYTES) {
            return $response;
        }

        $compressed = gzencode($body, self::COMPRESSION_LEVEL);

        // gzencode returns false on failure. Serving the original is always
        // correct, so there is nothing to report and nothing to fail.
        if ($compressed === false || strlen($compressed) >= strlen($body)) {
            return $response;
        }

        return $this->replace($response, $compressed);
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        // `accepts gzip` has to be an explicit offer. A client that sends
        // `identity`, or no header at all, gets the original bytes — handing a
        // gzip member to something that did not ask for it is a broken page.
        if (! str_contains(strtolower((string) $request->headers->get('Accept-Encoding', '')), 'gzip')) {
            return false;
        }

        // Already encoded by something else, or a 304 with no body to encode.
        if ($response->headers->has('Content-Encoding') || $response->isEmpty()) {
            return false;
        }

        // A StreamedResponse produces its body at send() time, so there is
        // nothing to read here and buffering it would defeat the streaming. See
        // the member-data audit middleware for the same constraint.
        if ($response instanceof StreamedResponse) {
            return false;
        }

        return $this->isCompressibleType((string) $response->headers->get('Content-Type', ''));
    }

    /** Text-shaped payloads only. Images, fonts and archives are already compressed. */
    private function isCompressibleType(string $contentType): bool
    {
        // `application/javascript; charset=utf-8` -> `application/javascript`.
        $type = strtolower(trim(explode(';', $contentType)[0]));

        return in_array($type, [
            'application/javascript',
            'text/javascript',
            'text/css',
            'application/json',
        ], true);
    }

    /** The bytes, whether they are in memory or still on disk. */
    private function body(Response $response): ?string
    {
        if ($response instanceof BinaryFileResponse) {
            $path = $response->getFile()->getPathname();

            // A file that has gone away between the response being built and here
            // is not this middleware's problem to report.
            $contents = is_readable($path) ? file_get_contents($path) : false;

            return $contents === false ? null : $contents;
        }

        return $response->getContent() ?: null;
    }

    /**
     * A BinaryFileResponse cannot carry a compressed body — it sends the file off
     * disk — so it is swapped for an ordinary response holding the gzipped bytes.
     * Its headers are kept, which is what preserves Livewire's year-long
     * `Cache-Control` and its `Last-Modified`.
     */
    private function replace(Response $response, string $compressed): Response
    {
        $headers = $response->headers->all();

        $replacement = new Response($compressed, $response->getStatusCode(), []);

        foreach ($headers as $name => $values) {
            // `all()` types its values as `list<string|null>`; a null entry is a
            // header with no value, which there is nothing useful to copy from.
            $values = array_values(array_filter($values, is_string(...)));

            if ($values !== []) {
                $replacement->headers->set($name, $values);
            }
        }

        $replacement->headers->set('Content-Encoding', 'gzip');
        $replacement->headers->set('Content-Length', (string) strlen($compressed));

        // Caches keyed only on the URL must not hand these bytes to a client that
        // cannot decode them. Without this a shared cache can serve the gzipped
        // body to an `identity` request.
        $replacement->headers->set('Vary', 'Accept-Encoding');

        return $replacement;
    }
}
