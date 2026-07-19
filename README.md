# Bulwark

> **Bulwark** — a security-hardened web hosting control panel for FreeBSD.
> A GPLv3 fork of [Sentora 2.0.2](https://github.com/sentora/sentora-core), which is itself a fork of [ZPanelCP](http://www.zpanelcp.com/).

Maintained by **Automatisa**.

## Project Lineage

| Project | Year | Copyright | License |
|---------|------|-----------|---------|
| ZPanelCP | 2005-2014 | ZPanelCP Team | GPL v2 (later v3) |
| Sentora | 2014-present | Sentora Project (TGates, Me.B, Jettawan) | GPL v3 |
| **Bulwark** | **2024-present** | **Automatisa** | **GPL v3** |

See [`NOTICE`](NOTICE) for the complete authorship chain.

## Description

**Bulwark** is a complete open-source web hosting control panel written in PHP, focused on
**FreeBSD** and hardened security. It descends from Sentora (a fork of ZPanelCP) and has been
substantially reworked: privilege separation, per-domain isolation, and a modernized stack.

Highlights over upstream Sentora:
- Multi-PHP per domain (8.2 / 8.3 / 8.4 / 8.5) with isolated FPM pools
- The panel runs under its own dedicated system user (isolated from `www`); secrets unreadable by the web server user; privilege escalation (doas) scoped to the panel only
- 30+ security fixes (SQL injection, command injection, CSRF, XSS, path traversal)
- A complete API Manager with role-based access control
- ClamAV antivirus integration (admin and user modules) and antispam management
- Let's Encrypt lifecycle hardening (ARI-first renewals, DNS-01/wildcard, revocation)
- New DNS record types (NAPTR, SSHFP, TLSA, URI) and a DNS cluster model
- IMAP mail migration (imapsync), hardened backup/restore, 3-state client system

## Requirements

* FreeBSD 15 (amd64); Apache + PHP-FPM, MySQL/MariaDB, BIND, ProFTPd, Postfix, Dovecot, Redis
* PHP 8.4 (panel) with optional additional versions per domain

## Installation

See `install_sentora.sh` in this directory. *(Path/name rebrand to `bulwark` is in progress.)*

## License

Bulwark is licensed under the **GNU General Public License v3** (GPL v3). See
[LICENSE.md](LICENSE.md) for the full license text and [`NOTICE`](NOTICE) for attributions.

As a derivative of GPLv3 software, Bulwark **remains GPLv3** and preserves the original
copyright notices of ZPanelCP and Sentora. The Bulwark copyright is **added** to, not
substituted for, the prior notices:
- Original ZPanelCP code: Copyright (C) 2005-2014 ZPanelCP Team
- Sentora fork: Copyright (C) 2014-present Sentora Project
- Bulwark modifications: Copyright (C) 2024-present Automatisa

## Trademark Notice

"Bulwark" is the name/brand of this project (Automatisa). "Sentora" and "ZPanel" are
trademarks of their respective owners; Bulwark is **not** affiliated with, endorsed by, or
sponsored by the Sentora Project or the ZPanelCP team — those names appear only to identify
the project's lineage and to satisfy the GPL attribution requirements.

## Getting support

For Bulwark, see the changelog (`CHANGELOG.md`) and the solutions register (`SOLUCIONES.md`).

Upstream Sentora references (historical): [website](https://sentora.org/) ·
[forums](https://forums.sentora.org/) · [issues](https://github.com/sentora/sentora-core/issues).
