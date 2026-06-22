# Sink

Self-hosted, unmetered staging/test mail capture for Laravel, on Laravel Cloud. A Laravel `sink`
mail transport captures every outbound message to a self-hosted inbox — viewable by humans in a web
UI, queryable (body-blind) by a coding agent over MCP. Capture-only; nothing is delivered.

**This repo is a monorepo** (being built out): a slim Sink app shell at the root wiring
`sink-server`, plus three split packages under `packages/`:
`sink-contracts` (the versioned wire envelope), `sink-client` (the mail transport), and
`sink-server` (ingest, parse, storage, prune, the inbox UI, the MCP server). It mirrors the
conventions of [`artisan-build/hone`](https://github.com/artisan-build/hone) and
[`artisan-build/matte`](https://github.com/artisan-build/matte) — read their READMEs.

## Build spec

The authoritative build handover is `docs/sink-build-handover.md` — locked decisions, architecture,
data model, MCP tool catalogue, and the sequenced build order. Read it first.

## Workflow

Feature builds: see `.solo/workflow.md` and the `multi-agent-build` skill. The starter base is the
`artisan-build/laravel-nodeless` kit (Laravel + Livewire 4 + Flux 2 + Fortify, no Node build).
