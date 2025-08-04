# Image Recognition

The Media module includes support for automatic image recognition of all images uploaded to the CMS media library. Images are
automatically tagged with keywords using machine learning, making all images uploaded to the CMS more discoverable.

This is implementation using the [AWS Rekognition](https://github.com/humanmade/aws-rekognition) plugin and service.

By default automatic image recognition is enabled.

## Recognition features

### Labels

Standard image label detection is enabled by default and provides basic information similar to tags on a piece of content, for
example "nature", "aircraft" or "person" and can be searched against.

This data can be accessed via the post meta key `hm_aws_rekognition_labels`.

### Moderation labels

Moderation labels provide information on potential adult content in uploaded images.

This data can be accessed via the post meta key `hm_aws_rekognition_moderation`.

### Faces

Face detection provides the location and size of any faces in an image, as well as an ID if associated with a collection. You
can [learn more about creating a face collection using the AWS SDK here](https://docs.aws.amazon.com/rekognition/latest/dg/collections.html).

This data can be accessed via the post meta key `hm_aws_rekognition_faces`.

### Celebrities

This feature returns data on celebrities recognized in an image. Any successful matches will be used to populate the default image
alt text if not already set. The alt text can be updated after it has been set dynamically as well.

This data can be accessed via the post meta key `hm_aws_rekognition_celebrities`.

### Text

Text content can be extracted from uploaded images using this feature. By default it is used to enhance relevancy when searching in
the media library.

This data can be accessed via the post meta key `hm_aws_rekognition_text`.

## Configuration

The features described above can be toggled in your project's `composer.json` under the `extra.altis.modules.rekognition` property.
The default configuration is shown below:

```json
{
    "extra": {
        "altis": {
            "modules": {
                "media": {
                    "rekognition": {
                        "labels": true,
                        "moderation": false,
                        "faces": false,
                        "celebrities": false,
                        "text": false
                    }
                }
            }
        }
    }
}
```

## Hooks and filters

You can build new features on top of the basic image recognition features either using the data collected by default or by hooking
in.

### `hm.aws.rekognition.process`

Runs when an image is being processed and receives the AWS Rekognition client object, the attachment ID and the data to pass for
the `Image` parameter. You can use this to do any custom processing such as searching for faces in an existing collection.

```php
add_action( 'hm.aws.rekognition.process', function ( Aws\Rekognition\RekognitionClient $client, int $id, array $image_args ) {
    $known_faces = $client->searchFacesByImage( [
        'CollectionId' => 'ABCDEF123456',
        'FaceMatchThreshold' => 0.9,
        'Image' => $image_args,
    ] );

    if ( ! empty( $known_faces['FaceMatches'] ) ) {
        update_post_meta( $id, 'recognised_faces', $known_faces['FaceMatches'] );
    }
}, 10, 2 );
```

You can find
the [full documentation for the `RekognitionClient` object here](https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-rekognition-2016-06-27.html).

### `hm.aws.rekognition.keywords`

Filters the keywords stored and used to enhance media library search results. Receives the current keyword list, the data returned
from built in image processing according to what features are enabled e.g. labels, faces etc. and the attachment ID.

This is useful for doing any post processing of the recognition results such as translation.

```php
add_filter( 'hm.aws.rekognition.keywords', function ( array $keywords, array $data, int $id ) {
    $translated_keywords = [];

    // This could be Google translate for example.
    $translation_service = new TranslationService();

    foreach ( $keywords as $keyword ) {
        $translated_keywords[] = $translation_service->translate( $keyword, 'fr' );
    }

    return $translated_keywords;
}, 10, 3 );
```

### `hm.aws.rekognition.alt_text`

Similar to the `hm.aws.rekognition.keywords` filter but allows you modify the generated alt text when none has been set yet.

This can be good for enhancing the default alt text such as in the example below which lists detected celebrities as they appear
from left to right in the image.

```php
add_filter( 'hm.aws.rekognition.alt_text', function ( string $new_alt_text, array $data, int $id ) {
    if ( ! empty( $data['celebrities'] ) ) {
        $celebrities = $data['celebrities'];
        uasort( $celebrities, function ( $a, $b ) {
            return $a['Face']['BoundingBox']['Left'] <=> $b['Face']['BoundingBox']['Left'];
        } );
        $names = wp_list_pluck( $celebrities, 'Name' );
        return sprintf( 'From left to right: %s', implode( ', ', $names ) );
    }

    return $new_alt_text;
}, 10, 3 );
```
## Disabling Automatic Image Recognition

Automatic image recognition can be specifically disabled by setting the `modules.media.rekognition`
setting to `false`.
