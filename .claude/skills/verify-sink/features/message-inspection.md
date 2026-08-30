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

Preconditions: baseline 1-6. Create `Verification message` with `harness/send-message.sh` and seed a
login actor.

Status: **recipe, not yet driven**.

- **Inspect a basic real-ingest message** - login, `goto` `/inbox`, click the link
  `Verification message`, expect headings `Recipients`, `Body`, `Headers`, `Links`, and `Attachments`,
  expect text `recipient@verify.test`, and expect text `https://example.test/verify`.
- **Prove body isolation and content** - `expectAttribute` on
  `iframe[title="Sandboxed message body"]` with attribute `sandbox` equal to an empty string, then
  `expectFrameText` containing `Captured by the isolated Sink verifier.`. Observable result: the body
  is visible inside the sandbox but the outer page exposes only metadata.
- **Prove raw entry point** - expect `a[href*="/raw"]` to be visible and have a path under the current
  message. Open it in a separate Playwright page and expect the MIME headers and body source.
- **Prove database and storage side effects** - save
  `harness/inspect-db.sh --message-subject='Verification message'`; its message has a non-null
  `parsed_at`, recipient, one link, and a `raw_object_key` under this run's storage directory.
- **Layout** - screenshot before and after opening the detail, `measure` the body iframe, and run
  `overflow` at both required viewports.

## Gotchas

- The basic `send-message.sh` fixture has one recipient and one link but no attachment. Attachment
  download remains unproved until a real multipart MIME message is ingested.
- Body content intentionally does not live in searchable database columns. Read it through the iframe
  route or raw object storage, not `inspect-db.sh`.
- A sandbox attribute with no value is deliberate. Any `allow-scripts` token is a security regression.
- The raw-source link opens a new tab; a same-page URL assertion will wait forever.
- Delete is admin-only and removes both metadata and stored blobs. Use it only in the disposable run.
