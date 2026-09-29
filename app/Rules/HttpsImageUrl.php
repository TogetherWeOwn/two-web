<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * TOG-7473: FeaturedContent.image_url is a moderator-typed string rendered
 * into `<img src="...">` for every landing-page visitor. Filament's `url()`
 * rule (Laravel `Str::isUrl`) accepts hundreds of schemes — `ftp://` passes —
 * and any `https://` host, including a hostile `.svg`, a tracking pixel, or
 * a multi-megabyte file. Only `javascript:`/`data:` shapes are rejected.
 *
 * This rule tightens the residual gap without a network fetch (no SSRF
 * surface, no publish-time latency): https only, no userinfo, and a raster
 * still-image extension on the path. SVG is deliberately excluded — it is
 * XML, not a still photo, and the most plausible stored-content vector here.
 * Empty values pass: the field is nullable, and `required` stays the form's
 * decision. Length stays the form's `maxLength(255)` decision.
 */
class HttpsImageUrl implements ValidationRule
{
    /** Still-photo extensions we serve. SVG and extensionless URLs are rejected. */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $value = trim($value);

        $parts = parse_url($value);

        // Schemes are case-insensitive on the wire (`HTTPS://` fetches fine),
        // so compare folded — otherwise we reject URLs browsers accept.
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https') {
            $fail('The :attribute field must be an https URL.');

            return;
        }

        if (empty($parts['host'])) {
            $fail('The :attribute field must be an https URL.');

            return;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $fail('The :attribute field must not contain credentials.');

            return;
        }

        $path = $parts['path'] ?? '';

        // A trailing slash (or no path at all) is a directory/redirect, not a
        // file: pathinfo() strips it and reports the parent's extension, so a
        // hostile host could serve sniffed HTML/SVG at `photo.jpg/`.
        if ($path === '' || str_ends_with($path, '/')) {
            $fail('The :attribute field must end in a still-image file ('.implode(', ', self::ALLOWED_EXTENSIONS).').');

            return;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $fail('The :attribute field must end in a still-image file ('.implode(', ', self::ALLOWED_EXTENSIONS).').');

            return;
        }
    }
}
