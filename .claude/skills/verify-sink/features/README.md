# Sink feature map

Read the relevant feature file before driving it. Every observed user entry point is listed; a run
that covers one entry point must not claim coverage for the others.

## Index

| Feature | File | Local reach |
| --- | --- | --- |
| Invitation-only access | [invitation-access.md](invitation-access.md) | Fully local; admin creation and invited acceptance |
| Inbox | [inbox.md](inbox.md) | Fully local with real HTTP ingest and database queue |
| Message inspection | [message-inspection.md](message-inspection.md) | Basic HTML message local; attachment recipe requires attachment MIME |

`driven YYYY-MM-DD` means the named entry point was actually exercised on a real disposable instance.
Anything else is a recipe, not proof.

## Baseline preconditions

1. `.claude/skills/verify-sink/harness/install-browser.sh` has installed Playwright outside the repository.
2. `.claude/skills/verify-sink/harness/launch.sh` ended with `instance is worth driving`.
3. `BASE_URL` is `http://127.0.0.1:<run-port>`, never a Herd `.test` hostname.
4. The default and named `sink` connections both identify this run's PostgreSQL database.
5. Managed authentication has completed through the disposable authority; `seed-actor.sh` is only for standalone fixture setup.
6. `.claude/skills/verify-sink/harness/send-message.sh` has created a captured message when the feature needs inbox state.

## Driving conventions

- Prefer accessible labels and roles from the rendered Flux controls. Use the few existing
  `[data-test=...]` selectors only where they exist.
- Run every layout-sensitive recipe at `1280x800` and `390x844`, with `overflow` and screenshots.
- Strings containing `{{viewport}}` are made unique per viewport by `drive.cjs`.
- Enter through `/bfc/managed/login`; the harness drives the real handoff, exchange, and session boundary.
- `wire:model.live` filters need a short `wait` before asserting the changed table.
- A seeded actor or helper-ingested message is precondition state, not proof of a UI feature.
- Read side effects from PostgreSQL with `.claude/skills/verify-sink/harness/inspect-db.sh` and keep the JSON under `evidence/`.
- The run uses private MinIO and local Redis rather than Cloud-managed implementations; name that caveat.

## Unmapped surfaces

- `/dashboard` is currently starter-kit placeholder content and has no meaningful Sink workflow.
- The MCP and `/ingest` API surfaces are not browser features. `send-message.sh` uses `/ingest` as a
  real precondition path; MCP verification belongs to its API/tool contract tests.
- Password reset is available but is not in the initial four maps.
