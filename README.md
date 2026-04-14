# CloudPanel Mail Addon

**DKIM, SPF & DMARC management for CloudPanel v2** -- directly in the CloudPanel UI.

CloudPanel is a lightweight server control panel that intentionally does not include email features. This addon fills the gap for outbound email authentication: it adds a **"Mail" tab** to every site in CloudPanel, where you can generate DKIM keys and see the exact DNS records needed for SPF, DKIM, and DMARC -- with copy-to-clipboard and live DNS verification.

![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)
![CloudPanel: v2.5+](https://img.shields.io/badge/CloudPanel-v2.5%2B-orange)
![Ubuntu: 24.04 / 22.04](https://img.shields.io/badge/Ubuntu-24.04%20%7C%2022.04-purple)

---

## What This Addon Does

When you host websites on CloudPanel, those sites often need to send transactional emails -- password resets, contact form submissions, order confirmations. Without proper email authentication (DKIM, SPF, DMARC), these emails end up in spam folders or get rejected entirely.

This addon:

- Adds a **"Mail" tab** to every site in CloudPanel's web interface
- **Generates 2048-bit DKIM keys** per domain with one click
- Displays **copy-ready DNS records** (DKIM TXT, SPF TXT, DMARC TXT) that you paste into your domain's DNS settings
- **Verifies DNS propagation** with a live check button -- green badge when records are active, red when missing
- Configures **OpenDKIM** to sign all outbound mail with DKIM
- **Survives CloudPanel updates** via an APT hook that re-applies patches automatically

> **Note:** This addon does not install a mail server. CloudPanel already ships with Postfix (via its `bsd-mailx` dependency). This addon builds on top of that existing Postfix installation by adding OpenDKIM for email authentication. If your server uses a different MTA (sendmail, exim, etc.), the OpenDKIM integration may need manual adjustment.

---

## Requirements

- **CloudPanel v2.5+** on Ubuntu 24.04 or 22.04 (Postfix is included by default)
- **Root access** to the server
- A domain with DNS access (to add the generated TXT records)

---

## Installation

### 1. Clone to your server

```bash
git clone https://github.com/s-a-s-k-i-a/cloudpanel-mail-addon.git /opt/clp-mail-addon
```

Or download a specific release:

```bash
wget -qO- https://github.com/s-a-s-k-i-a/cloudpanel-mail-addon/archive/refs/heads/main.tar.gz | tar xz -C /opt/ && mv /opt/cloudpanel-mail-addon-main /opt/clp-mail-addon
```

### 2. Run the installer

```bash
/opt/clp-mail-addon/scripts/clp-mail-addon install
```

This will:
- Install **OpenDKIM** and configure it to work with the existing Postfix installation (no new mail server is installed)
- Connect OpenDKIM to Postfix via milter socket
- Copy the addon files into CloudPanel
- Add the "Mail" tab to the site navigation
- Register the necessary routes
- Install an APT hook for automatic repair after CloudPanel updates
- Clear the Symfony cache

### 3. Verify

```bash
clp-mail-addon check
```

All items should show `[OK]`.

---

## Usage

1. Open CloudPanel and navigate to any site
2. Click the **"Mail"** tab
3. Click **"Generate DKIM Key"** if no key exists yet
4. Copy the displayed DNS records into your domain's DNS settings:
   - **DKIM**: TXT record at `mail._domainkey.yourdomain.com`
   - **SPF**: TXT record at `yourdomain.com`
   - **DMARC**: TXT record at `_dmarc.yourdomain.com`
5. Click **"Verify DNS"** to check if the records have propagated

---

## How It Works

### Architecture

The addon integrates into CloudPanel's Symfony application without modifying any existing code:

| Component | Location | Purpose |
|-----------|----------|---------|
| `MailController.php` | `src/Controller/Frontend/` | Handles key generation, DNS display, verification |
| `mail.html.twig` | `templates/Frontend/Site/` | The "Mail" tab UI |
| `mail.css` | `public/assets/css/frontend/` | Styling for DNS record boxes |
| Route entries | `config/routes.yaml` | URL routing for the new tab |
| Tab entry | `tab-container.html.twig` | Adds "Mail" to the navigation |

### Permissions

CloudPanel runs its PHP-FPM process as the `clp` user. The `clp` user has passwordless sudo access (configured by CloudPanel itself in `/etc/sudoers.d/cloudpanel`). The addon uses `sudo` for operations that require root privileges:

- Reading DKIM keys (owned by `opendkim:opendkim`)
- Generating new DKIM key pairs via `opendkim-genkey`
- Updating OpenDKIM configuration files
- Restarting the OpenDKIM service

**No credentials, private keys, or sensitive data are stored in or exposed by the addon.** DKIM private keys remain on the server under `/etc/opendkim/keys/`, owned by the `opendkim` user with restricted permissions (640).

### Update Safety

CloudPanel updates replace the entire `/home/clp/htdocs/app/` directory. The addon handles this with:

1. **Source files** stored separately at `/opt/clp-mail-addon/` (untouched by updates)
2. **APT hook** at `/etc/apt/apt.conf.d/99-clp-mail-addon` that runs after every `dpkg` operation
3. **Idempotent repair**: The hook checks if patches are intact (a single `grep`, <1ms). If CloudPanel was updated and patches are gone, it re-applies them and clears the Symfony cache

---

## CLI Reference

```
clp-mail-addon install     # Full installation
clp-mail-addon check       # Verify all components are in place
clp-mail-addon repair      # Re-apply patches (runs automatically after updates)
clp-mail-addon uninstall   # Remove addon from CloudPanel (keeps DKIM keys)
```

---

## DNS Records Explained

### SPF (Sender Policy Framework)

Tells receiving mail servers which IPs are authorized to send email for your domain.

```
v=spf1 ip4:YOUR_SERVER_IP ip6:YOUR_SERVER_IPV6 -all
```

### DKIM (DomainKeys Identified Mail)

Adds a cryptographic signature to every outgoing email, proving it was sent from your server and was not tampered with in transit.

```
mail._domainkey.yourdomain.com  TXT  "v=DKIM1; h=sha256; k=rsa; p=YOUR_PUBLIC_KEY"
```

### DMARC (Domain-based Message Authentication, Reporting & Conformance)

Tells receiving mail servers what to do when SPF or DKIM checks fail.

```
_dmarc.yourdomain.com  TXT  "v=DMARC1; p=quarantine; rua=mailto:postmaster@yourhostname"
```

---

## Troubleshooting

### Mail tab does not appear

```bash
clp-mail-addon check    # Identify what is missing
clp-mail-addon repair   # Re-apply patches
```

### DKIM key generation fails

Check that OpenDKIM tools are installed:

```bash
which opendkim-genkey    # Should return /usr/bin/opendkim-genkey
```

If missing: `apt install opendkim opendkim-tools`

### Emails still going to spam

1. Verify all three DNS records are set (use the "Verify DNS" button in the Mail tab)
2. Check that PTR (reverse DNS) is configured for your server IP
3. Test with [mail-tester.com](https://www.mail-tester.com/) for a detailed report
4. Ensure your server IP is not on any blacklists

---

## Security

- **No credentials stored**: The addon does not store, transmit, or expose any passwords, API keys, or private keys
- **DKIM private keys**: Generated and stored under `/etc/opendkim/keys/` with `opendkim:opendkim` ownership and `640` permissions -- never accessible via the web
- **CSRF protection**: The DKIM generation endpoint uses Symfony's built-in CSRF token validation
- **Authentication**: The Mail tab inherits CloudPanel's session-based authentication -- only logged-in CloudPanel users can access it
- **Input validation**: Domain names are taken from CloudPanel's own site entity (database), not from user input

---

## Background & Motivation

CloudPanel deliberately does not include email features -- and that is a reasonable design decision to keep the panel lightweight. However, many CloudPanel users host WordPress sites, contact forms, or web applications that need to send transactional emails reliably. Without DKIM, SPF, and DMARC, those emails frequently land in spam.

This addon was born out of that exact need: a real-world hosting setup for [Tierheim Hannover](https://www.tierheim-hannover.de/) where outbound email authentication was required but no CloudPanel-native solution existed.

### Related CloudPanel Discussions

This addon addresses several long-standing requests in the CloudPanel community:

- [E-mail server support](https://github.com/cloudpanel-io/cloudpanel-ce/discussions/109) -- CloudPanel's official stance: "No email planned." This addon respects that by not adding a mail server, only outbound authentication.
- [SMTP relay service for emails](https://github.com/cloudpanel-io/cloudpanel-ce/discussions/211) -- Request for SMTP relay integration. This addon works alongside any relay setup.
- [Developer extensions](https://github.com/cloudpanel-io/cloudpanel-ce/discussions/465) -- Request for a plugin/extension system. This addon demonstrates that CloudPanel's Symfony architecture can be extended with self-repairing addons.
- [Add Setup Mail Server SPF and DKIM](https://feature-requests.cloudpanel.io/posts/257/add-setup-mail-server-spf-and-dkim) -- The exact feature request this addon fulfills.
- [Reconsider integration with Email Server](https://feature-requests.cloudpanel.io/posts/437/reconsider-integration-with-email-server) -- Users asking CloudPanel to reconsider. This addon offers a community-driven alternative.
- [Plugins/Addons ability](https://feature-requests.cloudpanel.io/posts/66/plugins-addons-ability) -- 26+ votes for a plugin system. This addon is a working proof of concept.

---

## Contributing

Contributions are welcome. Please open an issue before submitting large changes.

If you find this addon useful, consider sharing it in the discussions linked above -- the more CloudPanel users know about it, the better.

---

## License

MIT License. See [LICENSE](LICENSE) for details.

---

## Credits

Built by [Saskia Lund](https://github.com/s-a-s-k-i-a). Developed for real-world use at [Tierheim Hannover](https://www.tierheim-hannover.de/).
