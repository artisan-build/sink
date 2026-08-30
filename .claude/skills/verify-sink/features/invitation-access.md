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

- **Admin creates invitations from the direct route** - write this exact step file and run both viewports:
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
    {"captureValue":{"selector":"input[readonly][value*='/register/']","name":"invitationUrl"}},
    {"measure":{"selector":"section","name":"invitations-section"}},
    {"overflow":false},
    {"shot":"after-create"}
  ]
  ```
  Observable result: each viewport shows its pending invitation and an invitation link. Persist the
  database side effect with this exact command:
  ```bash
  .claude/skills/verify-sink/harness/inspect-db.sh --invitation-prefix='verify+'
  ```
- **Admin enters from navigation** - use the desktop steps below. For the mobile drive, insert the
  `Toggle sidebar` click before the `Invitations` click:
  ```json
  [
    {"login":{"email":"admin@verify.test","password":"verify-password"}},
    {"clickRole":{"role":"link","name":"Invitations"}},
    {"expectUrl":{"contains":"/admin/invitations"}},
    {"expectRole":{"role":"heading","name":"Invitations"}}
  ]
  ```
  ```json
  [
    {"login":{"email":"admin@verify.test","password":"verify-password"}},
    {"clickRole":{"role":"button","name":"Toggle sidebar"}},
    {"clickRole":{"role":"link","name":"Invitations"}},
    {"expectUrl":{"contains":"/admin/invitations"}},
    {"expectRole":{"role":"heading","name":"Invitations"}}
  ]
  ```
- **Recipient accepts** - this recipe creates the invitation, captures the callout value without
  logging it, discards the authenticated context, and opens the captured URL in a fresh context:
  ```json
  [
    {"login":{"email":"admin@verify.test","password":"verify-password"}},
    {"goto":"/admin/invitations"},
    {"fillLabel":{"label":"Email address","value":"accept+{{viewport}}@example.test"}},
    {"clickRole":{"role":"button","name":"Create invitation"}},
    {"captureValue":{"selector":"input[readonly][value*='/register/']","name":"invitationUrl"}},
    {"shot":"invitation-created"},
    {"newContext":true},
    {"goto":"{{invitationUrl}}"},
    {"expectRole":{"role":"heading","name":"Accept your invitation"}},
    {"fillLabel":{"label":"Name","value":"Invited {{viewport}}"}},
    {"fillLabel":{"label":"Password","value":"accepted-password"}},
    {"fillLabel":{"label":"Confirm password","value":"accepted-password"}},
    {"shot":"before-accept"},
    {"clickRole":{"role":"button","name":"Create account"}},
    {"expectUrl":{"contains":"/dashboard"}}
  ]
  ```
  Persist the non-admin user and accepted invitation states with:
  ```bash
  .claude/skills/verify-sink/harness/inspect-db.sh --user-email='accept+1280x800@example.test'
  .claude/skills/verify-sink/harness/inspect-db.sh --user-email='accept+390x844@example.test'
  .claude/skills/verify-sink/harness/inspect-db.sh --invitation-prefix='accept+'
  ```
- **Invalid link stays closed and open registration is absent**:
  ```json
  [
    {"goto":"/register/unknown-token"},
    {"expectRole":{"role":"heading","name":"Invitation invalid or expired"}},
    {"expectMissing":"form"},
    {"goto":"/register"},
    {"expectStatus":404}
  ]
  ```
- **Login and logout** - use direct, stable attributes for both menus. Desktop:
  ```json
  [
    {"login":{"email":"admin@verify.test","password":"verify-password"}},
    {"click":"[data-test='sidebar-menu-button']"},
    {"click":"[data-test='logout-button']"},
    {"expectUrl":{"contains":"/"}},
    {"goto":"/admin/invitations"},
    {"expectUrl":{"contains":"/login"}}
  ]
  ```
  Mobile:
  ```json
  [
    {"login":{"email":"admin@verify.test","password":"verify-password"}},
    {"click":"header [data-flux-profile]"},
    {"click":"[data-test='logout-button']"},
    {"goto":"/admin/invitations"},
    {"expectUrl":{"contains":"/login"}}
  ]
  ```

## Gotchas

- The invitation token is stored hashed. Database evidence may show status and timestamps, never the
  plaintext link or token.
- The callout's read-only input has no label or `data-test`; select only
  `input[readonly][value*='/register/']`. `captureValue` treats the result as secret, and every normal
  or failure screenshot masks the invitation URL and token before writing the PNG.
- Running the two viewports repeats the workflow. Use `{{viewport}}` in the email to avoid ambiguity.
- Non-admin `/admin/invitations` is 403; guests are redirected to `/login`.
- Login throttles at five attempts per minute for each email/IP combination.
