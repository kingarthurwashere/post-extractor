# Post Extractor API — WordPress Plugin

Extends the WordPress REST API with a cross-type posts feed, Gutenberg section data, ACF support, and CPT discovery — shaped for the **NewsBEPA Flutter app** (`WordPressService`). Add the API key in Dart as in [Flutter — send the API key](#flutter--send-the-api-key) when authentication is required.

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
| `_embed` | always `true` | Param accepted; data always embedded |

Response fields match WP REST `?_embed=true` exactly:

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
