<?php

use App\Http\Middleware\AddSecurityHeaders;

// Match a whole active directive, not a substring in a disabled line or prose.
// Values stay literal: Permissions-Policy contains regex metacharacters.
function countActiveNginxDirective(string $contents, string $directive): int
{
    $count = preg_match_all('~^[\t ]*'.preg_quote($directive, '~').'[\t ]*(?:#[^\r\n]*)?\r?$~m', $contents);
    if ($count === false) {
        throw new RuntimeException('Cannot count active nginx directives: '.preg_last_error_msg());
    }

    return $count;
}

function assertNginxAppSecurityHeaders(string $contents): void
{
    foreach (AddSecurityHeaders::HEADERS as $header => $value) {
        $directive = 'add_header '.$header.' "'.$value.'"';
        $count = countActiveNginxDirective($contents, $directive.';')
            + countActiveNginxDirective($contents, $directive.' always;');

        expect($count)->toBe(1, "nginx.template.conf must carry {$header}: \"{$value}\" exactly once — the edge and app copies drifted.");
    }
}
