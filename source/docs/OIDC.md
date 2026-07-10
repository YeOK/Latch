# Social sign-in (OIDC)

Latch supports optional **Sign in with Google** and **Sign in with GitHub**. This is separate from the built-in **OAuth 2.0 authorization server** used for API clients (`docs/API.md`).

## Setup

1. Create OAuth/OIDC apps with each provider.
2. Add credentials to `config/local.php`:

```php
'oidc' => [
    'google' => [
        'client_id' => '….apps.googleusercontent.com',
        'client_secret' => '…',
    ],
    'github' => [
        'client_id' => '…',
        'client_secret' => '…',
    ],
],
```

3. Register redirect URIs (exact match):

| Provider | Callback URL |
|----------|----------------|
| Google | `https://your-forum.example/auth/oidc/google/callback` |
| GitHub | `https://your-forum.example/auth/oidc/github/callback` |

4. In **Admin → Settings**, enable each provider under **Social sign-in (OIDC)**.

## Behaviour

- **New users:** A member account is created when the provider returns a **verified** email. Username is derived from the provider login or email local-part (made unique if needed).
- **Existing users:** If the provider email matches an existing account, the identity is linked automatically and email is marked verified.
- **Registration disabled:** Social sign-in can still create accounts when a provider is enabled.
- **Admins with 2FA:** OAuth completes first; mandatory TOTP enrolment or challenge still applies before the session is established.
- **Password:** Social-only accounts receive a random password hash; users sign in via the provider unless they set a password on their profile.

## Provider notes

### Google

- Google Cloud Console → APIs & Services → Credentials → OAuth client (Web application).
- Scopes: `openid`, `email`, `profile` (requested automatically).

### GitHub

- GitHub → Settings → Developer settings → OAuth Apps.
- Scopes: `read:user`, `user:email` (requested automatically).
- If the user hides their email on the profile, Latch reads verified addresses from the `/user/emails` API.

## Security

- CSRF `state` parameter on every authorization request.
- Client secrets stay in `local.php`, not the database or admin UI.
- Failed callbacks are logged to `storage/logs/security.log` as `oidc_fail`.
- Successful sign-ins log `login_success` with `provider` metadata.

## Production enablement (your site)

Providers are **off** until credentials exist in server `config/local.php` and an admin toggles them in **Settings**.

1. Create OAuth apps (redirect URIs in table above — use your public site URL, e.g. `https://forum.example.com/...`).
2. Add `oidc.google` / `oidc.github` blocks to `local.php` on the server (never commit secrets).
3. Deploy config with appropriate permissions (`640 apache:apache`).
4. Admin → Settings → enable each provider.
5. Run the E2E checklist in [TESTING.md](TESTING.md) § OIDC E2E.

**Demo tip:** Enable GitHub first (simpler app setup); add Google for the full OIDC flow. Confirm admin accounts still pass mandatory 2FA after OAuth.

## E2E verification summary

| Case | Pass criteria |
|------|----------------|
| New member via provider | Account created, session established |
| Email already registered | Linked to existing user |
| Admin login | OAuth + TOTP challenge |
| Invalid state / callback | Safe redirect; `oidc_fail` logged |

```bash
php bin/latch audit
tail -f storage/logs/security.log   # during manual sign-in tests
```