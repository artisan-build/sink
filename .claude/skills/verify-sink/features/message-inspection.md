# Message inspection

## Sub-features

- `metadata` - app, IDs, dates, size, truncation, attachment/link counts.
- `recipients` - to/cc/bcc groups.
- `sandboxed-body` - rendered body in a scriptless sandboxed iframe.
- `raw-source` - raw MIME in a new tab.
- `headers-links` - parsed headers and normalized links.
- `attachments` - scoped downloads with sanitized filenames.
- `delete-message` - admin-only row and blob deletion.

## How to get to it (user POV)

- Click a subject in `/inbox`.
- Visit `/inbox/{message}` directly while authenticated.
- Use Back to inbox to return to the list.
- Use View raw source from the Body card.
- Use Download beside an attachment.
- Admins can use Delete message.

## Driving it with Playwright

Preconditions: baseline 1-6. Create `Verification message` with
`.claude/skills/verify-sink/harness/send-message.sh` and seed a login actor.

Status: **recipe, not yet driven**.

- **Inspect a basic real-ingest message, its sandboxed body, and the Back to inbox entry point** - run
  this exact recipe at both required viewports:
  ```json
  [
    {"login":{"email":"user@verify.test","password":"verify-password"}},
    {"goto":"/inbox"},
    {"shot":"before-detail"},
    {"clickRole":{"role":"link","name":"Verification message"}},
    {"expectRole":{"role":"heading","name":"Verification message"}},
    {"expectRole":{"role":"heading","name":"Recipients"}},
    {"expectRole":{"role":"heading","name":"Body"}},
    {"expectRole":{"role":"heading","name":"Headers"}},
    {"expectRole":{"role":"heading","name":"Links"}},
    {"expectRole":{"role":"heading","name":"Attachments"}},
    {"expectText":{"selector":"body","contains":"recipient@verify.test"}},
    {"expectText":{"selector":"body","contains":"https://example.test/verify"}},
    {"expectAttribute":{"selector":"iframe[title='Sandboxed message body']","name":"sandbox","equals":""}},
    {"expectFrameText":{"selector":"iframe[title='Sandboxed message body']","contains":"Captured by the isolated Sink verifier."}},
    {"measure":{"selector":"iframe[title='Sandboxed message body']","name":"message-body"}},
    {"overflow":false},
    {"shot":"message-detail"},
    {"clickRole":{"role":"link","name":"Back to inbox"}},
    {"expectUrl":{"contains":"/inbox"}},
    {"expectRole":{"role":"heading","name":"Inbox"}}
  ]
  ```
- **Open raw source in its real new-tab entry point** - `clickNewPage` waits for the popup and switches
  the active page before assertions:
  ```json
  [
    {"login":{"email":"user@verify.test","password":"verify-password"}},
    {"goto":"/inbox"},
    {"clickRole":{"role":"link","name":"Verification message"}},
    {"expect":{"selector":"a[href*='/raw']"}},
    {"clickNewPage":{"role":"link","name":"View raw source"}},
    {"expectUrl":{"contains":"/raw"}},
    {"expectText":{"selector":"body","contains":"Subject: Verification message"}},
    {"expectText":{"selector":"body","contains":"Captured by the isolated Sink verifier."}}
  ]
  ```
- **Prove database and storage side effects** - save this exact command's JSON under `evidence/`:
  ```bash
  .claude/skills/verify-sink/harness/inspect-db.sh --message-subject='Verification message'
  ```
  The matching row must have non-null `parsed_at`, the expected recipient, `link_count` one, a
  `raw_object_key`, and `raw_object_exists` true.
- **Delete message as an admin** - run at one viewport because the operation destroys the fixture:
  ```json
  [
    {"login":{"email":"admin@verify.test","password":"verify-password"}},
    {"goto":"/inbox"},
    {"clickRole":{"role":"link","name":"Verification message"}},
    {"acceptDialog":true},
    {"clickRole":{"role":"button","name":"Delete message"}},
    {"expectUrl":{"contains":"/inbox"}},
    {"expectText":{"selector":"body","contains":"Message deleted."}}
  ]
  ```
  Then run the database command above and require `count` zero.
- **Download attachment** - the documented `send-message.sh` fixture contains no attachment and the
  harness has no attachment-fixture option. Report this entry point `verifier-blocked` rather than
  inventing an undocumented MIME setup or claiming the visible control proves download behavior.

## Gotchas

- The basic `send-message.sh` fixture has one recipient and one link but no attachment. Attachment
  download remains unproved until a real multipart MIME message is ingested.
- Body content intentionally does not live in searchable database columns. Read it through the iframe
  route or raw object storage, not `inspect-db.sh`.
- A sandbox attribute with no value is deliberate. Any `allow-scripts` token is a security regression.
- The raw-source link opens a new tab; a same-page URL assertion will wait forever.
- Delete is admin-only and removes both metadata and stored blobs. Use it only in the disposable run.
