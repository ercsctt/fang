# Epic: Local Development Infrastructure (e-eed1ea)

## Plan

Set up local development infrastructure using Docker Compose with MySQL, Redis, and Meilisearch. Configure environment and ensure seamless local development experience.

<!-- Add implementation plan here -->

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

### f-b479e9: Add Meilisearch to docker-compose
- Added Meilisearch v1.6 service with container name `fang-meilisearch`
- Port 7700 exposed for local access
- Uses `MEILISEARCH_KEY` env var (defaults to `masterKey` for local dev)
- Data persisted in `meilisearch-data` volume
- Healthcheck uses wget to hit `/health` endpoint (Meilisearch Alpine image includes wget)

### f-55fbd2: Update .env.example with Meilisearch config
- Added `MEILISEARCH_HOST=http://127.0.0.1:7700` and `MEILISEARCH_KEY=masterKey` after Redis section
- Scout is not currently installed, so no SCOUT_DRIVER update needed
- All docker service hosts already use 127.0.0.1 for local dev

### f-993dc9: Configure Laravel Scout with Meilisearch
- Installed `laravel/scout` v10.23 and `meilisearch/meilisearch-php` v1.16
- Published Scout config to `config/scout.php`
- Set default driver to `meilisearch` (reads from `SCOUT_DRIVER` env var)
- Added `SCOUT_DRIVER=meilisearch` to `.env.example`
- Added `Searchable` trait to `Product` and `ProductListing` models
- Implemented `toSearchableArray()` on both models:
  - Product: id, name, brand, description, category, canonical_category, subcategory
  - ProductListing: id, title, description, brand, category, barcode, retailer_id
- Added `SCOUT_DRIVER=collection` to `phpunit.xml` so tests don't require running Meilisearch instance
- **Key decision**: Use `collection` driver in tests to avoid external service dependency

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->