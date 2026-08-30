# Captured-message inbox

## Sub-features

- `list` - newest captured messages and parsed counts.
- `filter-app` - exact source-app filter.
- `filter-recipient` - partial recipient filter.
- `filter-subject` - partial subject filter.
- `filter-dates` - inclusive received-from/to filters.
- `purge-filtered` - admin-only deletion that refuses an empty scope.

## How to get to it (user POV)

- Use the Inbox sidebar link after login.
- Visit `/inbox` directly; guests are redirected to `/login`.
- Return from a message with the Back to inbox button.
- Admins see Purge filtered scope after entering at least one filter.

## Driving it with Playwright

Preconditions: baseline 1-6. Seed a user, then run
`.claude/skills/verify-sink/harness/send-message.sh --app=verify-source --recipient=recipient@verify.test --subject='Verification message'`.

Status: **recipe, not yet driven**.

- **User sees, filters, restores, and opens a captured message** - run this exact recipe at both
  required viewports:
  ```json
  [
    {"login":{"email":"user@verify.test","password":"verify-password"}},
    {"goto":"/inbox"},
    {"expectRole":{"role":"heading","name":"Inbox"}},
    {"expectRole":{"role":"link","name":"Verification message"}},
    {"shot":"before-filter"},
    {"fillLabel":{"label":"Source app","value":"verify-source"}},
    {"wait":500},
    {"expectRole":{"role":"link","name":"Verification message"}},
    {"fillLabel":{"label":"Subject contains","value":"not-present"}},
    {"wait":500},
    {"expectText":{"selector":"body","contains":"No messages match these filters."}},
    {"fillLabel":{"label":"Subject contains","value":""}},
    {"wait":500},
    {"expectRole":{"role":"link","name":"Verification message"}},
    {"measure":{"selector":"section","name":"inbox-section"}},
    {"overflow":false},
    {"shot":"after-restore"},
    {"clickRole":{"role":"link","name":"Verification message"}},
    {"expectUrl":{"contains":"/inbox/"}},
    {"expectRole":{"role":"heading","name":"Verification message"}}
  ]
  ```
- **Recipient and inclusive date filters**:
  ```json
  [
    {"login":{"email":"user@verify.test","password":"verify-password"}},
    {"goto":"/inbox"},
    {"fillLabel":{"label":"Recipient","value":"recipient@verify.test"}},
    {"wait":500},
    {"expectRole":{"role":"link","name":"Verification message"}},
    {"fillLabel":{"label":"Received from","value":"2020-01-01"}},
    {"fillLabel":{"label":"Received to","value":"2099-12-31"}},
    {"wait":500},
    {"expectRole":{"role":"link","name":"Verification message"}},
    {"fillLabel":{"label":"Recipient","value":"missing@verify.test"}},
    {"wait":500},
    {"expectText":{"selector":"body","contains":"No messages match these filters."}}
  ]
  ```
- **Admin purges only a filtered scope** - seed an admin and a fresh message, then run this destructive
  recipe at one viewport:
  ```json
  [
    {"login":{"email":"admin@verify.test","password":"verify-password"}},
    {"goto":"/inbox"},
    {"fillLabel":{"label":"Subject contains","value":"Verification message"}},
    {"wait":500},
    {"acceptDialog":true},
    {"clickRole":{"role":"button","name":"Purge filtered scope"}},
    {"expectText":{"selector":"body","contains":"Purged 1 messages."}}
  ]
  ```
  Prove the deletion with this exact command, which must report `count` zero:
  ```bash
  .claude/skills/verify-sink/harness/inspect-db.sh --message-subject='Verification message'
  ```

## Gotchas

- `Source app` is exact match; recipient and subject are partial matches.
- Inputs use `wire:model.live`; wait for the Livewire response before reading the table.
- The table itself intentionally has an `overflow-x-auto` wrapper. Page-level horizontal overflow is
  still a failure.
- Purge is admin-only and refuses an unscoped request with 422. Never work around that guard.
- `send-message.sh` proves Postgres and the database queue, but not production managed-queue or object
  storage behavior.
