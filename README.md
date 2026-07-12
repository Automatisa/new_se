# new_sentora

> **A security-hardened fork of [Sentora 2.0.2](https://github.com/sentora/sentora-core), which is itself a fork of [ZPanelCP](http://www.zpanelcp.com/).**

## Project Lineage

| Project | Year | Copyright | License |
|---------|------|-----------|---------|
| ZPanelCP | 2005-2014 | ZPanelCP Team | GPL v2 (later v3) |
| Sentora | 2014-present | Sentora Project (TGates, Me.B, Jettawan) | GPL v3 |
| **new_sentora** | **2024-present** | **new_sentora maintainer** | **GPL v3** |

See [`NOTICE`](../NOTICE) for the complete authorship chain.

## Description

**new_sentora** is a fork of [Sentora](https://sentora.org), a complete open-source web hosting control panel written in PHP. Sentora is itself based on an original fork of ZPanelCP, designed to work with Linux, UNIX, and the BSDs (tested on FreeBSD).

This fork adds:
- 30+ security fixes (SQL injection, command injection, CSRF, XSS, path traversal)
- A complete API Manager with role-based access control
- ClamAV antivirus integration (admin and user modules)
- Antispam management modules
- New DNS record types (NAPTR, SSHFP, TLSA, URI)
- 3-state client system (Active / Suspended / Disabled)
- Service suspension hooks (FTP, Email, MySQL)

## Requirements

* PHP 8.2+ (originally tested with PHP 7.4 on Sentora 2.0.2)
* Apache, MySQL/MariaDB, BIND, ProFTPd, Postfix, Dovecot
* Tested on FreeBSD 14 with PHP-FPM 8.4

## Installation

See `install_sentora.sh` in this directory.

## License

This project is licensed under the **GNU General Public License v3** (GPL v3).
See [LICENSE.md](LICENSE.md) for the full text of the license.

**Required attribution:**
- Original ZPanelCP code: Copyright (C) 2005-2014 ZPanelCP Team
- Sentora fork: Copyright (C) 2014-present Sentora Project
- new_sentora modifications: Copyright (C) 2024-present new_sentora maintainer

## Trademark Notice

"Sentora" and "ZPanel" are trademarks of their respective owners. This fork
is not affiliated with, endorsed by, or sponsored by the Sentora Project or
the original ZPanelCP team. The use of these names is for descriptive purposes
only, to identify the project lineage.

## Getting support

For issues specific to new_sentora modifications, check the changelog
(CHANGELOG.md) and the solutions register (SOLUCIONES.md).

For upstream Sentora issues, see:
* [Sentora Website](https://sentora.org/)
* [Sentora Forums](https://forums.sentora.org/)
* [Sentora Bug Tracker](https://github.com/sentora/sentora-core/issues)
* [Sentora Documentation](https://docs.sentora.org/)

## Original Sentora Description (preserved)

Sentora is a complete open-source web hosting control panel written in PHP and
is designed to work with Linux, UNIX and the BSDs. Sentora is developed and
maintained by original ZPanel team members (TGates, Me.B and Jettawan).
Sentora is designed to be installed on a minimal OS with NO webserver
packages pre-installed. The automated installer will install and configure
Apache, PHP, MySQL, BIND, ProFTPd, etc.
