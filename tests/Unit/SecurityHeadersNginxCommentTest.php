<?php

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/NginxSecurityHeaders.php';

// Exercise both shipped pins against copied templates. Never edit the real
// template, run nginx, boot a deploy, or contact a database or external service.
it('counts only active security headers in both the PHP and shell pins', function (string $replacement, bool $passes) {
    $directive = 'add_header X-Frame-Options "DENY";';
    $files = new Filesystem;
    $contents = $files->get(base_path('nginx.template.conf'));
    expect(substr_count($contents, $directive))->toBe(1);

    $scratch = getenv('PAPERCLIP_RUN_SCRATCH_DIR') ?: sys_get_temp_dir();
    $fixture = $scratch.'/nginx-header-comment-'.bin2hex(random_bytes(8));
    $files->makeDirectory($fixture.'/ci', 0755, true);

    try {
        $files->put($fixture.'/nginx.template.conf', str_replace($directive, $replacement, $contents));
        $files->copy(base_path('ci/php-runtime-config-selftest.sh'), $fixture.'/ci/php-runtime-config-selftest.sh');
        $copied = $files->get($fixture.'/nginx.template.conf');

        if ($passes) {
            assertNginxAppSecurityHeaders($copied);
        } else {
            expect(fn () => assertNginxAppSecurityHeaders($copied))
                ->toThrow(AssertionFailedError::class, 'X-Frame-Options');
        }

        $process = new Process(['bash', $fixture.'/ci/php-runtime-config-selftest.sh']);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())->toBe($passes ? 0 : 1, $output);
        if ($passes) {
            expect($output)->toContain('PASS: nginx emits the docs/dns.md origin security headers');
        } else {
            expect($output)->toContain('FAIL:', 'X-Frame-Options');
        }
    } finally {
        $files->deleteDirectory($fixture);
    }
})->with([
    'pristine' => ['add_header X-Frame-Options "DENY";', true],
    'commented at column zero' => ["\n# add_header X-Frame-Options \"DENY\";", false],
    'indented commented-only header' => ['# add_header X-Frame-Options "DENY";', false],
    'commented copy before active header' => ["# add_header X-Frame-Options \"DENY\";\n        add_header X-Frame-Options \"DENY\";", true],
    'commented copy after active header' => ["add_header X-Frame-Options \"DENY\";\n        # add_header X-Frame-Options \"DENY\";", true],
    'active duplicate still rejected' => ["add_header X-Frame-Options \"DENY\";\n        add_header X-Frame-Options \"DENY\";", false],
    'active header with trailing comment' => ['add_header X-Frame-Options "DENY"; # framing policy', true],
    'comment on another directive is not a header' => ['add_header X-Other "test"; # add_header X-Frame-Options "DENY";', false],
    'always on an active header' => ['add_header X-Frame-Options "DENY" always;', true],
    'duplicate sharing line with another directive' => ["add_header X-Frame-Options \"DENY\";\n        add_header X-Other \"test\"; add_header X-Frame-Options \"DENY\";", false],
    'same-line active duplicate after another directive' => ['add_header X-Frame-Options "DENY"; add_header X-Other "test"; add_header X-Frame-Options "DENY";', false],
    'same-line active duplicate before another directive' => ['add_header X-Frame-Options "DENY"; add_header X-Frame-Options "DENY"; add_header X-Other "test";', false],
    'same-line active duplicate without separating spaces' => ['add_header X-Frame-Options "DENY";add_header X-Frame-Options "DENY";', false],
    'whitespace before terminator' => ['add_header X-Frame-Options "DENY" ;', true],
    'whitespace before always terminator' => ["add_header X-Frame-Options \"DENY\" always\t;", true],
    'tabs and repeated token whitespace' => ["add_header\tX-Frame-Options  \"DENY\"\t;", true],
    'multiline directive' => ["add_header X-Frame-Options\n        \"DENY\"\n        ;", true],
    'quoted directive text is not active' => ['add_header X-Other \'add_header X-Frame-Options "DENY";\';', false],
    'quoted directive text beside active header' => ['add_header X-Frame-Options "DENY"; add_header X-Other \'# add_header X-Frame-Options "DENY";\';', true],
    'escaped quote and comment marker in another value' => ['add_header X-Frame-Options "DENY"; add_header X-Other "escaped \\"; # still inside the value";', true],
    'inline commented duplicate is ignored' => ['add_header X-Frame-Options "DENY"; # add_header X-Frame-Options "DENY";', true],
    'comment between directive tokens' => ["add_header X-Frame-Options # framing policy\n        \"DENY\";", true],
]);
