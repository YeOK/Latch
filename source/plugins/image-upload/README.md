# Image upload (R2)

First-party Latch plugin for **post images** via direct browser upload to **Cloudflare R2**. Public URLs use your R2 custom domain (e.g. `images.latch.network`). Nothing is stored in Latch `storage/`.

## Setup

1. Create an R2 bucket and API token with **Object Read & Write** on that bucket.
2. Attach a **custom domain** (e.g. `images.latch.network`) to the bucket.
3. Configure **CORS** on the bucket so browsers can `PUT` from your forum origin:

```json
[
  {
    "AllowedOrigins": ["https://latch.network"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

4. Add credentials to `config/local.php` (not the admin UI):

```php
'plugins' => [
    'image_upload' => [
        'account_id' => 'YOUR_CLOUDFLARE_ACCOUNT_ID',
        'access_key_id' => 'YOUR_R2_ACCESS_KEY',
        'secret_access_key' => 'YOUR_R2_SECRET',
        'bucket' => 'latch-forum-images',
        'public_host' => 'images.latch.network',
        'max_mb' => 8,
        'key_prefix' => 'forum/',
    ],
],
```

5. Enable the plugin:

```bash
php bin/latch plugin-audit image-upload
php bin/latch plugin enable image-upload
php bin/latch maintenance --clear-cache
```

## Usage

Signed-in members see an **Image** button in the compose toolbar. Uploaded images are inserted as markdown `![](https://images…/forum/userId/uuid.ext)`.

Pasting arbitrary image URLs from other hosts is blocked on save.

## Security

- Presign route requires login + CSRF.
- Only JPEG, PNG, GIF, WebP up to `max_mb`.
- Object keys are scoped per user under `key_prefix`.
- R2 API secrets stay in `local.php` only.