<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * `POST /csp-reports` — the session-free CSP violation sink (TOG-8403).
 *
 * Lives in routes/funnel.php with an empty middleware stack, never in `web`:
 * the browser fires this from pages whose session may already be gone, and —
 * like `/discord` — it answers when the app database is down (a report is
 * logged, never stored). No session, no CSRF, no throttle (the route reads no
 * cache, and the edge already rate-limits), no auth.
 *
 * Accepts the CSP `report-uri` JSON shape (`{"csp-report": {...}}`) and the
 * newer `Reporting API` shape (`[{...}]`), logs a sampled subset at warning
 * level with a fixed key set (never the raw body, which is attacker-shaped),
 * and always answers 204 — even for malformed bodies. A 4xx would make the
 * browser retry; a report endpoint that retries is a flood amplifier. Bodies
 * over 8 KB are dropped before parsing, so the sink cannot be used as a
 * log-spam cannon. There is no `throttle` middleware: like `/discord`, this
 * route must answer during an app-DB outage, and `throttle` reads the cache
 * store — which is the database everywhere shipped.
 */
class CspReportController
{
    /** Largest report body accepted, in bytes. Bigger bodies are dropped. */
    public const MAX_BODY_BYTES = 8192;

    public function __invoke(Request $request): Response
    {
        $raw = (string) $request->getContent();

        if (strlen($raw) > self::MAX_BODY_BYTES) {
            Log::warning('csp.report.dropped_oversize', ['bytes' => strlen($raw)]);

            return response('', 204);
        }

        $report = $this->extractReport($raw);

        if ($report === null) {
            return response('', 204);
        }

        if ($this->sampled()) {
            Log::warning('csp.report.violation', [
                'blocked_uri' => $report['blocked-uri'] ?? $report['blockedURL'] ?? null,
                'violated_directive' => $report['violated-directive'] ?? $report['effectiveDirective'] ?? null,
                'document_uri' => $report['document-uri'] ?? $report['url'] ?? null,
                'source_file' => $report['source-file'] ?? null,
                'line_number' => $report['line-number'] ?? null,
            ]);
        }

        return response('', 204);
    }

    /**
     * Pull the violation object out of either report shape.
     * Returns null when the body is not a recognisable report.
     *
     * @return array<string, mixed>|null
     */
    private function extractReport(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, depth: 4, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        // Classic `report-uri` shape: {"csp-report": {...}}.
        if (isset($decoded['csp-report']) && is_array($decoded['csp-report'])) {
            return $decoded['csp-report'];
        }

        // Reporting API shape: [{...}, ...] — take the first report body.
        if (array_is_list($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
            $first = $decoded[0];

            return is_array($first['body'] ?? null) ? $first['body'] : $first;
        }

        return null;
    }

    private function sampled(): bool
    {
        $rate = (float) config('csp.report_sample_rate', 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return random_int(1, 1_000_000) / 1_000_000 <= $rate;
    }
}
