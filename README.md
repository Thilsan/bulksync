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
| One-word title | The visible title is a bare model name — "NOBLETON", "Altra" |
| No tags | The product carries none |

Each page scores out of 100, with points deducted per issue. Lengths are
counted in characters rather than bytes, so Arabic content is not wrongly
reported as too long.

**Collections are audited too.** Collection pages rank for category searches
("cabin luggage qatar") while product pages compete for model names, so they
carry a share of search demand out of all proportion to their number. The image,
alt-text and tag checks do not apply to them and are left off rather than passing
trivially. Filter the table with the Everything / Products / Collections tabs.

**Duplicate clusters** are shown above the table: which pages share a meta title
or description, and therefore split the same search result. A flat list shows a
row without showing what it clashes with, which is not enough to fix it.

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

## Collection SEO

Collection pages rank for the category somebody types — "cabin luggage qatar" —
while a product page competes for a model name. There are only a few dozen of
them and they carry a share of search demand out of all proportion to their
number.

They need their own module because a collection has no SKU to look it up by and
no photograph to write from: the products inside it are the evidence of what the
page is about, so the generator reads a sample of their titles, types and brands.

Start a run over every collection from **Collection SEO**, or over just the ones
an audit flagged with **Fix N collections** on the audit results screen. Review
everything before anything is written; the body description is only replaced when
you tick to replace it, since a collection description is often written by hand.

## Merge candidates

The duplicate panel reports a symptom. This reports the cause: Shopify products
sharing a title, which are usually one product split into several by colour or
size. While they stay split they divide their own ranking between two URLs, and
their generated meta titles will keep matching — the thing that distinguishes
them is normally colour, which never goes into a meta title.

Consecutive SKUs (`SFR207ACC01095` / `…096`) are called out as the strongest
signal. The check is deliberately conservative, because a false "these are the
same product" invites a merge that would lose a real one.

## Almost Ranking

Queries where a page sits between position 11 and 30 — page two, where almost
nobody looks. Google already considers those pages relevant enough to show, so
moving position 14 to position 8 is a fraction of the work of ranking something
new and worth several times the traffic.

Sorted by impressions, so the biggest prize for the same effort is first. Terms
with fewer than ten impressions are dropped: position 12 shown twice is noise.
Feed a row's search term into the Target search terms box on the AI Content
Generator or Collection SEO.

Requires a Search Console site on the store.

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

Where a **Search Console site** is also set on the store, the same comparison
records impressions, clicks, click-through rate and average position. That is the
better instrument for judging a rewritten meta description: it changes how many
people click what they were already being shown, before it changes where the page
ranks, and sessions alone cannot tell those two explanations apart.

Either source alone is enough to produce a reading. Positions are averaged by
impression, so a URL shown twice does not weigh as much as one shown ten thousand
times, and a page shown but never clicked counts as measured — "shown and
ignored" is a finding, not an absence of one.

Requires a GA4 property ID and/or a Search Console site on the store
(Stores → edit), plus the service-account key described under
`services.ga4.credentials` / `services.search_console.credentials`. The service
account must be granted access separately on each side — Analytics by property,
Search Console by site.

## Keyword-grounded generation

Left to itself the model writes from the photograph: it describes what the
product looks like and has no idea what anyone is searching for. Two sources fix
that, and both are optional.

- **Target search terms** on the generation form, applied to every product in the
  batch.
- **Real queries from Search Console** for that exact product page, when the store
  has a site configured — read over the last 90 days, ten at most.

They are merged case-insensitively so a merchant's spelling and Google's do not
both go in and read as emphasis. The prompt is told these are facts about demand
rather than about the product: a term naming something the product is not is
ignored, and no term overrides the accuracy or colour-neutral rules. With neither
source the prompt behaves exactly as it did before.

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
