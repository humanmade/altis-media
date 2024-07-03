# Lazy Loading

WordPress provides lazy loading of images by default as long as `width` and `height` attributes are present on an `img` tag. See
the [Lazy Loading announcement](https://make.wordpress.org/core/2020/07/14/lazy-loading-images-in-5-5/) for more details.

## Lazy Loading Inline Frames

Inline frames are lazy-loaded by default using
the [browser-level `loading` attribute](https://html.spec.whatwg.org/multipage/iframe-embed-object.html#attr-iframe-loading).
Only `<iframe>` tags with both `width` and `height` attributes present will be lazy-loaded to avoid a negative impact on layout
shifting. Embedded `<iframe>` tags provided via oEmbed in content run through `the_content`, `the_excerpt` or `widget_text_content`
filters will have the `loading="lazy"` attribute added by default where the web service has provided a `width` and `height`
attribute.

You can customize whether and how inline frames are lazy loaded using the `wp_lazy_loading_enabled` filter. For example, to disable
lazy-loading of inline frames from post content entirely, you could use the following code:

```php
add_filter( 'wp_lazy_loading_enabled', function( $default, $tag_name, $context ) {
    if ( $tag_name === 'iframe' && $context === 'the_content'  ) {
        return false;
    }

    return $default;
}, 10, 3 );
```

You can also use the `wp_iframe_tag_add_loading_attr` filter to customize a specific `<iframe>` tag. For example, if you wanted to
disable lazy-loading on inline frames from a specific provider, like YouTube, you could use the following code:

```php
add_filter( 'wp_iframe_tag_add_loading_attr', function( $value, $iframe, $context ) {
    if ( $context === 'the_content' && false !== strpos( $iframe, 'youtube.com' ) ) {
        return false;
    }

    return $value;
}, 10, 3 );
```
