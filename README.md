# BulkSync — Shopify Bulk Image Uploader

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
