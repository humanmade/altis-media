# Global Media Library

The Global Media Library feature is off by default but can be enabled via the Altis config like so:

```json
{
    "extra": {
        "altis": {
            "modules": {
                "media": {
                    "global-media-library": true
                }
            }
        }
    }
}
```

When browsing and selecting media all the files will be sourced from the dedicated global content site on the network. This means an
image only needs to be uploaded once and it can be used anywhere.

**Note**: You must run the `wp altis migrate` command to create
the [Global Content Repository site](docs://core/global-content-repository.md) for this feature to function.

Alternatively the media library config option can be set to any WordPress site URL that has a public REST API.

```json
{
    "extra": {
        "altis": {
            "modules": {
                "media": {
                    "global-media-library": "https://example.com"
                }
            }
        }
    }
}
```

## Disallowing Local Media

Along with the Global Media Library you can optionally switch off each site's Local Media Library to force using the global library.

To do so you can add the following config:

```json
{
    "extra": {
        "altis": {
            "modules": {
                "media": {
                    "local-media-library": false
                }
            }
        }
    }
}
```

Alternatively you can also use the `amf/allow_local_media` filter to conditionally control which sites can use Local Media and which
cannot.

```php
add_filter( 'amf/allow_local_media', function () : bool {
    // Local for a custom site meta entry.
    if ( get_site_meta( get_current_site_id(), 'allow_local_media' ) ) {
        return true;
    }

    return false;
} );
```
