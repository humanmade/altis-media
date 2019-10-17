# Defining Image Sizes

Altis provides some default image sizes, that you can use to render images in the theme.

- Thumbnail
- Medium
- Large
- Post Thumbnail
- Full

You can set their dimensions under Settings > Media, or keep the defaults.

[screenshot of media settings]

A lot of times, you'll find that you need additional image sizes for different contexts, depending on your theme's design.

You can define additional image sizes with `add_image_size`.

The custom image sizes should be declared in the callback function to the `after_setup_theme` action.

Example:
```php
add_action( 'after_setup_theme', __NAMESPACE__ . '\\theme_setup' );
function theme_setup() {
	// Set the image size by resizing the image proportionally (without distorting it):
	add_image_size( 'custom-size', 220, 180 ); // 220 pixels wide by 180 pixels tall, soft proportional crop mode
	
	// Set the image size by cropping the image (not showing part of it):
	add_image_size( 'custom-size', 220, 180, true ); // 220 pixels wide by 180 pixels tall, hard crop mode
	
	// Set the image size by cropping the image and defining a crop position:
	add_image_size( 'custom-size', 220, 220, array( 'left', 'top' ) ); // Hard crop left top
}
```

When setting a crop position, the first value in the array is the x axis crop position, the second is the y axis crop position.

This will *not* add a UI for these named sizes under Settings > Media.

You can find more info in the [WordPress developer guide](https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/#add-custom-featured-image-sizes)

## Using The Custom Sizes

Once you've defined custom image sizes, there are different ways you can use them.

You can display a post thumbnail: `the_post_thumbnail( 'custom-size` )`

You can display any uploaded image by its attachment ID: `wp_get_attachment_image_src( 1 , 'custom-size' )`

### `image_size_names_choose`

This filter allows you to make your custom image sizes available for selection in the admin.

Runs before rendering the list of available image sizes in the sidebar of the media edit screen. Used in conjuction with `add_image_size` 

```php
add_filter( 'image_size_names_choose', function ( array $sizes ) : array {
	$sizes['category-thumb'] = __( 'Category Thumb' );
	return $sizes;
});
```

[screenshot of media popup]

You can find the [full documentation for this filter here](https://developer.wordpress.org/reference/hooks/image_size_names_choose/).

## Dynamically Defined With A Size Array

There are scenarios where you might want to define the image size via an array:

```php
$thumb_url = wp_get_attachment_image_src( $attachment_id, [ 900, 450 ], true );`
```

This will generate an image on the fly with the dimensions defined by an array of width and height [w, h] values in pixels.

This function doesn't allow setting the crop values though.
