# SiteScouter

The backend service for the [Newsfeed Aggregator](../README.md) mobile app, written in PHP. SiteScouter's job is to find and collect content from news sites and blogs, and serve it to the app in a clean, consistent format so the client doesn't have to deal with the messy details of each source.

> **Status:** Early development. Sections marked **TODO** need to be filled in as the implementation takes shape.

## What it does

- Scouts configured sites and feeds (RSS/Atom) for new articles
- Normalizes each article into one consistent shape: source, publish time, title, and snippet
- Exposes the aggregated feed to the mobile app over a JSON API

**Planned**

- Source management (add, remove, and list sites)
- Scheduled fetching and caching so the app gets fast responses
- Search across stored articles
- OPML import and export
- De-duplication of articles that appear in more than one source

## Tech stack

- PHP 8.1 or newer
- [Composer](https://getcomposer.org) for dependencies
- **TODO:** framework (if any), database (e.g. SQLite or MySQL), and feed-parsing library

## Getting started

### Prerequisites

- PHP 8.1+ with the extensions you rely on (typically `curl`, `mbstring`, `xml`, `pdo`)
- Composer

Check your setup:

```bash
php -v
composer -V
```

### Install

```bash
cd sitescouter
composer install
```

### Configure

Copy the example environment file and edit it:

```bash
cp .env.example .env
```

On Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

**TODO:** list the settings your app reads. Typical examples:

| Variable | Description |
|---|---|
| `APP_ENV` | `local` or `production` |
| `DB_DSN` | Database connection string |
| `FETCH_INTERVAL` | How often sources are refreshed (minutes) |

### Run locally

Using PHP's built-in server:

```bash
php -S 0.0.0.0:8000 -t public
```

The API is then available at `http://localhost:8000`.

## Connecting the mobile app

The Android emulator can't reach your computer through `localhost`. In the app, point the API base URL at:

```
http://10.0.2.2:8000
```

On a physical device with Expo Go, use your computer's LAN IP instead (for example `http://192.168.1.20:8000`), and make sure both are on the same network. You may also need to allow PHP through Windows Firewall.

## API

**TODO:** document your real endpoints. A starting template:

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/articles` | List aggregated articles (newest first) |
| `GET` | `/sources` | List configured sources |
| `POST` | `/sources` | Add a source |

Example article response:

```json
{
  "id": "1",
  "source": "TechCrunch",
  "time": "2026-11-01T10:30:00Z",
  "title": "Example headline",
  "snippet": "A short summary of the article."
}
```

This matches the `Article` shape the mobile app already uses (`id`, `source`, `time`, `title`, `snippet`), so the app can consume it with minimal changes. The app currently shows `time` as a relative string like "12m ago", so either format it on the client from the timestamp or have the API return it.

## Project structure

**TODO:** update to match your layout. A typical one:

```
sitescouter/
├── public/          # Web root (index.php entry point)
├── src/             # Application code
├── config/          # Configuration
├── storage/         # Cache, logs, local database
├── composer.json
└── .env.example
```

## Roadmap

- [ ] Fetch and parse RSS/Atom feeds
- [ ] Store articles
- [ ] JSON API consumed by the mobile app
- [ ] Source management
- [ ] Scheduled refresh
- [ ] Search
- [ ] OPML import/export

## License

Not yet specified.