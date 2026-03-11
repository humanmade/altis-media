<?php
/**
 * Test content sanitisation (spec Section 6).
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

use Altis\Media\Private_Media\Sanitisation;

class SanitisationTest extends \Codeception\TestCase\WPTestCase {

	protected $tester;

	public function testStripsAllAwsParams() {
		$params = [
			'X-Amz-Content-Sha256=abc',
			'X-Amz-Security-Token=def',
			'X-Amz-Algorithm=AWS4-HMAC-SHA256',
			'X-Amz-Credential=AKID/20240101/us-east-1/s3/aws4_request',
			'X-Amz-Date=20240101T000000Z',
			'X-Amz-SignedHeaders=host',
			'X-Amz-Expires=900',
			'X-Amz-Signature=abc123',
			'presign=true',
		];

		$url = 'https://example.com/uploads/photo.jpg?' . implode( '&', $params );
		$content = sprintf( '<img src="%s" class="wp-image-1" />', $url );

		$result = Sanitisation\strip_aws_params_from_content( $content );

		foreach ( Sanitisation\AWS_PARAMS as $param ) {
			$this->assertStringNotContainsString( $param, $result, "Parameter {$param} should be stripped." );
		}

		$this->assertStringContainsString( 'photo.jpg', $result );
	}

	public function testPreservesNonAwsQueryParams() {
		$url = 'https://example.com/uploads/photo.jpg?w=800&h=600&X-Amz-Algorithm=test&quality=80';
		$content = sprintf( '<img src="%s" />', $url );

		$result = Sanitisation\strip_aws_params_from_content( $content );

		$this->assertStringContainsString( 'w=800', $result );
		$this->assertStringContainsString( 'h=600', $result );
		$this->assertStringContainsString( 'quality=80', $result );
		$this->assertStringNotContainsString( 'X-Amz-Algorithm', $result );
	}

	public function testStripsFromHrefAttribute() {
		$url = 'https://example.com/uploads/doc.pdf?X-Amz-Signature=abc&presign=true';
		$content = sprintf( '<a href="%s">Download</a>', $url );

		$result = Sanitisation\strip_aws_params_from_content( $content );

		$this->assertStringNotContainsString( 'X-Amz-Signature', $result );
		$this->assertStringNotContainsString( 'presign', $result );
		$this->assertStringContainsString( 'doc.pdf', $result );
	}

	public function testStripsFromDataSrcAttribute() {
		$url = 'https://example.com/uploads/video.mp4?X-Amz-Algorithm=test';
		$content = sprintf( '<video data-src="%s"></video>', $url );

		$result = Sanitisation\strip_aws_params_from_content( $content );

		$this->assertStringNotContainsString( 'X-Amz-Algorithm', $result );
	}

	public function testMixedUrls() {
		$content = '<img src="https://example.com/a.jpg?X-Amz-Signature=abc" class="wp-image-1" />'
			. '<img src="https://example.com/b.jpg" class="wp-image-2" />'
			. '<a href="https://example.com/c.pdf?presign=true&X-Amz-Date=20240101">PDF</a>';

		$result = Sanitisation\strip_aws_params_from_content( $content );

		$this->assertStringNotContainsString( 'X-Amz-Signature', $result );
		$this->assertStringNotContainsString( 'presign', $result );
		$this->assertStringNotContainsString( 'X-Amz-Date', $result );
		$this->assertStringContainsString( 'a.jpg', $result );
		$this->assertStringContainsString( 'b.jpg', $result );
		$this->assertStringContainsString( 'c.pdf', $result );
	}

	public function testSanitisePostContentFilter() {
		$data = [
			'post_content' => '<img src="https://example.com/a.jpg?X-Amz-Algorithm=test" />',
		];

		$result = Sanitisation\sanitise_post_content( $data );

		$this->assertStringNotContainsString( 'X-Amz-Algorithm', $result['post_content'] );
	}

	public function testEmptyContentPassesThrough() {
		$data = [ 'post_content' => '' ];
		$result = Sanitisation\sanitise_post_content( $data );
		$this->assertEquals( '', $result['post_content'] );
	}

	public function testStripsXAmzS3HostParam() {
		$url = 'https://example.com/uploads/photo.jpg?X-Amz-S3-Host=s3.amazonaws.com&w=800';
		$content = sprintf( '<img src="%s" />', $url );

		$result = Sanitisation\strip_aws_params_from_content( $content );

		$this->assertStringNotContainsString( 'X-Amz-S3-Host', $result );
		$this->assertStringContainsString( 'w=800', $result );
	}
}
