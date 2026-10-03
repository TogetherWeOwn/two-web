<?php

use App\Http\Middleware\AddSecurityHeaders;

// Split statements outside quotes/comments, not lines: nginx allows multiple
// directives per line. Collapse token whitespace but keep quoted values literal.
/** @return list<string> */
function activeNginxStatements(string $contents): array
{
    $statements = [];
    $statement = '';
    $quote = '';
    $escaped = false;
    $comment = false;
    $tokenBoundary = true;

    for ($i = 0, $length = strlen($contents); $i < $length; $i++) {
        $char = $contents[$i];
        if ($comment) {
            if ($char === "\n") {
                $comment = false;
                if ($statement !== '' && ! str_ends_with($statement, ' ')) {
                    $statement .= ' ';
                }
            }

            continue;
        }
        if ($escaped) {
            $statement .= $char;
            $escaped = false;
            $tokenBoundary = false;
        } elseif ($char === '\\') {
            $statement .= $char;
            $escaped = true;
            $tokenBoundary = false;
        } elseif ($quote !== '') {
            $statement .= $char;
            if ($char === $quote) {
                $quote = '';
            }
        } elseif ($char === '#' && $tokenBoundary) {
            // nginx starts comments only at token boundaries; foo#bar is literal.
            $comment = true;
        } elseif (($char === '"' || $char === "'") && $tokenBoundary) {
            $quote = $char;
            $statement .= $char;
            $tokenBoundary = false;
        } elseif ($char === ';' || $char === '{' || $char === '}') {
            if ($char === ';') {
                $statements[] = trim($statement);
            }
            $statement = '';
            $tokenBoundary = true;
        } elseif (str_contains(" \t\r\n", $char)) {
            if ($statement !== '' && ! str_ends_with($statement, ' ')) {
                $statement .= ' ';
            }
            $tokenBoundary = true;
        } else {
            $statement .= $char;
            $tokenBoundary = false;
        }
    }

    return $statements;
}

function countActiveNginxDirective(string $contents, string $directive): int
{
    $expected = activeNginxStatements($directive)[0]
        ?? throw new RuntimeException('Expected a terminated nginx directive.');

    return count(array_keys(activeNginxStatements($contents), $expected, true));
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
