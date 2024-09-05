# Dynamic Images

The Media module supports dynamic image resizing via a microservice called [Tachyon](https://github.com/humanmade/tachyon). Tachyon allows arbitrary image resizes to be done on the fly, supports lossless image compression and many file formats such as WebP for supported browsers.

By default the Tachyon service is enabled. It can be explicitly disabled by setting the `modules.media.tachyon` configuration property to `false`, though this is highly discouraged.

All images rendered on the front end of the website are automatically modified to use the Tachyon service URLs. Via lossless compression and automatic format conversion, image sizes can be reduced up to 70% with no degradation in quality.

## Responsive Images

A major advantage of using Tachyon for all site images is the ability to generate more accurate `srcset` that respect a registered image size's aspect ratio rather than relying on multiple other image sizes being registered at the same aspect ratio.

This is made possible using Tachyon's `zoom` parameter. By default all `srcset` attributes will be generated with 2x, 1.5x, 0.5x and 0.25x alternatives. The available modifiers can be configured through the `composer.json` file:

```json
{
	"extra": {
		"altis": {
			"modules": {
				"media": {
					"smart-media": {
						"srcset-modifiers": [ 2, 1.5, 0.5, 0.25 ]
					}
				}
			}
		}
	}
}
```

By using the `zoom` parameter Tachyon also applies a sliding scale of quality for output images so that larger 2x images for example don't incur a huge increase in file size. Images displayed on a web page at a smaller size than their natural size do not need to be as high quality to appear crisp.

## Custom Image Sizes

Image URLs in Altis are converted to Tachyon URLs by default in all contexts (file paths remain the same). You can convert any image URL under the uploads directory to a Tachyon URL using the `tachyon_url()` function.

```php
// Query parameters to append to the URL.
$args = [
	'lb' => '400,400',
	'background' => 'white',
];
// Generate a Tachyon URL for $image_src and $args.
$url = tachyon_url( $image_src, $args );
```

## Default Settings

By default in Altis all Tachyon URLs for image sizes that result in a cropped image will have smart cropping applied. You can apply additional default modifications using the following filter:

**`tachyon_pre_args: array $args [, array $downsize_args]`**

- `$args` is an array of arguments used to generate the Tachyon URL query string.
- `$downsize_args` if available provides additional context such as the requested image size name and attachment ID.

## Query Args Reference

The following query string arguments can be applied to any image delivered by Tachyon.

| URL Arg | Type | Description |
|---|----|---|
|`w`|Number|Max width of the image.|
|`h`|Number|Max height of the image.|
|`quality`|Number, 0-100|Image quality.|
|`resize`|String, "w,h"|A comma separated string of the target width and height in pixels. Crops the image.|
|`crop_strategy`|String, "smart", "entropy", "attention"|There are 3 automatic cropping strategies for use with `resize`: <ul><li>`attention`: good results, ~70% slower</li><li>`entropy`: mediocre results, ~30% slower</li><li>`smart`: best results, ~50% slower</li>|
|`gravity`|String|Alternative to `crop_strategy`. Crops are made from the center of the image by default, passing one of "north", "northeast", "east", "southeast", "south", "southwest", "west", "northwest" or "center" will crop from that edge.|
|`fit`|String, "w,h"|A comma separated string of the target maximum width and height. Does not crop the image.|
|`crop`|Boolean\|String, "x,y,w,h"|Crop an image by percentages x-offset, y-offset, width and height (x,y,w,h). Percentages are used so that you don’t need to recalculate the cropping when transforming the image in other ways such as resizing it. You can crop by pixel values too by appending `px` to the values. `crop=160px,160px,788px,788px` takes a 788 by 788 pixel square starting at 160 by 160.|
|`zoom`|Number|Zooms the image by the specified amount for high DPI displays. `zoom=2` produces an image twice the size specified in `w`, `h`, `fit` or `resize`. The quality is automatically reduced to keep file sizes roughly equivalent to the non-zoomed image unless the `quality` argument is passed.|
|`webp`|Boolean, 1|Force WebP format.|
|`lb`|String, "w,h"|Add letterboxing effect to images, by scaling them to width, height while maintaining the aspect ratio and filling the rest with black or `background`.|
|`background`|String|Add background color via name (red) or hex value (%23ff0000). Don't forget to escape # as `%23`.|

## Limitations

Image files that contain dimensions as part of the file name, e.g.
`my-image-100x200.png` can cause issues for Tachyon. We recommend you rename all
images to remove dimensions from their file names, as well as any other special
characters. You can
use [this tool](https://github.com/humanmade/rename-images-command) as a
framework to convert file names.
