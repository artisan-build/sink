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

Preconditions: baseline 1-5. Seed `user@verify.test` with password `verify-password` without `--admin`.

Status: **recipe, not yet driven**.

- **Update profile** - login, `goto` `/settings/profile`, fill labels `Name` and `Email`, click the
  `Save` button in the profile form, and expect the new values to remain rendered. Read the `users`
  row from this run's PostgreSQL database as the side effect.
- **Change appearance** - `goto` `/settings/appearance`, click the radio named `Light`, `Dark`, or
  `System`, screenshot the result, and repeat at both viewports. This is browser-local state and has
  no database side effect.
- **Reach security through password confirmation** - `goto` `/settings/security`, expect URL
  `/user/confirm-password`, fill `Password` with `verify-password`, click the existing
  `[data-test="confirm-password-button"]`, and expect `/settings/security`.
- **Update password** - after confirmation, fill `Current password`, `New password`, and
  `Confirm password`, click `[data-test="update-password-button"]`, log out, and prove login works
  with the new password and fails with the old one.
- **2FA and passkeys** - drive only with an isolated TOTP generator or Playwright CDP virtual
  authenticator. If neither is configured, report `verifier-blocked`; merely seeing the controls is
  not proof of enrollment or sign-in.

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
