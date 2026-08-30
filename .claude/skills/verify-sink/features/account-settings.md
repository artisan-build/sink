# Account settings

## Sub-features

- `profile` - update name and email.
- `appearance` - choose light, dark, or system locally.
- `password` - update a password after password confirmation.
- `two-factor` - enable, confirm, recover, and disable TOTP.
- `passkeys` - register and remove WebAuthn credentials.
- `delete-account` - confirm and remove the current account when enabled.

## How to get to it (user POV)

- Open the desktop user menu or mobile profile menu and choose Settings.
- Visit `/settings`, which redirects to `/settings/profile`.
- Use the Profile, Appearance, and Security links in the settings navigation.
- Visit `/settings/security`; an unconfirmed session is redirected to `/user/confirm-password`.

## Driving it with Playwright

Preconditions: baseline 1-5. For two-viewport writes, seed both actors without `--admin`:

```bash
.claude/skills/verify-sink/harness/seed-actor.sh --email='user+1280x800@verify.test' --password='verify-password' --name='Desktop User'
.claude/skills/verify-sink/harness/seed-actor.sh --email='user+390x844@verify.test' --password='verify-password' --name='Mobile User'
```

Status: **recipe, not yet driven**.

- **Update profile and appearance** - run this exact recipe at both required viewports:
  ```json
  [
    {"login":{"email":"user+{{viewport}}@verify.test","password":"verify-password"}},
    {"goto":"/settings/profile"},
    {"fillLabel":{"label":"Name","value":"Updated {{viewport}}"}},
    {"fillLabel":{"label":"Email","value":"updated+{{viewport}}@verify.test"}},
    {"clickRole":{"role":"button","name":"Save"}},
    {"expectValue":{"label":"Name","equals":"Updated {{viewport}}"}},
    {"expectValue":{"label":"Email","equals":"updated+{{viewport}}@verify.test"}},
    {"goto":"/settings/appearance"},
    {"clickRole":{"role":"radio","name":"Dark"}},
    {"expectChecked":{"selector":"input[value='dark']"}},
    {"overflow":false},
    {"shot":"dark-appearance"}
  ]
  ```
  Persist each profile side effect with:
  ```bash
  .claude/skills/verify-sink/harness/inspect-db.sh --user-email='updated+1280x800@verify.test'
  .claude/skills/verify-sink/harness/inspect-db.sh --user-email='updated+390x844@verify.test'
  ```
- **Reach security through password confirmation and update the password** - seed
  `password-user@verify.test`, then run this destructive recipe at one viewport:
  ```json
  [
    {"login":{"email":"password-user@verify.test","password":"verify-password"}},
    {"goto":"/settings/security"},
    {"expectUrl":{"contains":"/user/confirm-password"}},
    {"fillLabel":{"label":"Password","value":"verify-password"}},
    {"click":"[data-test='confirm-password-button']"},
    {"expectUrl":{"contains":"/settings/security"}},
    {"fillLabel":{"label":"Current password","value":"verify-password"}},
    {"fillLabel":{"label":"New password","value":"updated-password"}},
    {"fillLabel":{"label":"Confirm password","value":"updated-password"}},
    {"shot":"before-password-update"},
    {"click":"[data-test='update-password-button']"},
    {"click":"[data-test='sidebar-menu-button']"},
    {"click":"[data-test='logout-button']"},
    {"login":{"email":"password-user@verify.test","password":"updated-password"}},
    {"expectUrl":{"contains":"/dashboard"}},
    {"click":"[data-test='sidebar-menu-button']"},
    {"click":"[data-test='logout-button']"},
    {"goto":"/login"},
    {"fillLabel":{"label":"Email address","value":"password-user@verify.test"}},
    {"fillLabel":{"label":"Password","value":"verify-password"}},
    {"click":"[data-test='login-button']"},
    {"expectUrl":{"contains":"/login"}},
    {"expectText":{"selector":"body","contains":"These credentials do not match our records."}}
  ]
  ```
- **Delete account** - when the conditionally rendered control is present, run this exact recipe only
  in the disposable database and at one viewport:
  ```json
  [
    {"login":{"email":"delete-user@verify.test","password":"verify-password"}},
    {"goto":"/settings/profile"},
    {"clickRole":{"role":"button","name":"Delete account"}},
    {"expectRole":{"role":"heading","name":"Are you sure you want to delete your account?"}},
    {"fillLabel":{"label":"Password","value":"verify-password"}},
    {"clickRole":{"role":"button","name":"Delete account"}},
    {"expectUrl":{"contains":"/"}}
  ]
  ```
  Then require `.claude/skills/verify-sink/harness/inspect-db.sh --user-email='delete-user@verify.test'`
  to report `count` zero. If the control is conditionally absent, report `verified-unreachable` with
  that prerequisite; do not change product configuration.
- **2FA and passkeys** - this harness provides neither an isolated TOTP generator nor a Playwright CDP
  virtual authenticator. Report both entry points `verifier-blocked`; merely seeing the controls is not
  proof of enrollment, recovery, removal, or sign-in, and no manual authenticator operation is part of
  this recipe map.

## Gotchas

- Security uses `password.confirm`; a direct route does not land on the settings component first.
- Changing the email can expose an email-verification state. Mail is forced to the run's log and never
  leaves the machine.
- Appearance is client-side Flux state, so database inspection cannot prove it. Capture screenshots
  and the selected radio at both viewports.
- Passkeys need a virtual authenticator and the run's loopback origin. This harness does not configure
  one automatically.
- Account deletion is irreversible inside the run and may be conditionally hidden. Never substitute a
  developer account or Herd database to make the control appear.
