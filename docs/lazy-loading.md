# Lazy Loading

The Media module supports Lazy Loading of images using low resolution image placeholders with a blur effect, generating accurate preview images using an amazingly small amount of data.

This is implemented using the [Gaussholder](https://github.com/humanmade/gaussholder) project.

Gaussholder takes a Gaussian blur and applies it to an image to generate a preview image. Gaussian blurs work as a low-pass filter, allowing us to throw away a lot of the data. We then further reduce the amount of data per image by removing the JPEG header and rebuilding it on the client side (this eliminates ~800 bytes from each image).

## Configuration

Lazy loading of images in post content is enabled by default. This behavior can be disabled by setting the `modules.media.gaussholder` property to `false` in your project's `composer.json`.

Lazy loading is enabled on a per image size basis, so you must configure the specific image sizes. This is done via the `image-sizes` key in the configuration:

```json
{
	"extra": {
		"altis": {
			"modules": {
				"media": {
					"gaussholder": {
						"image-sizes": {
							"large": 32
						}
					}
				}
			}
		}
	}
}
```

It's important to note that _if no image sizes are configured, lazy loading will not activate._ The keys are registered image sizes (plus `full` for the original size), with the value as the desired blur radius in pixels.

Be aware that for every size you add, a placeholder will be generated and stored in the database. If you have a lot of sizes, this will be a lot of data.

### Blur radius

The blur radius controls how much blur we use. The image is pre-scaled down by this factor, and this is really the key to how the placeholders work. Increasing radius decreases the required data quadratically: a radius of 2 uses a quarter as much data as the full image; a radius of 8 uses 1/64 the amount of data. (Due to compression, the final result will not follow this scaling.)

Be careful tuning this, as decreasing the radius too much will cause a huge amount of data in the body; increasing it will end up with not enough data to be an effective placeholder.

The radius needs to be tuned to each size individually. Facebook uses about 200 bytes of data for their placeholders, but you may want higher quality placeholders. There's no ideal radius, as you simply want to balance having a useful placeholder with the extra time needed to process the data on the page.

Gaussholder includes a CLI command to help you tune the radius: pick a representative attachment or image file and use `wp gaussholder check-size <id_or_image> <radius>`. Adjust the radius until you get to roughly 200B, then check against other attachments to ensure they're in the ballpark.

Note: changing the radius requires regenerating the placeholder data. Run `wp gaussholder process-all --regenerate` after changing radii or adding new sizes.
