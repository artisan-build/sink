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
`harness/send-message.sh --app=verify-source --recipient=recipient@verify.test --subject='Verification message'`.

Status: **recipe, not yet driven**.

- **User sees and filters a captured message** - login, `goto` `/inbox`, expect the `Inbox` heading
  and `Verification message`, take a before screenshot, `fillLabel` `Source app` with
  `verify-source`, wait 500 ms, expect the subject, fill `Subject contains` with `not-present`, wait,
  expect text `No messages match these filters.`, clear the subject, then screenshot the restored row.
  Observable result: Livewire narrows and restores the real parsed message without a full-page error.
- **Recipient and date filters** - fill `Recipient`, `Received from`, and `Received to` by their exact
  labels, wait after each change, and assert the known subject appears or the empty-state text appears.
- **Message entry point** - `clickRole` the `link` named `Verification message`, then `expectUrl`
  containing `/inbox/` and the matching heading.
- **Admin purges only a filtered scope** - set `Subject contains`, use `acceptDialog`, click the button
  `Purge filtered scope`, and expect status text describing the deletion. Then
  `harness/inspect-db.sh --message-subject='Verification message'` must report count zero.
- **Layout** - `measure` the first `section`, run `overflow`, and capture full-page screenshots at both
  required viewports.

## Gotchas

- `Source app` is exact match; recipient and subject are partial matches.
- Inputs use `wire:model.live`; wait for the Livewire response before reading the table.
- The table itself intentionally has an `overflow-x-auto` wrapper. Page-level horizontal overflow is
  still a failure.
- Purge is admin-only and refuses an unscoped request with 422. Never work around that guard.
- `send-message.sh` proves Postgres and the database queue, but not production managed-queue or object
  storage behavior.
