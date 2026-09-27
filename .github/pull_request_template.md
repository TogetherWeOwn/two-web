## What

## Checklist

- [ ] `composer check` is green (lint + analyse + test)
- [ ] No secrets, real `.env`, `vendor/` or `node_modules/` in the diff
- [ ] Changes to `app/Http/Resources/EventResource.php` update
      `tests/Feature/Events/EventJsonContractTest.php` — the events JSON shape is
      the calendar and bot-mirror contract, and the test fails on any unannounced
      rename, removal or reorder
