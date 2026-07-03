# Design: WebAuthn for admins (optional)

| Field | Value |
|-------|-------|
| **Status** | Proposed — deferred |
| **Priority** | Below restore/update/OIDC E2E |
| **Scope** | Passkey login for admin accounts |

## Goal

Offer **WebAuthn passkeys** as an optional second factor or primary sign-in for admins, complementing mandatory TOTP today.

## Non-goals (v1)

- Replacing TOTP for all users
- Cross-device passkey sync (platform responsibility)
- Passwordless for members by default

## Sketch

1. Migration: `user_webauthn_credentials` (credential id, public key, sign count, label).
2. Admin profile: register passkey via `navigator.credentials.create()`.
3. Login: after password (or instead for opted-in admins), `navigator.credentials.get()`.
4. Challenge stored in session; verify with `web-auth/webauthn-lib` or similar.

## Gate

Implement after Phase 5 OSS test gate and `db-check` / `restore` ship — credential changes need safe rollback paths.

## References

- Phase 3 optional item in `PLAN.md`
- `docs/TESTING.md` — WebAuthn section (deferred)