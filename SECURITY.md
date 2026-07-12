# Security Policy

Latch is a self-hosted PHP forum. We take security reports seriously and appreciate responsible disclosure.

**Operator documentation** (headers, backups, hardening): [source/docs/SECURITY.md](source/docs/SECURITY.md)

## Supported versions

Security fixes are published for the **latest release** only. Upgrade via [GitHub Releases](https://github.com/YeOK/Latch/releases), COPR (`dnf upgrade latch`), or `scripts/update.sh`.

| Version              | Supported |
|----------------------|-----------|
| 0.4.5.2 (latest)     | Yes       |
| 0.4.5.0 and earlier  | No        |

When a new version is tagged, this table is updated in the release commit.

## Reporting a vulnerability

**Please report security issues through GitHub — not public issues.**

1. Open **[Report a vulnerability](https://github.com/YeOK/Latch/security/advisories/new)** (repo **Security** tab → **Advisories** → **Report a vulnerability**).
2. If that link is unavailable, ensure [Private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/working-with-repository-security-advisories/configuring-private-vulnerability-reporting-for-a-repository) is enabled for this repository, then retry.

We do **not** accept vulnerability reports via public GitHub Issues, pull requests, or the public forum.

### What to include

- Description of the issue and affected component (core, OAuth API, plugin system, installer, etc.)
- Steps to reproduce on a **stock install** (tarball or COPR), including version
- Impact (confidentiality, integrity, availability) and realistic attacker model
- Proof of concept or exploit code if you have it (private advisory only)
- Suggested fix or mitigation, if any

### What we will do

| Stage | Target |
|-------|--------|
| Acknowledgment | Within **3 business days** |
| Triage / severity | Within **7 business days** |
| Fix + advisory | Depends on severity; critical issues prioritised |

We will coordinate disclosure with you before publishing a [GitHub Security Advisory](https://github.com/YeOK/Latch/security/advisories) and release notes. Please allow reasonable time for a fix on a volunteer-maintained project.

Credit is given in the advisory and `CHANGELOG.md` unless you prefer to remain anonymous.

## In scope

Examples we want to hear about:

- Authentication or session bypass, privilege escalation, or broken access control (including board ACLs and admin/mod tools)
- Cross-site scripting (stored/reflected), CSRF on state-changing actions, or open redirects with security impact
- SQL injection or unsafe query construction (SQLite)
- Server-side request forgery (webhooks, outbound URL guards, OAuth/OIDC flows)
- Secrets or sensitive data exposure (`config/local.php`, tokens, backups, logs)
- Plugin hook / markup / upload sandbox escapes that affect other tenants on the same instance
- Installer or upgrade path issues that weaken a default deployment

## Out of scope

- **Operator misconfiguration** — e.g. world-readable `storage/`, missing TLS, weak admin passwords, disabled 2FA on admin accounts, origin not firewalled behind Cloudflare
- **Denial of service** without a distinct security root cause (generic load flooding)
- **Social engineering** or physical access
- Vulnerabilities in **third-party dependencies** — please report to the upstream project; tell us if you believe Latch must bump a locked dependency
- Issues already fixed on `main` or in an unreleased patch we are about to ship
- Automated scan noise without a demonstrated exploit on Latch defaults

## Safe harbour

We will not pursue legal action against researchers who:

- Make a good-faith effort to avoid privacy violations, data destruction, and service disruption
- Do not access other users’ data beyond what is needed to demonstrate the issue
- Report through the private advisory process above

## Security contacts

- **Reports:** [GitHub private vulnerability reporting](https://github.com/YeOK/Latch/security/advisories/new) only
- **General questions (non-sensitive):** [GitHub Discussions](https://github.com/YeOK/Latch/discussions) or a public issue labelled `question`

Thank you for helping keep Latch and its operators safe.