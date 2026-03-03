# Private Uploads

Private Uploads ensures that media files attached to unpublished posts are not publicly accessible via their S3 URLs. This is essential for sites handling embargoed or sensitive content such as financial results or pre-release announcements.

## Configuration

The feature is enabled by default. To disable it, set the following in your `composer.json`:

```json
{
    "extra": {
        "altis": {
            "modules": {
                "media": {
                    "private-uploads": false
                }
            }
        }
    }
}
```

## How It Works

### Automatic Privacy

Media privacy is determined automatically based on the parent post's status:

- **Published parent post**: Attachments are **public** (accessible via direct S3 URL).
- **Draft, pending, or other non-published parent**: Attachments are **private** (accessible only via time-limited presigned URLs).
- **Unattached media** (no parent post): Defaults to **private**.
- **Global Media Library**: Always **public**, regardless of other settings.

When a post is published, all its attachments are automatically updated to public. When a post is unpublished (moved back to draft, pending, etc.), its attachments are set to private.

### Manual Override

Each attachment has an optional privacy toggle available on the attachment edit screen:

- **Auto** (default): Privacy follows the parent post status rules described above.
- **Private**: The file is always private, even if the parent post is published.
- **Public**: The file is always public, even if the parent post is unpublished.

Attachments with a manual override are not affected by post status transitions.

### Media Library

A "Privacy" column in the media library list view shows the current effective privacy status of each attachment with a lock (private) or globe (public) icon.

## Presigned URLs

When an attachment is private, WordPress automatically serves presigned URLs instead of direct S3 URLs. These URLs are time-limited (6 hours by default) and grant temporary read access. This applies to:

- `wp_get_attachment_url()`
- `wp_get_attachment_image_src()`
- Image srcsets

The presigned URL expiry can be customised via the `s3_uploads_private_attachment_url_expiry` filter:

```php
add_filter( 's3_uploads_private_attachment_url_expiry', function ( $expiry, $post_id ) {
    return '+1 hour';
}, 10, 2 );
```

## Technical Details

This feature works by integrating with the S3 Uploads plugin (`humanmade/s3-uploads`):

- The `s3_uploads_is_attachment_private` filter determines whether an attachment should be private.
- S3 object ACLs are set to either `private` or `public-read` accordingly.
- Post status transitions trigger bulk ACL updates for all child attachments.
- Manual overrides are stored in the `_s3_privacy` post meta field.
