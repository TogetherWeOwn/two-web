<?php

$calls = file_get_contents($argv[1] ?? 'storage/logs/dusk-stub-calls.jsonl');

foreach (['/oauth2/authorize', '/oauth2/token', '/users/@me', '/internal/actions'] as $path) {
    if (! str_contains((string) $calls, '"pathname":"'.$path.'"')) {
        fwrite(STDERR, "Expected Dusk call {$path} was absent.\n");

        exit(1);
    }
}

$receiptPath = $argv[2] ?? 'storage/logs/dusk-bot-receipt.json';
$receipt = json_decode((string) file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);

if (($receipt['body']['action'] ?? null) !== 'event.upsert') {
    fwrite(STDERR, "Expected event.upsert receipt was absent.\n");

    exit(1);
}

fwrite(STDOUT, "OAuth and bot receipts present.\n");
