# Dynamic Images

The Media module supports dynamic image resizing via a microservice called [Tachyon](https://github.com/humanmade/tachyon). Tachyon allows arbitrary image resizes to be done on the fly, supports lossless image compression and many file formats such as WebP for supported browsers.

By default the Tachyon service is enabled. It can be explicitly disabled by setting the `modules.media.tachyon` configuration property to `false`, though this is highly discouraged.

All images rendered on the front end of the website are automatically modified to use the Tachyon service URLs. Via lossless compression and automatic format conversion, image sizes can be reduced up to 70% with no degradation in quality.
