# Claude Code Memory - Ultrax Debug

## Project Overview
WordPress plugin voor beveiligde remote debugging via Claude CLI.

---

## Quick Start

### Klant Setup
1. Upload plugin naar `/wp-content/plugins/ultrax-debug/`
2. Activeer plugin in WordPress admin
3. **Kopieer het getoonde token direct** (wordt 1x getoond)
4. Plugin is nu 24 uur actief

### Claude CLI Gebruik
```bash
curl -H "X-Claude-Token: <token>" https://site.nl/wp-json/claude/v1/status
```

---

## Endpoints

| Endpoint | Beschrijving |
|----------|--------------|
| `/claude/v1/status` | WP/PHP versie, memory, debug mode |
| `/claude/v1/errors?lines=50` | Laatste N regels debug.log |
| `/claude/v1/plugins` | Alle plugins met update status |
| `/claude/v1/theme` | Actief thema + parent info |
| `/claude/v1/database` | Tabellen, row counts, sizes (GEEN data) |
| `/claude/v1/code-context?topic=X` | Code context per topic |

### Code Context Topics

| Topic | Wat het scant |
|-------|---------------|
| `gravity-forms` | gform_* hooks, forms count, add-ons |
| `woocommerce` | wc_* hooks, product/order counts |
| `divi` | et_* hooks, builder modules |
| `acf` | ACF field groups, acf/* hooks |
| `custom-post-types` | register_post_type calls |
| `rest-api` | register_rest_route calls |
| `cron` | wp_schedule_event calls |
| `general` | Alle add_action/add_filter |

**Code Context Response:**
```json
{
  "topic": "gravity-forms",
  "plugin_active": true,
  "plugin_version": "2.8.1",
  "theme_hooks": [
    {"file": "functions.php", "line": 234, "hook": "gform_after_submission", "snippet": "add_action(..."}
  ],
  "custom_functions": ["custom_after_submit()"],
  "forms_count": 12,
  "docs_url": "https://docs.gravityforms.com/..."
}
```

**Security filters:**
- Max 3 regels per snippet
- Strips: API keys, passwords, tokens, credentials
- Alleen function signatures, geen implementatie
- Max 50 matches per scan

---

## Security Features

| Feature | Implementatie |
|---------|---------------|
| Token | Auto-generated, `password_hash()` opslag |
| HTTPS | Verplicht (behalve localhost) |
| Rate limit | 10/min, 100/uur per IP |
| Auto-disable | Timer-based (default 24u) |
| IP whitelist | Optioneel in admin |
| Logging | Database met GDPR IP anonimisatie |
| Read-only | Alleen GET endpoints, geen data wijzigen |

---

## Admin Functies

**Tools > Ultrax Debug**
- Token regenereren
- Timer verlengen (1u/24u/1 week)
- IP whitelist beheer
- Request log viewer

---

## Key Files

| File | Doel |
|------|------|
| `ultrax-debug.php` | Alles-in-één plugin (geen modules) |
| `uninstall.php` | Cleanup bij verwijderen |

---

## Waarom Alles-in-Één?

Deze plugin is bewust single-file:
- Simpelere deployment (1 bestand kopiëren)
- Minder kans op fouten
- Gemakkelijker te auditen
- ~650 regels is nog overzichtelijk

---

## Security Checklist

- [x] Token niet plain-text opgeslagen
- [x] HTTPS verplicht in productie
- [x] Rate limiting voorkomt brute force
- [x] Auto-disable voorkomt vergeten endpoints
- [x] IP anonimisatie voor GDPR
- [x] Capability check op admin functies
- [x] Nonce verificatie op AJAX calls
- [x] Geen gevoelige data in responses (geen users/passwords/config)

---

## Response Codes

| Code | Betekenis |
|------|-----------|
| 200 | Success |
| 401 | Token ontbreekt of ongeldig |
| 403 | HTTPS verplicht of IP geblokkeerd |
| 429 | Rate limit bereikt |
| 503 | Auto-disable timer verlopen |

---

## Troubleshooting

### 401 Unauthorized
- Check `X-Claude-Token` header
- Regenereer token in admin

### 403 Forbidden
- Check of site HTTPS heeft
- Check IP whitelist settings

### 429 Rate Limit
- Wacht 1 minuut en probeer opnieuw
- Max 10 requests per minuut

### 503 Disabled
- Open WP admin > Tools > Ultrax Debug
- Klik "Activeer 24 uur"

---

## Learnings

### v1.5.0
- Code context endpoint maakt Claude "slim" over de codebase
- Security filtering essentieel: regex patterns voor sensitive data
- Topic-based scanning is efficiënter dan full codebase dump
- Max 50 matches voorkomt overwhelm

### v1.0.0
- Single-file architectuur is simpeler voor dit use case
- `password_hash()` beter dan plain CONSTANT in wp-config
- Transient met one-time display token werkt goed
- IP anonimisatie: `preg_replace('/\.\d+$/', '.0', $ip)`
