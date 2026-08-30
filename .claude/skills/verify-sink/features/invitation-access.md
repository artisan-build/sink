# Invitation-only access

## Sub-features

- `login-logout` - existing users enter at `/login` and leave through Log out.
- `admin-invite` - an admin creates a seven-day invitation and receives its link.
- `accept-invite` - the recipient creates a non-admin account from that link.
- `invalid-invite` - unknown, expired, and accepted links expose no signup form.
- `open-registration-closed` - there is no open `/register` route.

## How to get to it (user POV)

- Visit `/login` directly, follow any auth redirect, or use Log in from `/`.
- As an admin, use the Invitations sidebar link or visit `/admin/invitations` directly.
- Open the generated `/register/{token}` invitation link.
- Visit `/register/{unknown-token}` to see the invalid/expired state.
- Log out from the desktop user menu or mobile profile menu.

## Driving it with Playwright

Preconditions: baseline 1-4. Seed `admin@verify.test` with password `verify-password` and `--admin`.

Status: **driven 2026-08-30** for direct `/admin/invitations` creation at `1280x800` and `390x844`.
The sidebar entry, invitation acceptance, invalid token, and logout remain recipes.

- **Admin creates invitations from the direct route** - write this step file and run both viewports:
  ```json
  [
    {"login":{"email":"admin@verify.test","password":"verify-password"}},
    {"goto":"/admin/invitations"},
    {"expectText":{"selector":"body","contains":"Invite humans into this Sink instance."}},
    {"shot":"before-create"},
    {"fillLabel":{"label":"Email address","value":"verify+{{viewport}}@example.test"}},
    {"clickRole":{"role":"button","name":"Create invitation"}},
    {"expectText":{"selector":"body","contains":"verify+{{viewport}}@example.test"}},
    {"expectText":{"selector":"body","contains":"Pending"}},
    {"measure":{"selector":"section","name":"invitations-section"}},
    {"overflow":false},
    {"shot":"after-create"}
  ]
  ```
  Observable result: each viewport shows its pending invitation and an invitation link. Persist the
  database side effect with `harness/inspect-db.sh --invitation-prefix='verify+'`.
- **Admin enters from navigation** - after `login`, `clickRole` the `link` named `Invitations`, then
  `expectUrl` containing `/admin/invitations` and the Invitations heading. On mobile, first click the
  button whose accessible label is `Toggle sidebar`.
- **Recipient accepts** - extract the read-only invitation URL from the successful callout with
  `expectValue`/`inputValue`, open it in a fresh context, fill `Name`, `Password`, and
  `Confirm password`, click the `Create account` button, and expect `/dashboard`. The `users` row must
  exist as non-admin and the invitation's `accepted_at` must be non-null.
- **Invalid link stays closed** - `goto` `/register/unknown-token`, expect heading
  `Invitation invalid or expired`, and `expectMissing` for `form`.
- **Open registration is absent** - `goto` `/register`, then `expectStatus` `404`.

## Gotchas

- The invitation token is stored hashed. Database evidence may show status and timestamps, never the
  plaintext link or token.
- The callout's read-only input has no label or `data-test`; scope it to the callout when extracting a
  link instead of selecting the first input on the page.
- Running the two viewports repeats the workflow. Use `{{viewport}}` in the email to avoid ambiguity.
- Non-admin `/admin/invitations` is 403; guests are redirected to `/login`.
- Login throttles at five attempts per minute for each email/IP combination.
