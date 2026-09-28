<!--
Title: Conventional Commits header, e.g. `fix(auth): refuse expired sudo sessions`.
Types: feat fix perf refactor test docs build ci chore revert style security. Max 100 chars.
Keep the card ID out of the title; put it on the Refs line below.
-->

## Summary

<!-- What changed and why, in 1-3 sentences. -->

## Changes

-

## Testing

<!-- Commands you ran and their results, or why no test applies. -->

## Checklist

- [ ] `composer check` is green (lint + analyse + test)
- [ ] No secrets, real `.env`, `vendor/` or `node_modules/` in the diff
- [ ] Changes to `app/Http/Resources/EventResource.php` update
      `tests/Feature/Events/EventJsonContractTest.php` — the events JSON shape is
      the calendar and bot-mirror contract, and the test fails on any unannounced
      rename, removal or reorder

Refs: TOG-
