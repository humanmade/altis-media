# Defining Image Sizes

Altis provides some default image sizes, that you can use to render images in the theme.

- Thumbnail
- Medium
- Large
- Post Thumbnail
- Full

They have default dimensions, but you can override these either with code or in the admin, under [Settings > Media](internal://admin/options-media.php).

![Media Settings](https://user-images.githubusercontent.com/30460/67079140-8b846000-f18a-11e9-8387-038b594f19aa.png)

A lot of times, you'll find that you need custom image sizes for different contexts, depending on your theme's design.

You can define custom image sizes with the `add_image_size()` function.

This function accepts the following parameters:

```php
 * @param string     $name   Image size identifier.
 * @param int        $width  Optional. Image width in pixels. Default 0.
 * @param int        $height Optional. Image height in pixels. Default 0.
 * @param bool|array $crop   Optional. Whether to crop images to specified width and height or resize.
```
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

This will *not* add a UI for these named sizes under Settings > Media.

### Cropping

Cropping behavior for the image size is dependent on the value of $crop:
1. If false (default), images will be scaled, not cropped.
2. If an array in the form of `[ x_crop_position, y_crop_position ]`:
    - x_crop_position accepts 'left' 'center', or 'right'.
    - y_crop_position accepts 'top', 'center', or 'bottom'.
    Images will be cropped to the specified dimensions within the defined crop area.
 3. If true, images will be cropped to the specified dimensions using center positions.
 
You can find more info in the [WordPress developer guide](https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/#add-custom-featured-image-sizes)

## Using The Custom Sizes

Once you've defined custom image sizes, there are different ways you can use them.

You can display a post thumbnail: `the_post_thumbnail( 'custom-size` );`

You can display any uploaded image by its attachment ID: `wp_get_attachment_image_src( 1 , 'custom-size' );`

## Hooks And Filters

### `image_size_names_choose`

This filter allows you to make your custom image sizes available for selection in the admin.

Runs before rendering the list of available image sizes in the sidebar of the media edit screen. Used in conjuction with `add_image_size` 

```php
add_filter( 'image_size_names_choose', function ( array $sizes ) : array {
	$sizes['category-thumb'] = __( 'Category Thumb' );
	return $sizes;
});
```

![Media Editor](https://user-images.githubusercontent.com/30460/67079152-93dc9b00-f18a-11e9-9797-5075210affad.png)

You can find the [full documentation for this filter here](https://developer.wordpress.org/reference/hooks/image_size_names_choose/).

## Dynamically Defined With A Size Array

In cases where a specific image size might only be used once you can define the image size via an array of width and height values in pixels:

```php
$thumb_url = wp_get_attachment_image_src( $attachment_id, [ 900, 450 ], true );`
```


**NOTE**: This method of defining dimensions currently does not support cropping.
