# Sink feature map

Read the relevant feature file before driving it. Every observed user entry point is listed; a run
that covers one entry point must not claim coverage for the others.

## Index

| Feature | File | Local reach |
| --- | --- | --- |
| Invitation-only access | [invitation-access.md](invitation-access.md) | Fully local; admin creation and invited acceptance |
| Inbox | [inbox.md](inbox.md) | Fully local with real HTTP ingest and database queue |
| Message inspection | [message-inspection.md](message-inspection.md) | Basic HTML message local; attachment recipe requires attachment MIME |
| Account settings | [account-settings.md](account-settings.md) | Profile/appearance/password/2FA local; passkeys need a virtual authenticator |

`driven YYYY-MM-DD` means the named entry point was actually exercised on a real disposable instance.
Anything else is a recipe, not proof.

## Baseline preconditions

1. `harness/install-browser.sh` has installed Playwright outside the repository.
2. `harness/launch.sh` ended with `instance is worth driving`.
3. `BASE_URL` is `http://127.0.0.1:<run-port>`, never a Herd `.test` hostname.
4. The default and named `sink` connections both identify this run's PostgreSQL database.
5. `harness/seed-actor.sh` has created the actor a recipe names, unless account creation is the feature.
6. `harness/send-message.sh` has created a captured message when the feature needs inbox state.

## Driving conventions

- Prefer accessible labels and roles from the rendered Flux controls. Use the few existing
  `[data-test=...]` selectors only where they exist.
- Run every layout-sensitive recipe at `1280x800` and `390x844`, with `overflow` and screenshots.
- Strings containing `{{viewport}}` are made unique per viewport by `drive.cjs`.
- Login is throttled at five attempts per minute per email/IP. A relaunch resets the disposable
  database-backed limiter state; repeated drives may otherwise receive 429.
- `wire:model.live` filters need a short `wait` before asserting the changed table.
- A seeded actor or helper-ingested message is precondition state, not proof of a UI feature.
- Read side effects from PostgreSQL with `harness/inspect-db.sh` and keep the JSON under `evidence/`.
- The run uses local disk and database queue, not production object storage and managed queue. Name
  that caveat in every result that depends on message parsing or blobs.

## Unmapped surfaces

- `/dashboard` is currently starter-kit placeholder content and has no meaningful Sink workflow.
- The MCP and `/ingest` API surfaces are not browser features. `send-message.sh` uses `/ingest` as a
  real precondition path; MCP verification belongs to its API/tool contract tests.
- Password reset is available but is not in the initial four maps.
