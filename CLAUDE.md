# Claude Code Memory - LOIQ WordPress Agent

## Project Overview
WordPress plugin voor beveiligde remote debugging, write capabilities en volledige site building via Claude CLI.
**v3.0:** Read-only diagnose (v1) + write endpoints (v2) + site builder met Divi, menus, media, forms, facets (v3).

---

## Quick Start

### Klant Setup
1. Upload plugin naar `/wp-content/plugins/loiq-wp-agent/`
2. Activeer plugin in WordPress admin
3. **Kopieer het getoonde token direct** (wordt 1x getoond)
4. Plugin is nu 24 uur actief
5. **Enable gewenste Power Modes** in Tools > LOIQ WP Agent (standaard alles uit)

### Claude CLI Gebruik
```bash
# Read (v1) — X-Claude-Token of Authorization: Bearer
curl -H "X-Claude-Token: <token>" https://site.nl/wp-json/claude/v1/status
curl -H "Authorization: Bearer <token>" https://site.nl/wp-json/claude/v1/status

# Write (v2) — vereist Power Mode enabled
curl -X POST -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"css":".hero{color:red}","target":"child_theme","dry_run":true}' \
  https://site.nl/wp-json/claude/v2/css/deploy

# Site Builder (v3) — vereist Power Mode enabled
curl -X POST -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"title":"Homepage","template":"homepage","status":"draft"}' \
  https://site.nl/wp-json/claude/v3/page/create
```

> **v3.2.1+**: `Authorization: Bearer <token>` is nu de aanbevolen auth methode.
> SiteGround (en andere managed hosting) stript custom headers bij requests met query parameters.
> `X-Claude-Token` werkt nog steeds als fallback voor requests zonder query params.

---

## Read Endpoints (v1)

| Endpoint | Beschrijving |
|----------|--------------|
| `GET /claude/v1/status` | WP/PHP versie, memory, debug mode |
| `GET /claude/v1/errors?lines=50` | Laatste N regels debug.log |
| `GET /claude/v1/plugins` | Alle plugins met update status |
| `GET /claude/v1/theme` | Actief thema + parent info |
| `GET /claude/v1/database` | Tabellen, row counts, sizes (GEEN data) |
| `GET /claude/v1/code-context?topic=X` | Code context per topic |
| `GET /claude/v1/styles` | CSS stylesheets, customizer CSS, theme CSS |
| `GET /claude/v1/forms` | Gravity Forms structuur (alle of ?id=3) |
| `GET /claude/v1/page?url=/pad` | Pagina context (of ?id=42) |

---

## Write Endpoints (v2)

Alle write endpoints vereisen:
- Actieve timer (niet verlopen)
- Geldige token
- **Power Mode enabled** voor die categorie
- Write rate limit: 10/min, 120/hr

| Endpoint | Power Mode | Beschrijving |
|----------|-----------|-------------|
| `POST /claude/v2/css/deploy` | css | CSS deployen naar child theme, Divi custom CSS, of customizer |
| `POST /claude/v2/option/update` | options | Whitelisted WP opties bijwerken |
| `POST /claude/v2/plugin/toggle` | plugins | Plugin activeren/deactiveren |
| `POST /claude/v2/content/update` | content | Post/pagina titel, content, meta, status bijwerken |
| `POST /claude/v2/snippet/deploy` | snippets | PHP snippet deployen als mu-plugin |

### Management Endpoints (v2)

| Endpoint | Beschrijving |
|----------|-------------|
| `POST /claude/v2/rollback` | Snapshot terugdraaien |
| `GET /claude/v2/snapshots` | Recente snapshots lijst |
| `GET /claude/v2/power-modes` | Huidige power mode status |

---

## Site Builder Endpoints (v3)

### Divi Builder

| Endpoint | Method | Power Mode | Beschrijving |
|----------|--------|-----------|-------------|
| `/claude/v3/divi/build` | POST | divi_builder | JSON → Divi shortcode (met validatie) |
| `/claude/v3/divi/parse` | POST | — (read) | Divi shortcode → structured JSON |
| `/claude/v3/divi/validate` | POST | — (read) | Valideer shortcode structuur |
| `/claude/v3/divi/modules` | GET | — | Module registry (35+ modules met attrs + voorbeelden) |
| `/claude/v3/divi/templates` | GET | — | Beschikbare page templates |
| `/claude/v3/divi/template/{name}` | GET | — | Template ophalen als JSON |

### Divi Theme Builder

| Endpoint | Method | Power Mode | Beschrijving |
|----------|--------|-----------|-------------|
| `/claude/v3/theme-builder/list` | GET | — | Alle templates met condities |
| `/claude/v3/theme-builder/read` | GET | — | Template detail (header/body/footer content) |
| `/claude/v3/theme-builder/create` | POST | divi_builder | Nieuwe template aanmaken |
| `/claude/v3/theme-builder/update` | POST | divi_builder | Template layout content updaten |
| `/claude/v3/theme-builder/assign` | POST | divi_builder | Condities toewijzen (use_on/exclude_from) |
| `/claude/v3/divi/library/list` | GET | — | Divi Library items |
| `/claude/v3/divi/library/save` | POST | divi_builder | Layout opslaan in Library |

### Page Management

| Endpoint | Method | Power Mode | Beschrijving |
|----------|--------|-----------|-------------|
| `/claude/v3/page/create` | POST | content | Nieuwe pagina met Divi content (JSON of shortcode) |
| `/claude/v3/page/clone` | POST | content | Dupliceer pagina als draft |
| `/claude/v3/page/list` | GET | — | Lijst pagina's met status, template, Divi info |

### Child Theme Functions

| Endpoint | Method | Power Mode | Beschrijving |
|----------|--------|-----------|-------------|
| `/claude/v3/child-theme/functions/read` | GET | — | Lees functions.php (volledig of per tagged block) |
| `/claude/v3/child-theme/functions/append` | POST | child_theme | Tagged block toevoegen |
| `/claude/v3/child-theme/functions/remove` | POST | child_theme | Tagged block verwijderen |
| `/claude/v3/child-theme/functions/list` | GET | — | Lijst alle LOIQ-AGENT tagged blocks |

### Menu Management

| Endpoint | Method | Power Mode | Beschrijving |
|----------|--------|-----------|-------------|
| `/claude/v3/menu/list` | GET | — | Alle menus met locaties |
| `/claude/v3/menu/create` | POST | menus | Menu aanmaken |
| `/claude/v3/menu/items/add` | POST | menus | Items toevoegen (pages, custom links, hiërarchie) |
| `/claude/v3/menu/items/reorder` | POST | menus | Volgorde wijzigen |
| `/claude/v3/menu/assign` | POST | menus | Menu toewijzen aan locatie |
| `/claude/v3/menu/mega-menu/read` | GET | — | Max Mega Menu config |
| `/claude/v3/menu/mega-menu/configure` | POST | menus | Mega settings per item |

### Media Upload

| Endpoint | Method | Power Mode | Beschrijving |
|----------|--------|-----------|-------------|
| `/claude/v3/media/upload` | POST | media | Upload afbeelding (base64 of URL, max 5MB) |
| `/claude/v3/media/search` | GET | — | Media library doorzoeken |

### Gravity Forms

| Endpoint | Method | Power Mode | Beschrijving |
|----------|--------|-----------|-------------|
| `/claude/v3/forms/create` | POST | forms | GF aanmaken via GFAPI |
| `/claude/v3/forms/update` | POST | forms | Form bewerken |
| `/claude/v3/forms/delete` | POST | forms | Form verwijderen |
| `/claude/v3/forms/embed` | GET | — | Shortcode genereren |

### FacetWP

| Endpoint | Method | Power Mode | Beschrijving |
|----------|--------|-----------|-------------|
| `/claude/v3/facet/list` | GET | — | Alle facets + templates |
| `/claude/v3/facet/create` | POST | facets | Facet aanmaken |
| `/claude/v3/facet/update` | POST | facets | Facet bewerken |
| `/claude/v3/facet/template` | POST | facets | Template config |

### Taxonomy Management

| Endpoint | Method | Power Mode | Beschrijving |
|----------|--------|-----------|-------------|
| `/claude/v3/taxonomy/list` | GET | — | Alle taxonomies + terms |
| `/claude/v3/taxonomy/create-term` | POST | content | Term aanmaken |
| `/claude/v3/taxonomy/assign` | POST | content | Terms toewijzen aan post |

---

## v3 Endpoint Details

### Page Create
```json
// POST /claude/v3/page/create
{
    "title": "Homepage",
    "template": "homepage",
    "status": "draft",
    "page_layout": "et_full_width_page",
    "parent_id": 0,
    "dry_run": false
}
```
Templates: `homepage`, `diensten`, `contact`, `over-ons`, `blog-single`, `landing-page`.

### Divi Build (JSON → Shortcode)
```json
// POST /claude/v3/divi/build
{
    "content": {
        "type": "section",
        "children": [{
            "type": "row",
            "settings": {"column_structure": "1_3,1_3,1_3"},
            "children": [
                {"type": "column", "children": [{"type": "blurb", "settings": {"title": "Dienst 1"}}]},
                {"type": "column", "children": [{"type": "blurb", "settings": {"title": "Dienst 2"}}]},
                {"type": "column", "children": [{"type": "blurb", "settings": {"title": "Dienst 3"}}]}
            ]
        }]
    },
    "dry_run": false
}
```

### Child Theme Functions Append
```json
// POST /claude/v3/child-theme/functions/append
{
    "name": "custom-login-logo",
    "code": "add_action('login_head', function() { echo '<style>.login h1 a { background-image: url(/logo.svg); }</style>'; });",
    "dry_run": false
}
```
Creates tagged block: `// === LOIQ-AGENT:custom-login-logo START/END ===`

### Menu Create + Items
```json
// POST /claude/v3/menu/create
{"name": "Hoofdmenu", "dry_run": false}

// POST /claude/v3/menu/items/add
{
    "menu_id": 5,
    "items": [
        {"type": "page", "object_id": 42, "position": 1},
        {"type": "custom", "title": "Blog", "url": "/blog", "position": 2},
        {"type": "page", "object_id": 55, "parent_item_id": 42, "position": 1}
    ],
    "dry_run": false
}
```

### Media Upload
```json
// POST /claude/v3/media/upload
{
    "source": "url",
    "url": "https://example.com/image.jpg",
    "filename": "hero-image.jpg",
    "alt": "Hero afbeelding",
    "dry_run": false
}
```

### Gravity Forms Create
```json
// POST /claude/v3/forms/create
{
    "form": {
        "title": "Contact Formulier",
        "fields": [
            {"type": "name", "label": "Naam", "isRequired": true},
            {"type": "email", "label": "E-mail", "isRequired": true},
            {"type": "textarea", "label": "Bericht", "isRequired": true}
        ]
    },
    "dry_run": false
}
```

### FacetWP Create
```json
// POST /claude/v3/facet/create
{
    "facet": {
        "name": "vacature_locatie",
        "label": "Locatie",
        "type": "dropdown",
        "source": "tax/locatie"
    },
    "dry_run": false
}
```

### Theme Builder Create
```json
// POST /claude/v3/theme-builder/create
{
    "title": "Blog Template",
    "areas": {
        "header": "[et_pb_section]...[/et_pb_section]",
        "body": "[et_pb_section]...[/et_pb_section]",
        "footer": "[et_pb_section]...[/et_pb_section]"
    },
    "dry_run": false
}
```

---

## Write Endpoint Details (v2)

### CSS Deploy
```json
// POST /claude/v2/css/deploy
{
    "css": ".hero-section { background: #d85f2c; }",
    "target": "child_theme",     // "child_theme" | "divi_custom_css" | "customizer"
    "mode": "append",            // "append" | "replace" | "prepend"
    "dry_run": false
}
```

### Option Update
```json
// POST /claude/v2/option/update
{
    "option": "blogname",
    "value": "Nieuwe Site Naam",
    "dry_run": false
}
```
Whitelisted: blogname, blogdescription, timezone_string, date_format, time_format, start_of_week, posts_per_page, permalink_structure.

### Plugin Toggle
```json
// POST /claude/v2/plugin/toggle
{
    "plugin": "gravityforms/gravityforms.php",
    "action": "deactivate",
    "dry_run": false
}
```

### Content Update
```json
// POST /claude/v2/content/update
{
    "post_id": 42,
    "fields": {
        "post_title": "Nieuwe titel",
        "post_content": "...",
        "post_status": "draft",
        "meta": {
            "_yoast_wpseo_title": "SEO Title"
        }
    },
    "dry_run": false
}
```

### Snippet Deploy
```json
// POST /claude/v2/snippet/deploy
{
    "name": "custom-redirect",
    "code": "add_action('template_redirect', function() { ... });",
    "dry_run": false
}
```

### Rollback
```json
// POST /claude/v2/rollback
{
    "snapshot_id": 42
}
```

---

## Safeguards

### Power Modes (fail-closed)
- Default: alles **uitgeschakeld**
- Per-categorie (11 totaal):
  - v2: `css`, `options`, `plugins`, `content`, `snippets`
  - v3: `divi_builder`, `child_theme`, `menus`, `media`, `forms`, `facets`
- Timer verlopen = alles geblokkeerd (ongeacht power mode setting)
- Toggelbaar via wp-admin UI

### Snapshots
- Elke write operatie maakt een pre-edit snapshot
- Bevat before/after state, session ID, IP, timestamp
- Rollback herstelt de before state
- Dry-run maakt snapshot zonder uit te voeren

### Snippet Veiligheid
- Geblokkeerde patronen: eval(), exec(), system(), shell_exec(), passthru(), proc_open()
- Max 500 regels per snippet
- Max 5 actieve snippets
- ABSPATH check auto-injected
- Deployed als mu-plugin: `/wp-content/mu-plugins/loiq-snippet-{name}.php`

### Child Theme Veiligheid (v3)
- PHP syntax check (`php -l`) voor elke append
- Dangerous pattern scan (hergebruik snippet scanner)
- Max 100KB bestandsgrootte voor functions.php
- Tagged blocks: `// === LOIQ-AGENT:{name} START/END ===`
- Snapshot van hele functions.php voor rollback

### Media Veiligheid (v3)
- Max 5MB upload
- Alleen image/* MIME types
- Rate limit van toepassing

### Option Whitelist
Deny-by-default. Alleen veilige opties toegestaan. NOOIT: siteurl, home, admin_email, users_can_register, db_*, secret_*.

### Content Safeguards
- Geen `post_status: "trash"` (geen hard delete)
- Geblokkeerde meta key prefixes: _wp_attached, _edit_lock, _wp_trash, etc.
- Geblokkeerde meta patterns: password, secret, token, auth_key, nonce, hash

---

## Security Features

| Feature | Implementatie |
|---------|---------------|
| Token | Auto-generated, `password_hash()` opslag |
| HTTPS | Verplicht (behalve localhost) |
| Read rate limit | 30/min, 300/uur per IP |
| Write rate limit | 10/min, 120/uur per IP |
| Auto-disable | Timer-based (default 24u) — endpoints + debug logging + power modes |
| Managed logging | mu-plugin toggled WP_DEBUG_LOG via timer |
| IP whitelist | Optioneel in admin |
| Logging | Database met GDPR IP anonimisatie |
| Power modes | 11 categorieën, fail-closed |
| Snapshots | Pre-edit met rollback |
| Dry-run | Preview changes zonder uitvoeren |
| Snippet scanner | Blokkeert gevaarlijke PHP patronen |
| PHP syntax check | Valideert child theme code voor deployment |

---

## Key Files

| File | Doel |
|------|------|
| `loiq-wp-agent.php` | Entry point, v1 read endpoints, admin UI, permission checks, route registration |
| `includes/class-write-endpoints.php` | v2 write + management endpoint handlers |
| `includes/class-safeguards.php` | Power modes (11), snapshots, dry-run, rollback, snippet scanning |
| `includes/class-audit.php` | Extended audit trail voor alle write operaties |
| `includes/class-divi-builder.php` | Divi JSON↔shortcode builder, parser, validator, module registry |
| `includes/class-divi-theme-builder.php` | Theme Builder CRUD, Divi Library management |
| `includes/class-page-endpoints.php` | Pagina create, clone, list met template support |
| `includes/class-child-theme.php` | functions.php tagged block CRUD met safeguards |
| `includes/class-menu-endpoints.php` | WP menus + Max Mega Menu configuratie |
| `includes/class-media-endpoints.php` | Media upload (base64/URL) + search |
| `includes/class-forms-endpoints.php` | Gravity Forms CRUD via GFAPI |
| `includes/class-facet-endpoints.php` | FacetWP facet + template management |
| `includes/class-taxonomy-endpoints.php` | Taxonomy/term management |
| `templates/*.json` | 6 Divi page templates (homepage, diensten, contact, over-ons, blog-single, landing-page) |
| `uninstall.php` | Cleanup bij verwijderen (tabellen, options, mu-plugins, snippets) |

---

## Page Templates (v3)

Beschikbaar via `/claude/v3/divi/templates` en `/claude/v3/page/create?template=X`.

| Template | Beschrijving | Secties |
|----------|-------------|---------|
| `homepage` | Standaard homepage | Hero, features (3-col), about (2-col), testimonials, CTA |
| `diensten` | Diensten overzicht | Header, diensten grid (2x3), CTA |
| `contact` | Contactpagina | Header, formulier + contactgegevens, Google Maps |
| `over-ons` | Over ons | Header, verhaal (2-col), kernwaarden (4-col), team (3-col) |
| `blog-single` | Blog overzicht | Header, blog module + sidebar |
| `landing-page` | Conversie landing page | Fullwidth hero, voordelen (3-col), social proof counters, testimonial, CTA |

Templates gebruiken placeholders: `{{site_name}}`, `{{tagline}}`, `{{headline}}`, `{{admin_email}}`, `{{domain}}`, `{{placeholder_image}}`.

---

## Admin Functies

**Tools > LOIQ WP Agent**
- Token regenereren
- Timer verlengen (1u/24u/1 week)
- IP whitelist beheer
- **Power Modes toggles** (11 categorieën: 5 v2 + 6 v3)
- **Recente Snapshots** (met rollback buttons)
- **Gedeployde Snippets** (met verwijder buttons)
- Request log viewer
- **v3 Endpoint documentatie** (inline reference table)

---

## Response Codes

| Code | Betekenis |
|------|-----------|
| 200 | Success |
| 400 | Ongeldige request (bad input, dry-run rollback, etc.) |
| 401 | Token ontbreekt of ongeldig |
| 403 | HTTPS verplicht, IP geblokkeerd, power mode uit, of geblokkeerde actie |
| 404 | Resource niet gevonden (post, plugin, snapshot) |
| 429 | Rate limit bereikt (read of write) |
| 500 | Server fout (niet schrijfbaar, etc.) |
| 503 | Auto-disable timer verlopen |

---

## Divi Module Registry (v3)

De `divi/modules` endpoint retourneert 35+ modules met hun attributen en voorbeelden:

**Structuur:** `section`, `row`, `column`, `fullwidth_section`
**Content:** `text`, `image`, `video`, `code`, `divider`
**Interactie:** `button`, `cta`, `contact_form`, `contact_field`, `login`
**Media:** `gallery`, `video`, `video_slider`, `audio`, `map`, `map_pin`
**Layout:** `blurb`, `slider`, `slide`, `testimonial`, `tabs`, `tab`, `accordion`, `accordion_item`, `toggle`
**Data:** `number_counter`, `circle_counter`, `countdown_timer`, `bar_counter`, `bar_counters_item`
**Social:** `social_media_follow`, `social_media_follow_network`
**Blog:** `blog`, `post_title`, `post_content`, `comments`
**Fullwidth:** `fullwidth_header`, `fullwidth_image`, `fullwidth_slider`, `fullwidth_code`, `fullwidth_menu`
**Navigation:** `sidebar`, `menu`, `search`

---

## Site Build Workflow

Typische flow voor het bouwen van een complete site:

1. **Pagina's aanmaken:** `page/create` met templates (homepage, diensten, contact, over-ons)
2. **Content aanpassen:** `v2/content/update` of `divi/build` → `content/update`
3. **Menu bouwen:** `menu/create` → `menu/items/add` → `menu/assign`
4. **Theme Builder:** `theme-builder/create` → `theme-builder/assign` (header/footer)
5. **Formulieren:** `forms/create` → embed shortcode in pagina
6. **Media:** `media/upload` → gebruik URL in Divi content
7. **Child theme:** `child-theme/functions/append` voor custom PHP
8. **Facets:** `facet/create` → `facet/template` voor filtering
9. **CSS:** `v2/css/deploy` voor fijntuning

---

## Learnings

### v3.0.0
- Site builder met 9 nieuwe endpoint classes en 40+ REST endpoints
- Divi JSON↔shortcode bidirectionele conversie met module registry (35+ modules)
- Theme Builder CRUD (et_template, header/body/footer layouts, condities)
- Child theme functions.php management met tagged blocks en PHP syntax check
- Menu management inclusief Max Mega Menu configuratie
- Media upload met base64 en URL support (5MB max, image/* only)
- Gravity Forms CRUD via GFAPI
- FacetWP facet en template management
- Taxonomy/term CRUD
- 6 page templates als JSON (homepage, diensten, contact, over-ons, blog-single, landing-page)
- 6 nieuwe power modes (fail-closed): divi_builder, child_theme, menus, media, forms, facets
- Snapshot/rollback uitgebreid voor alle v3 domeinen

### v2.0.0
- Write endpoints met per-categorie power modes (fail-closed)
- Snapshot systeem voor pre-edit state capture + rollback
- Dry-run mode op alle write endpoints
- Snippet deployer met veiligheidsscanner (blokkeert eval/exec/system)
- Content updates met blocked meta keys (geen _wp_*, geen secrets)
- Option whitelist (deny-by-default, alleen veilige opties)
- Plugin toggle safeguards (kan zichzelf niet deactiveren)
- Modulaire architectuur: main file + 3 includes
- DB versie check voor upgrades zonder re-activatie
- Write rate limits: 10/min, 120/hr (reads: 30/min, 300/hr)

### v1.8.0
- `/styles` endpoint: CSS stylesheets, customizer CSS, child theme CSS, Divi custom CSS
- `/forms` endpoint: Gravity Forms structuur inclusief fields, confirmations, notifications
- `/page` endpoint: Pagina context met meta keys, Divi modules, Yoast data

### v1.7.0
- Managed Debug Logging: mu-plugin koppelt WP_DEBUG_LOG aan de timer
- Timer verlopen = logging stopt automatisch, geen wp-config editing

### v1.5.0
- Code context endpoint maakt Claude "slim" over de codebase
- Security filtering essentieel: regex patterns voor sensitive data

### v1.0.0
- Single-file architectuur simpeler voor dit use case
- `password_hash()` beter dan plain CONSTANT in wp-config

---

## Training Documentatie

Uitgebreide training docs voor autonoom site bouwen:

| Document | Inhoud |
|----------|--------|
| `training/BUILD-BIBLE.md` | **CANONICAL** — 21 gouden regels, €100K+ agency standaard, build flow, "zo hoort het" patronen |
| `training/SITE-BUILD-PLAYBOOK.md` | API workflow: 9 fasen met exacte endpoint calls |
| `training/DIVI-THEME-BUILDER.md` | Theme Builder architectuur, blog templates, header/footer |
| `training/MAX-MEGA-MENU.md` | Mega menu configuratie via API |
| `training/DIVI-PATTERNS.md` | 9 design patterns als JSON (hero, grid, FAQ, CTA, etc.) |

**BUILD-BIBLE.md is de wet.** Lees deze EERST bij elke site build. De andere docs zijn API reference.
