# Image Recognition

The Media module includes support for automatic image recognition of all images uploaded to the CMS media library. Images are automatically tagged with keywords using machine learning, making all images uploaded to the CMS more discoverable.

This is implementation using the [AWS Rekognition](https://github.com/humanmade/aws-rekognition) plugin and service.

By default automatic image recognition is enabled, but can be specifically disabled by setting the `modules.media.rekognition` setting to `false`.
