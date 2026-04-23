# Post Extractor API — WordPress Plugin

Extends the WordPress REST API with a cross-type posts feed, Gutenberg section data, ACF support, and CPT discovery — shaped for the **NewsBEPA Flutter app** (`WordPressService`). Add the API key in Dart as in [Flutter — send the API key](#flutter--send-the-api-key) when authentication is required.

---

## What this plugin can do

- Serve a unified, app-friendly content API across WordPress post types.
- Return embedded author/media/taxonomy fields plus Gutenberg `sections`.
- Expose taxonomy and CPT discovery endpoints for dynamic app layouts.
- Provide site branding payloads via `GET /site-identity`.
- Enforce API-key access and request-rate limiting.
- Accept contributor applications and citizen story submissions.
- Support editorial session login and moderation list/action endpoints.
- Provide operational health metadata via `GET /health`.
- Provide ad placement configuration payloads for app monetization UI.
- Ingest analytics events and expose editorial analytics summaries.
- Track contributor earnings and expose payout ledgers by application id.

## Delivery status checklist

### MVP-ready in plugin
- [x] Content feed API (`/posts`, `/posts/{id}`, `/categories`)
- [x] CPT discovery/sections endpoints (`/cpt-slugs`, `/cpt`, `/cpt-sections`)
- [x] Site identity endpoint (`/site-identity`)
- [x] API key protection + request throttling
- [x] Contributor application + citizen submission endpoints
- [x] Editorial login + moderation list/action endpoints
- [x] Health endpoint (`/health`)
- [x] SQL-backed storage tables for premium entitlements, analytics, and contributor earnings

### Future backend phases
- [ ] Webhook/event stream for external automation
- [ ] Rich analytics endpoints for product dashboards
- [ ] Advanced abuse protection and anomaly detection
- [ ] Fine-grained API key scopes and rotation policies
- [ ] Background job processing for heavier editorial workflows

---

## Flutter → Plugin endpoint mapping

| Flutter `WordPressService` method | Plugin endpoint |
|---|---|
| `getPosts(publication, page, perPage, categoryId, searchQuery, featuredOnly)` | `GET /posts` |
| `getAllPublicationsPosts()` | `GET /posts` (called per publication) |
| `getBreakingNews()` (sticky=true) | `GET /posts?sticky=true` |
| `getCategories(publication)` | `GET /categories` |
| `getCustomPostTypeSlugs(publication)` | `GET /cpt-slugs` |
| `getCustomPostTypeItems(publication, slug)` | `GET /cpt/{slug}` |
| `getCustomPostTypeSections(publication)` | `GET /cpt-sections` |
| `getPost(id, publication)` | `GET /posts/{id}` |
| `searchAllPublications(query)` | `GET /posts?search=…` |

---

## Database architecture (production path)

The plugin now provisions custom SQL tables (via activation + auto schema check):

- `wp_pe_premium_entitlements`
- `wp_pe_analytics_daily`
- `wp_pe_contributor_earnings`

These back:

- premium entitlement verification/status
- analytics event ingestion + summary aggregation
- contributor earnings credits + ledger retrieval

> Table prefix follows your WordPress prefix (example uses `wp_`).

---

## Endpoints

Base URL: `https://yoursite.com/wp-json/post-extractor/v1`

### `GET /types`
All public post types registered on the site.

---

### `GET /posts`

Mirrors `/wp/v2/posts` but works across **all post types** in one call.

| Param | Flutter source | Notes |
|---|---|---|
| `page` | `page` | Default 1 |
| `per_page` | `perPage` | Default 10 |
| `categories` | `categoryId` | Integer category ID |
| `search` | `searchQuery` | Full-text search |
| `sticky` | `featuredOnly` | `true` → sticky posts only |
| `status` | hardcoded `publish` | |
| `orderby` | hardcoded `date` | |
| `order` | hardcoded `desc` | |
| `post_type` | optional override | Filters to one type |
| `all` | optional override | `true` returns all matching posts in one response |
| `_embed` | always `true` | Param accepted; data always embedded |

**Pagination:** Requests where `page` is greater than `total_pages` return **HTTP 200** with `"posts": []` and unchanged `X-WP-Total` / `X-WP-TotalPages` headers (same as `/wp/v2` collections). `per_page` is clamped to 1–100.

Response fields now come from the core WordPress REST item shape (`/wp/v2/{type}/{id}?_embed=1`) and include custom registered REST fields (for example `class_list`, `rttpg_*`) when the theme/plugins expose them.

To mirror your native posts endpoint:

```bash
curl -H "X-PE-API-Key: <key>" \
  "https://yoursite.com/wp-json/post-extractor/v1/posts?post_type=post&per_page=50&page=1"
```

Or fetch all posts in one call:

```bash
curl -H "X-PE-API-Key: <key>" \
  "https://yoursite.com/wp-json/post-extractor/v1/posts?post_type=post&all=true"
```

Example response shape:

```json
{
  "total": 42,
  "total_pages": 5,
  "posts": [
    {
      "id": 1,
      "slug": "my-post",
      "type": "post",
      "status": "publish",
      "link": "https://site.com/my-post",
      "date": "2025-01-01T10:00:00+00:00",
      "modified": "2025-01-02T08:00:00+00:00",
      "sticky": false,
      "title":   { "rendered": "My Post Title" },
      "content": { "rendered": "<p>...</p>", "protected": false },
      "excerpt": { "rendered": "Short excerpt…", "protected": false },
      "author": 1,
      "featured_media": 7,
      "categories": [3],
      "tags": [12, 15],
      "_embedded": {
        "author": [{ "id": 1, "name": "Admin", "slug": "admin", "avatar_urls": { "96": "..." } }],
        "wp:featuredmedia": [{
          "id": 7,
          "source_url": "https://site.com/photo.jpg",
          "alt_text": "Hero",
          "media_details": {
            "width": 1920, "height": 1080,
            "sizes": {
              "full":         { "source_url": "...", "width": 1920, "height": 1080 },
              "medium_large": { "source_url": "...", "width": 768,  "height": 432  },
              "thumbnail":    { "source_url": "...", "width": 150,  "height": 150  }
            }
          }
        }],
        "wp:term": [
          [{ "id": 3, "name": "News", "slug": "news", "taxonomy": "category", "count": 12, "parent": 0 }]
        ]
      },
      "sections": [
        {
          "order": 0,
          "type": "core/heading",
          "label": "Heading",
          "html": "<h2>Welcome</h2>",
          "inner_text": "Welcome",
          "attrs": { "level": 2 },
          "children": []
        }
      ]
    }
  ]
}
```

---

### `GET /posts/{id}`
Same shape as `/posts` items, plus:
- `meta` — filtered post meta (no `_wp_*` internals)
- `acf` — ACF field groups + values (empty `{}` if ACF not installed)

---

### `GET /categories`
Mirrors `/wp/v2/categories`. Same fields `CategoryModel.fromWordPress()` reads.

| Param | Default |
|---|---|
| `per_page` | 20 |
| `orderby` | count |
| `order` | desc |
| `hide_empty` | true |

---

### `GET /cpt-slugs`
Auto-discovers REST-enabled custom post types, excluding core WP slugs.  
Returns: `["videos", "podcasts", "episodes"]`

---

### `GET /cpt/{slug}`
Items for one CPT. Same response shape as `/posts` items inside `items[]`.

---

### `GET /cpt-sections`
All CPTs × items in one round-trip. Equivalent to calling `getCustomPostTypeSections()`.

| Param | Default |
|---|---|
| `per_type` | 8 |
| `max_types` | 6 |

```json
[
  { "slug": "videos",   "label": "Videos",   "items": [ ... ] },
  { "slug": "podcasts", "label": "Podcasts", "items": [ ... ] }
]
```

---

### `GET /health`
Operational backend status for checks/monitoring.

```bash
curl -H "X-PE-API-Key: <key>" \
  "https://yoursite.com/wp-json/post-extractor/v1/health"
```

Example response:

```json
{
  "ok": true,
  "namespace": "post-extractor/v1",
  "plugin_version": "1.5.4",
  "timestamp_utc": "2026-04-23T20:10:11+00:00",
  "site_name": "My Afrika Magazine",
  "site_url": "https://myafrikamag.com/",
  "api_key_configured": true,
  "rate_limit_per_minute": 120,
  "editorial_configured": true,
  "extractable_types": ["post", "videos", "pe_citizen", "pe_contrib_app"],
  "extractable_total": 4
}
```

---

### `GET /editorial/contributor-applications`
Editorial list endpoint (requires `X-PE-Editorial-Token`).

Optional server-side filters:

| Param | Values | Notes |
|---|---|---|
| `status` | `all`, `pending`, `approved`, `rejected` | Default `all` |
| `q` | text | Matches applicant `name`, `email`, `publication` |

```bash
curl -H "X-PE-Editorial-Token: <token>" \
  "https://yoursite.com/wp-json/post-extractor/v1/editorial/contributor-applications?page=1&per_page=30&status=pending&q=mykasi"
```

---

### `GET /editorial/citizen-submissions`
Editorial list endpoint (requires `X-PE-Editorial-Token`).

Optional server-side filters:

| Param | Values | Notes |
|---|---|---|
| `status` | `all`, `pendingreview`, `verified`, `rejected` | Default `all` |
| `q` | text | Matches pitch `headline`, `location`, `publication` |

```bash
curl -H "X-PE-Editorial-Token: <token>" \
  "https://yoursite.com/wp-json/post-extractor/v1/editorial/citizen-submissions?page=1&per_page=30&status=verified&q=bulawayo"
```

---

### `POST /contributor-applications/{id}/cancel`
Cancels a pending application from the app and marks it as rejected by applicant.

Body (JSON):
- `email` (required)
- `reason` (optional)

### `POST /contributor-applications/{id}/undo-cancel`
Restores an app-cancelled application back to `pending` if still inside the configured undo window.

Body (JSON):
- `email` (required)

Notes:
- Undo availability is exposed in the application payload fields:
  - `cancelledByApplicant`
  - `cancelUndoUntil` (UTC ISO string)
  - `canUndoCancel`
- Undo window length is configurable in wp-admin:
  - **Settings → Post Extractor → Cancel undo window (seconds)** (default `600`).

---

### `GET /premium/entitlement`
Returns premium status for a device.

| Param | Required | Notes |
|---|---|---|
| `device_id` | yes | App-generated stable device id |

```bash
curl -H "X-PE-API-Key: <key>" \
  "https://yoursite.com/wp-json/post-extractor/v1/premium/entitlement?device_id=device_123"
```

### `POST /premium/entitlement/verify`
Stores purchase evidence and updates entitlement.

Body (JSON):

| Field | Required | Notes |
|---|---|---|
| `device_id` | yes | App device id |
| `platform` | yes | `android` or `ios` |
| `product_id` | yes | Store product id |
| `purchase_id` | yes | Transaction / purchase id |
| `purchase_token` | yes | Store verification token |
| `expires_at` | no | ISO timestamp for subscription expiry |

```bash
curl -X POST -H "Content-Type: application/json" \
  -H "X-PE-API-Key: <key>" \
  -d '{"device_id":"device_123","platform":"android","product_id":"newsbepa.premium.monthly","purchase_id":"GPA.1234","purchase_token":"token"}' \
  "https://yoursite.com/wp-json/post-extractor/v1/premium/entitlement/verify"
```

> Current implementation uses `verified_mode: server_stub` as scaffolding. Replace with Google Play / App Store server verification in production.

Production path:
- Configure **Premium verifier URL** in Settings → Post Extractor.
- Optional bearer auth via **Premium verifier bearer token**.
- Enable **Premium strict mode** to fail-closed if verifier is unavailable.
- Verifier should return JSON: `{ "ok": true, "active": true|false, "expires_at": "...", "verified_mode": "provider_google_apple" }`.

---

### `GET /ads/placements`
Monetization placement payloads for app surfaces.

Optional query:
- `publication`
- `placement`

### `POST /analytics/events`
Stores product analytics events (API key required).

Body (JSON):
- `event` (required)
- `publication` (optional)
- `format` (optional, default `article`)

### `GET /analytics/summary`
Editorial analytics summary endpoint (requires `X-PE-Editorial-Token`).

Query:
- `days` (1..365, default 30)

### `GET /contributors/earnings`
Returns contributor earnings ledger by application id.

Query:
- `application_id` (required)

### `POST /editorial/contributor-earnings/credit`
Credits contributor earnings entry (requires editorial token).

Body (JSON):
- `application_id` (required)
- `amount` (required, >0)
- `note` (optional)
- `story_id` (optional)

---

## Authentication

Send the API key as:
- Header: `X-PE-API-Key: <key>`
- Query param: `?api_key=<key>`

Generate a key at **Settings → Post Extractor** in wp-admin.

When **Require Authentication** is enabled (default), the mobile app **must** send the key on **every** request to `post-extractor/v1` (either form below). Logged-in WordPress editors can still call the API from the browser without the key; the app uses the key.

---

## Flutter — send the API key

This repository is the WordPress plugin only. Wire the key into **NewsBEPA** `WordPressService` (or any HTTP client) like this.

### Option 1 — Header `X-PE-API-Key` (recommended)

Using **package:http**:

```dart
import 'package:http/http.dart' as http;

Future<http.Response> fetchPosts({
  required String baseUrl, // e.g. https://news.example.com/wp-json/post-extractor/v1
  required String apiKey,
  Map<String, String> query = const {},
}) {
  final uri = Uri.parse('$baseUrl/posts').replace(queryParameters: query);
  return http.get(
    uri,
    headers: {
      'X-PE-API-Key': apiKey,
      'Accept': 'application/json',
    },
  );
}
```

Using **dio** (global header for all plugin calls):

```dart
import 'package:dio/dio.dart';

Dio createPostExtractorClient({
  required String baseUrl,
  required String apiKey,
}) {
  return Dio(
    BaseOptions(
      baseUrl: baseUrl,
      headers: {
        'X-PE-API-Key': apiKey,
        'Accept': 'application/json',
      },
    ),
  );
}

// Usage: client.get('/posts', queryParameters: {'page': 1, 'per_page': 10});
```

### Option 2 — Query parameter `api_key`

Use this if you cannot set custom headers on your stack (some proxies strip unknown headers). Merge `api_key` into the URL query for each request:

```dart
Uri withApiKey(Uri uri, String apiKey) {
  final q = Map<String, String>.from(uri.queryParameters);
  q['api_key'] = apiKey;
  return uri.replace(queryParameters: q);
}

// Example
final uri = withApiKey(
  Uri.parse('$baseUrl/posts').replace(queryParameters: {'page': '1', 'per_page': '10'}),
  apiKey,
);
```

Store `apiKey` per publication (or one global key) via `--dart-define`, flavors, or `flutter_secure_storage` — avoid committing it to git.

---

## Requirements

- WordPress 5.5+ (PHP 8.0+)
- ACF plugin (optional — for `acf` field in single post)

---

## Plugin structure

```
post-extractor/
├── post-extractor.php
└── includes/
    ├── class-post-extractor-api.php       ← REST routes (Flutter-compatible)
    ├── class-post-extractor-blocks.php    ← Gutenberg → sections[]
    ├── class-post-extractor-meta.php      ← custom fields + ACF
    └── class-post-extractor-settings.php ← admin settings + key generator
```
