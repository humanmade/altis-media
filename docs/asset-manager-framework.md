# Asset Manager Framework

The Asset Manager Framework (AMF) enables using external providers such as a Digital Asset Manager (DAM), another WordPress website, or a central site within a Multisite installation. The [Global Media Library feature](./global-media-library.md) is built on top of this framework.

It handles the necessary integration with WordPress (Ajax endpoints and Backbone components) leaving you to focus on just the server-side API connection to your media source.

The intention is that media provided by any external source will become a seamless part of your site's media library.

## Loading AMF

AMF is loaded automatically when using the Global Media Library feature, however if you would like to use the framework without the global media library you can manually load it using the function `Altis\Media\load_amf()`:

```php
add_action( 'plugins_loaded', function () {
    Altis\Media\load_amf();
}, 9 );
```

## Implementation

There are two main aspects to the framework.

1. Allow the media manager grid to display external items which are not attachments on the current site.
2. Subsequently create a local attachment for an external item when it's selected for use.

The design decision behind this is that allowing for external items to be browsed in the media manager is quite straight forward, but unless each item is associated with a local attachment then most of the rest of WordPress breaks when you go to use an item.

Asset Manager Framework instead allows external media items to be browsed in the media library grid, but as soon as an item is selected for use (eg. to be inserted into a post or used as a featured image), an attachment is created for the media item, and this gets returned by the media manager.

The actual media file does not get sideloaded into WordPress - it intentionally remains at its external URL. The correct external URL gets referred to as necessary, while a local object attachment is maintained that can be referenced and queried within WordPress.

## Integration

There are four steps needed to integrate a media provider using the Asset Manager Framework:

1. Create a provider which extends the `AssetManagerFramework\Provider` class and implements its `get_id()`, `get_name()` and `request()` methods.
2. Process the response from the external media provider by creating an array of `AssetManagerFramework\Media` objects (or objects that extend that class) and setting values on each according to the response data.
3. Return an `AssetManagerFramework\MediaList` object with the array of `AssetManagerFramework\Media` objects as its first parameter.
2. Hook into the `amf/register_providers` action to register your provider for use.

Here's a basic example of a provider which supplies images from unsplash.com:

```php
use AssetManagerFramework\Image;
use AssetManagerFramework\Provider;
use AssetManagerFramework\ProviderRegistry;
use AssetManagerFramework\MediaList;

class UnsplashProvider extends Provider {

    public function get_id() {
        return 'unsplash';
    }

    public function get_name() {
        return __( 'Unsplash Media' );
    }

    /**
     * Use the query arguments to request files from unsplash.com.
     *
     * @param array $args The WP_Query args array.
     * @return MediaList
     */
	protected function request( array $args ) : MediaList {

        $url = 'https://api.unsplash.com/photos';

        // Map WP query args to unsplash API arguments.
        $url = add_query_arg( [
            'page' => $args['paged'] ?: 1,
            'per_page' => $args['posts_per_page'],
            'order_by' => $args['orderby'] === 'desc' ? 'latest' : 'oldest',
        ], $url );

        // Fetch the images.
        $response = wp_remote_get( $url, [
            'headers' => [
                'Accept-Version' => 'v1',
                'Authorization' => 'Client-ID <Unsplash API Client ID>',
            ],
        ] );

        $data = json_decode( wp_remote_retrieve_body( $response ) );

        // Map images in the response to `Image` instances.
        $items = [];
        foreach ( $data as $image ) {
            $item = new Image( $image->id, 'image/jpeg' );

            $item->set_url( $image->urls->raw );
            $item->set_title( $image->description );
            $item->set_width( $image->width );
            $item->set_height( $image->height );

            // Add additional data including sizes, file name etc...

            $items[] = $item;
        }

		return new MediaList( ...$items );
	}

}

// Register the provider.
add_action( 'amf/register_providers', function ( ProviderRegistry $provider_registry ) {
	$provider_registry->register( new UnsplashProvider() );
} );
```

For a more complete example using Unsplash see the [AMF Unsplash integration plugin](https://github.com/humanmade/amf-unsplash) for reference.

### Dynamic Image Resizing

AMF provides an interface to enable dynamic image resizing for your provider classes. The Global Media Library uses this to deliver assets via Tachyon out of the box.

To make a provider that can resize assets on the fly it needs to implement the `Resize` interface:

```php
use UnsplashProvider;
use AssetManagerFramework\Interfaces\Resize;

class ResizingUnsplashProvider extends UnsplashProvider implements Resize {

    public function resize( WP_Post $attachment, int $width, int $height, $crop = false ) : string {

        $base_url = wp_get_attachment_url( $attachment->ID );

        $query_args = [
            'w' => $width,
            'h' => $height,
            'fit' => $crop ? 'crop' : 'clip',
            'crop' => 'faces,focalpoint',
        ];

        if ( is_array( $crop ) ) {
            $crop = array_filter( $crop, function ( $value ) {
                return $value !== 'center';
            } );
            $query_args['crop'] = implode( ',', $crop );
        }

        return add_query_args( urlencode_deep( $query_args ), $base_url );
    }

}
```

### Modifying Existing Providers

Since you have access to each provider instance during registration via the `amf/provider` filter, you can also use it and decorate it, replace it with a subclass of a specific provider or replace it entirely:

```php
use AssetManagerFramework\Provider;
use ResizingUnsplashProvider;

add_filter( 'amf/provider', function ( Provider $provider, string $id ) {
    if ( $provider->get_id() !== 'unsplash' ) {
        return $provider;
    }

	return new ResizingUnsplashProvider();
}, 10, 2 );
```

This is useful, for example, when you are using a third-party provider implementation and want to change certain behavior. Remember to use a priority later than the default for this filter, for example 20, so your code runs after the default filter.

### `AssetManagerFramework\ProviderRegistry` Class

The `ProviderRegistry` is designed to be accessed via the `amf/register_providers` action hook but provides the following methods:

**`static instance() : ProviderRegistry`**

Returns the registry singleton class.

**`get( string $id = '' ) : Provider`**

Get a provider by ID or the default provider (the first registered provider) if no ID is provided.

**`register( Provider $provider )`**

Registers a new provider class.

### `AssetManagerFramework\Media` Class

The `Media` object is important as it allows you easily map data from any API to data that Altis can understand and use. It is recommended to set as much data as possible given the API responses.

The following methods are available:

**`set_url( string $url )`**

Sets the primary URL for the image, this is equivalent to the original full size image URL.

**`set_title( string $title )`**

Sets the media title.

**`set_width( int $width )`**

Sets the width of the original file in pixels.

**`set_height( int $height )`**

Sets the height of the original file in pixels.

**`set_sizes( array $sizes )`**

The most complex component to set the data for, the sizes array must be a particular shape and defines all the different thumbnail sizes and crops you may need. Determining this data depends a lot on how the provider returns data and how it generates resized images.

The sizes array should be a list with size names as keys and width, height, orientation and url data:

```php
$sizes = [
    'full' => [
        'width' => 1400,
        'height' => 900,
        'orientation' => 'landscape',
        'url' => 'https://example.com/image.jpg',
    ],
    // ...
];
```

Below is an example of a function used to prepare an image object response from Unsplash.com:

```php
use AssetManagerFramework\Media;

function prepare_item( stdClass $image ) : Media {
    // Create the media instance.
    $item = new Media( $image->id, 'image/jpeg' );
    $item->set_url( $image->urls->raw );
    $item->set_width( $image->width );
    $item->set_height( $image->height );

    // Calculate sizes.
    $registered_sizes = wp_get_registered_image_subsizes();
    $registered_sizes['full'] = [
        'width' => $image->width,
        'height' => $image->height,
        'crop' => false,
    ];
    if ( isset( $registered_sizes['medium'] ) ) {
        $registered_sizes['medium']['crop'] = true;
    }

    $orientation = $image->height > $image->width ? 'portrait' : 'landscape';
    $sizes = [];
    foreach ( $registered_sizes as $name => $size ) {
        // Unsplash uses imgix for resizing and modifying images.
        $imgix_args = [
            'w' => $size['width'],
            'h' => $size['height'],
            'fit' => $size['crop'] ? 'crop' : 'max',
        ];
        $sizes[ $name ] = [
            'width' => $size['width'],
            'height' => $size['height'],
            'orientation' => $orientation,
            'url' => add_query_arg( urlencode_deep( $imgix_args ), $image->urls->raw ),
        ];
    }

    $item->set_sizes( $sizes );

    return $item;
}
```

**`set_filename( string $filename )`**

Sets the media item's filename.

**`set_image( string $image )`**

Sets a placeholder image or custom thumbnail URL, useful for non-image based media.

**`set_link( string $link )`**

Sets a link to a URL to view the media if available. This is equivalent to the attachment page view in WordPress.

**`set_alt( string $alt )`**

Sets the alternative text for the media, commonly used in the `alt` attribute.

**`set_description( string $description )`**

Sets the description text for the media, can be used for the caption in some cases but is intended for a long description of the file.

**`set_caption( string $caption )`**

Sets caption text for the media, commonly displayed below the media in post content.

**`set_name( string $name )`**

Sets the slug or URL safe version of the media title for use in links.

**`set_date( int $date )`**

A unix timestamp of when the file was created.

**`set_modified( int $modified )`**

A unix timestamp of when the file was last edited.

**`set_file_size( int $file_size )`**

Sets the file size in bytes.

**`set_author( string $name, string $link )`**

Set the media file's author name and an optional link to their website or other appropriate URL.

**`set_meta( array $meta )`**

Set a key/value list of meta data associated with the media, stored as post meta when using the media.

**`add_meta( string $key, mixed $value )`**

Adds an item of meta data rather than setting or overwriting data like `set_meta()`.

**`set_amf_meta( array $meta )`**

Set additional meta data not stored in the database but for use within AMF during processing. See the hooks and filters section below.

**`add_amf_meta( string $key, mixed $value )`**

Adds an item of AMF only meta data rather than setting or overwriting data like `set_amf_meta()`.

### `AssetManagerFramework\Image` Class

Extends the `AssetManagerFramework\Media` class.

### `AssetManagerFramework\Document` Class

Extends the `AssetManagerFramework\Media` class.

### `AssetManagerFramework\Playable` Class

Designed for audio and video media specifically, this extends the `AssetManagerFramework\Media` class and adds the following methods:

**`set_length( string $duration )`**

Set the length of the media in `HH:ii:ss` format or `ii:ss` format.

**`set_thumb( string $thumb_url )`**

Set the thumbnail URL for the media.

**`set_bitrate( int $bitrate, string $bitrate_mode = '' )`**

Sets the bitrate and optional bitrate mode.

### `AssetManagerFramework\Video` Class

Extends the `AssetManagerFramework\Playable` class.

### `AssetManagerFramework\Audio` Class

Extends the `AssetManagerFramework\Playable` class and adds the following methods:

**`set_artist( string $artist )`**

Sets the artist's name.

**`set_album( string $album )`**

Sets the album the audio is from.

## Hook and Filters

### Actions

**`amf/loaded`**

Fires once AMF has been loaded and bootstrapped. Use this hook to load any custom providers or media objects.

**`amf/register_providers: ProviderRegistry`**

The hook to use for registering new providers receives the `ProviderRegistry` object as its parameter.

**`amf/inserted_attachment: WP_Post $attachment, array $selection, array $amf_meta`**

Fires when a media file is selected and downloaded for use locally. Receives 3 parameters:

- `WP_Post $attachment`: The newly created attachment post for the media.
- `array $selection`: The data currently available in the media library for the selected post.
- `array $amf_meta`: Any custom meta data added via `Media::set_amf_meta()` or `Media::add_amf_meta()`.

### Filters

**`amf/provider: Provider`**

Filters the AMF provider class during registration. This filter receives each provider class.

**`amf/script/data: array`**

Filters the data passed client side to the media library integration. By default this contains an associative array with an item called `providers` that is an array of all registered providers including their ID, name and feature support.
