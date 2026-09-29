# BulkSync — Ai Ecommerce Studio

Upload bulk product images from OneDrive to Shopify by matching filenames to product SKUs.

## Quick Start

```bash
composer install
cp .env.example .env
# Edit .env: set DB_*, SHOPIFY_*, ONEDRIVE_* values
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
# Open http://localhost:8000
```

**Default login:** `admin@bulksync.local` / `password`

---

## Configuration

Set credentials in the **Settings** page, or directly in `.env`.

### Shopify

| Variable | Description |
|---|---|
| `SHOPIFY_DOMAIN` | Store domain e.g. `mystore.myshopify.com` |
| `SHOPIFY_ACCESS_TOKEN` | Private app access token (`shpat_...`) |

Create a Shopify Private App → Shopify Admin → Apps → Develop apps → Create app → API scopes: `read_products`, `write_products`.

### Microsoft OneDrive

| Variable | Description |
|---|---|
| `ONEDRIVE_TENANT_ID` | Azure AD Tenant ID (use `common` for personal accounts) |
| `ONEDRIVE_CLIENT_ID` | Azure App Registration Client ID |
| `ONEDRIVE_CLIENT_SECRET` | Azure App Registration Client Secret |

Azure setup:
1. Azure Portal → Azure Active Directory → App registrations → New registration
2. API permissions → Microsoft Graph → Application → `Files.Read.All` → Grant admin consent
3. Certificates & secrets → New client secret → copy value

---

## How SKU Matching Works

Name your image files exactly as the Shopify SKU (without extension):

```
ABC-123.jpg    →  finds Shopify variant with SKU "ABC-123"
SHIRT-RED-M.png →  finds Shopify variant with SKU "SHIRT-RED-M"
```

1. Share your OneDrive folder with **"Anyone with the link can view"**
2. Paste the share link in the New Upload form
3. The app scans the folder, matches by SKU, resizes, and uploads

### Image Size Options

| Option | Max dimension | Quality | Typical output |
|---|---|---|---|
| Thumbnail | 600px | 70% | 50–150 KB |
| Small | 800px | 75% | 100–250 KB |
| Medium | 1200px | 80% | 200–500 KB |
| Large | 2000px | 85% | 300–800 KB |

All images are automatically compressed to stay under **1 MB**.

---

## SEO Audit

Scans every product in the store and reports what is missing or wrong about the
fields a search engine reads. Read-only — nothing is written back to Shopify.
Fixes are made in the **AI Content Generator**, which already writes meta titles,
meta descriptions and image alt text.

| Check | Raised when |
|---|---|
| No meta title / meta description | The `global.title_tag` / `global.description_tag` metafield is empty |
| Duplicate meta title / description | Two or more products share one, ignoring case and padding |
| Over/under length | Title outside 30–60 characters, description outside 70–160 |
| Images without alt text | Any product image has an empty `altText` |
| No images | The product has none at all |
| Thin description | Under 200 characters of body copy |
| No tags | The product carries none |

Each product scores out of 100, with points deducted per issue. Lengths are
counted in characters rather than bytes, so Arabic content is not wrongly
reported as too long.

Audits are pruned daily: the ten most recent per person are kept, and older ones
past 90 days are deleted with their rows.

### Fixing what it finds

**Fix N with AI** on the results screen sends the rows currently filtered on
screen to the AI Content Generator. Filter to one issue first — the button
always acts on exactly what the table is showing.

It stops at generation. Nothing reaches Shopify until someone opens the review
screen and pushes, which is what stops one bad meta description shipping to two
hundred products. Up to 500 products per press; generation is billed per
product, so larger sets are done in batches on purpose.

Products whose first variant has no SKU are skipped and counted in the result
message — the generator can only look products up by SKU.

## SEO Impact

Every push from the AI Content Generator is recorded. Thirty-five days later a
scheduled job asks Google Analytics how many **organic** sessions that product's
URL had in the 28 days before the push, against the 28 days after, and the
Impact Report shows the difference.

The settle period is deliberately longer than the window — Google has to recrawl
the page before a rewritten title can change anything, so measuring sooner would
mostly measure the crawl delay. Paid search is excluded, and a URL with no
organic sessions on either side is reported as *no data* rather than as a flat
result.

Requires a GA4 property ID on the store (Stores → edit) and the service-account
key described under `services.ga4.credentials`.

---

## Apache vhost (production)

```apache
<VirtualHost *:80>
    ServerName bulksync.local
    DocumentRoot /opt/homebrew/var/www/bulk/public
    <Directory /opt/homebrew/var/www/bulk/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add `127.0.0.1 bulksync.local` to `/etc/hosts`.
