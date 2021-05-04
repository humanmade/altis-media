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

When browsing and selecting media all the files will be sourced from the dedicated global content site on the network. This means an image only needs to be uploaded once and it can be used anywhere.

**Note**: You must run the `wp altis migrate` command to create the [Global Content Repository site](docs://core/global-content-repository.md) for this feature to function.

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
