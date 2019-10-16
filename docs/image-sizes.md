# Images Sizes

You can define additional image thumbnail sizes with `add_image_size`.

The custom thumbnail sizes should be declared in the callback function to the `after_setup_theme` action.

Example:
```php
add_action( 'after_setup_theme', __NAMESPACE__ . '\\theme_setup' );
function theme_setup() {
    add_image_size( 'category-thumb', 300, 9999 ); // 300 pixels wide (and unlimited height)
}
```

You can find more info at the [WordPress Developer Reference](https://developer.wordpress.org/reference/functions/add_image_size/) and [WordPress developer guide](https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/#add-custom-featured-image-sizes)

## Hooks and filters

You can add custom image sizes by hooking in on this filter.

### `image_size_names_choose`

Runs before rendering the list of available thumbnail sizes in the sidebar of the media edit screen. Used in conjuction with `add_image_size` 

```php
    add_filter( 'image_size_names_choose', function ( array $sizes ) {
        $sizes['category-thumb'] = __( 'Category Thumb' );
        return $sizes;
    });
```

You can find the [full documentation for this filter here](https://developer.wordpress.org/reference/hooks/image_size_names_choose/).

## Dynamically defined with a size array

There are scenarios where you might want to define the thumbnail size via an array:

`$thumb_url = wp_get_attachment_image_src( $attachment_id, [ 900, 450 ], true );`

This will generate a thumbnail on the fly with the dimensions defined by an array of width and height [w, h] values in pixels
