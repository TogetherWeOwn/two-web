<?php

/*
 | Scoped machine ingress for agent-originated events (TOG-5510/web, Gate 2).
 |
 | One endpoint, one caller, one guild. `POST /api/agent-events` authenticates
 | an opaque bearer credential against the `agent_event_grants` table and runs
 | exactly five typed operations — create, read, update, publish, cancel — on
 | events owned by that grant. Everything else about the request is denied
 | before any domain mutation, queueing or signing.
 |
 | `enabled` is the production kill-switch and defaults to off: production must
 | never load or accept a staging grant, so a staging database dump restored
 | somewhere unconfigured still answers 404. DevOps enables this on staging
 | only, after merge, through the legitimate provisioning owner.
 |
 | Guild ids are not secrets. The live guild is already a default in
 | config/services.php for exactly that reason; the staging guild is the
 | admitted audience from the Gate 2 scope and the production one is listed
 | here so the denial can name the guild it refused rather than compare
 | against a constant buried in the service.
 */

return [
    'enabled' => (bool) env('AGENT_EVENTS_ENABLED', false),

    'staging_guild_id' => env('AGENT_EVENTS_GUILD_ID') ?: '1545644954272137297',

    'production_guild_id' => env('AGENT_EVENTS_PRODUCTION_GUILD_ID') ?: '326474832151838730',

    // The one admitted caller. Never accepted from the request: the credential
    // alone identifies the grant, and the grant carries this value for audit.
    'caller_agent_id' => env('AGENT_EVENTS_CALLER_AGENT_ID') ?: 'c1f22b2f-d85f-41e1-9c16-9ca24ac06a11',

    // Gate 2 limits: 10 mutating and 30 reads per minute per grant, with a
    // service-level ceiling so one grant cannot spend the whole budget.
    'mutating_per_minute' => (int) env('AGENT_EVENTS_MUTATING_PER_MINUTE', 10),
    'reads_per_minute' => (int) env('AGENT_EVENTS_READS_PER_MINUTE', 30),
    'service_mutating_per_minute' => (int) env('AGENT_EVENTS_SERVICE_MUTATING_PER_MINUTE', 60),
    'service_reads_per_minute' => (int) env('AGENT_EVENTS_SERVICE_READS_PER_MINUTE', 300),

    // The outer route shield (TOG-8402): every hit per credential per minute,
    // counted before auth, the grant lookup and the audit write. Sits above
    // the inner budgets' sum on purpose — a flood guard, not the allowance —
    // so the bot's normal burst never sees it.
    'route_per_minute' => (int) env('AGENT_EVENTS_ROUTE_PER_MINUTE', 60),
];
