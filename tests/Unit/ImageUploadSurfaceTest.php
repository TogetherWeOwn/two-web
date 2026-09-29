<?php

use App\Http\Requests\StoreEventRequest;
use App\Livewire\MemberProfile;
use App\Models\Event;
use App\Models\Profile;
use Illuminate\Support\Str;

// TOG-6946: the card asked for proof that event/member image uploads reject a
// renamed .exe/.svg with a 422. The audit found there is nothing to reject
// through: this codebase has no file-upload endpoint at all — no
// WithFileUploads, no FileUpload field, no hasFile/Storage:: handling, no
// <input type=file>, and no route that accepts file bytes. The image surfaces
// are URL strings and Discord CDN values only:
//
// - Event + Profile have no image/cover/file column, fillable, or request
//   rule (pinned below).
// - `users.avatar` is written only from `$discordUser->getAvatar()` at
//   login/join — a `https://cdn.discordapp.com/...` template filled from
//   Discord's own API response, never from member input. (TOG-8440: the PATCH
//   writer that test used to forge through is deleted; the surviving pin is
//   'ignores a forged avatar...' in tests/Feature/Livewire/MemberProfileTest,
//   where a save leaves the user row untouched.)
// - FeaturedContent.image_url is a TextInput->url() string rendered through
//   Blade {{ }} escaping. `Str::isUrl` rejects javascript:/data: schemes
//   (pinned below); it still accepts any https URL including .svg and ftp —
//   that residual policy question is filed as its own card, not closed here.
//
// These tests are fail-closed tripwires, not framework tests: if anyone adds
// an upload surface, the token scan goes red until they add real validation
// (server-side mime sniff, size cap, SVG rejection) plus spoof tests beside it.
// They touch no database (see phpunit.xml: the suite runs on Postgres, which
// this box cannot reach), so they live in tests/Unit and run anywhere.

/** Recursively scan a source tree for any of the given tokens. */
function uploadSurfaceHits(array $dirs, array $tokens): array
{
    $hits = [];

    foreach ($dirs as $dir) {
        $path = base_path($dir);

        if (! is_dir($path)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $file->getPathname());

            foreach (file($file->getPathname()) as $number => $line) {
                foreach ($tokens as $token) {
                    if (str_contains($line, $token)) {
                        $hits[] = $relative.':'.($number + 1).' — '.$token;
                    }
                }
            }
        }
    }

    return $hits;
}

$appCode = ['app', 'config', 'routes', 'resources/views'];

$uploadTokens = [
    'WithFileUploads',
    'FileUpload',
    'hasFile',
    'UploadedFile',
    'putFile',
    'storeAs(',
    'Storage::',
    'TemporaryUploadedFile',
    'temporaryUploadUrl',
    'signedRoute',
    'GenerateSignedUploadUrl',
    'livewire.upload-file',
    'livewire.preview-file',
    'storage.local',
    'livewire-tmp',
    'enctype',
    'multipart',
    "'mimes'",
    '"mimes"',
    'type="file"',
    "type='file'",
];

it('has no file-upload machinery in app code', function () use ($appCode, $uploadTokens) {
    // A renamed .exe/.svg cannot be smuggled through an endpoint that does
    // not exist. If this fails, someone added upload machinery: add
    // server-side mime sniffing (never extension), a size cap, and SVG
    // rejection with spoof tests — then update this scan deliberately.
    expect(uploadSurfaceHits($appCode, $uploadTokens))->toBe([]);
});

it('accepts no image or file input on the profile write path', function () {
    $forbidden = ['avatar', 'image', 'cover', 'file', 'photo', 'picture', 'upload'];

    $ruleKeys = array_keys(MemberProfile::validationRules());

    expect(array_intersect($ruleKeys, $forbidden))->toBe([]);

    expect(array_intersect((new Profile)->getFillable(), $forbidden))->toBe([]);
});

it('accepts no image or file input on the event write path', function () {
    $forbidden = ['avatar', 'image', 'cover', 'file', 'photo', 'picture', 'upload'];

    // TOG-9270: UpdateEventRequest is deleted with PATCH /events/{event} — the
    // Filament panel (EditEvent) is the only event editor, via EventService.
    // StoreEventRequest remains the sole HTTP event writer.
    expect(array_intersect(array_keys((new StoreEventRequest)->rules()), $forbidden))->toBe([]);
    expect(array_intersect((new Event)->getFillable(), $forbidden))->toBe([]);
});

it('keeps avatar Discord-owned: app code never takes it from member input', function () {
    // The only writes build the value from Discord's own API object. If a
    // third writer appears, this pin forces a review of where its value
    // comes from.
    $authCode = file_get_contents(base_path('app/Http/Controllers/JoinController.php'))
        .file_get_contents(base_path('app/Http/Controllers/Auth/DiscordLoginController.php'));

    // Both writes build the value from Discord's own API object
    // ($discordUser->getAvatar()), never from member input.
    expect($authCode)->toContain('getAvatar()');
    expect(substr_count($authCode, "'avatar'"))->toBe(2, 'avatar must have exactly two writers: join and login');
    expect($authCode)->not->toContain("input('avatar'");
    expect($authCode)->not->toContain('request->avatar');
});

it('lets image_url be a URL-shaped string only, rendered escaped', function () {
    // Scheme smuggling (javascript:/data:) is closed by the url rule's
    // Str::isUrl check — the two schemes that would turn stored markup into
    // live script in an <img> or preview context.
    expect(Str::isUrl('javascript:alert(1)'))->toBeFalse();
    expect(Str::isUrl('data:image/svg+xml,<svg></svg>'))->toBeFalse();

    // The residual gap, pinned not closed: any https URL passes, including a
    // hostile .svg or a tracking pixel. Tracked in [TOG-7473](/TOG/issues/TOG-7473);
    // if that card lands an allowlist, extend this test to pin it.
    expect(Str::isUrl('https://evil.example/x.svg'))->toBeTrue();

    // Rendering stays escaped: no raw-echo of either image variable anywhere
    // in the views, and the home-page <img> goes through {{ }}.
    foreach (['resources/views/home.blade.php', 'resources/views/livewire/member-profile.blade.php'] as $view) {
        expect(file_get_contents(base_path($view)))->not->toContain('{!!', "{$view} must never raw-echo");
    }

    expect(file_get_contents(base_path('resources/views/home.blade.php')))
        ->toContain('<img src="{{ $item->image_url }}"');
});
