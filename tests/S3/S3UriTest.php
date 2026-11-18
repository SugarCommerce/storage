<?php
/**
 * `s3://bucket/key` address tests.
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\S3;

use SugarCommerce\Storage\S3\Address;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The address a plugin stores to say "this file lives in a bucket".
 */
final class S3UriTest extends TestCase {

	/**
	 * Only the scheme, as written, makes a path remote.
	 */
	public function test_only_the_scheme_makes_a_path_remote(): void {
		$this->assertTrue( Address::is( 's3://bucket/key.zip' ) );
		$this->assertTrue( Address::is( 's3://' ) );
		$this->assertFalse( Address::is( 'S3://bucket/key.zip' ) );
		$this->assertFalse( Address::is( ' s3://bucket/key.zip' ) );
		$this->assertFalse( Address::is( 'bucket/key.zip' ) );
		$this->assertFalse( Address::is( 'https://bucket.s3.amazonaws.com/key.zip' ) );
	}

	/**
	 * The bucket and the key come apart at the first slash.
	 *
	 * @param string                                  $path     A stored path.
	 * @param array{bucket: string, key: string}|null $expected What it names.
	 */
	#[DataProvider( 'paths' )]
	public function test_the_bucket_and_key_come_apart( string $path, ?array $expected ): void {
		$this->assertSame( $expected, Address::parse( $path ) );
	}

	/**
	 * Stored paths, and what each names.
	 *
	 * @return array<string, array{0: string, 1: array{bucket: string, key: string}|null}>
	 */
	public static function paths(): array {
		return array(
			'a key'                   => array( 's3://bucket/key.zip', array( 'bucket' => 'bucket', 'key' => 'key.zip' ) ),
			'a nested key'            => array( 's3://bucket/packs/Drum Kit.zip', array( 'bucket' => 'bucket', 'key' => 'packs/Drum Kit.zip' ) ),
			'a doubled slash'         => array( 's3://bucket//packs/a.zip', array( 'bucket' => 'bucket', 'key' => 'packs/a.zip' ) ),
			'a folder prefix'         => array( 's3://bucket/packs/', array( 'bucket' => 'bucket', 'key' => 'packs/' ) ),
			'no key'                  => array( 's3://bucket/', null ),
			'only slashes after'      => array( 's3://bucket///', null ),
			'no slash'                => array( 's3://bucket', null ),
			'no bucket'               => array( 's3:///key.zip', null ),
			'nothing'                 => array( 's3://', null ),
			'no scheme'               => array( 'bucket/key.zip', null ),
			'a local path'            => array( 'protected/packs/a.zip', null ),
			'upper-case scheme'       => array( 'S3://bucket/key.zip', null ),
			'an https link'           => array( 'https://example.com/a.zip', null ),
			'whitespace is not eaten' => array( ' s3://bucket/key.zip', null ),
		);
	}

	/**
	 * Typed input may leave the scheme off, when the caller says so.
	 */
	public function test_the_scheme_can_be_optional(): void {
		$expected = array(
			'bucket' => 'bucket',
			'key'    => 'a.zip',
		);

		$this->assertSame( $expected, Address::parse( 'bucket/a.zip', true ) );
		$this->assertSame( $expected, Address::parse( 's3://bucket/a.zip', true ) );
		$this->assertSame( $expected, Address::parse( '  /bucket/a.zip  ', true ) );
		$this->assertSame( $expected, Address::parse( ' s3://bucket/a.zip', true ) );
		$this->assertNull( Address::parse( 'bucket', true ) );
		$this->assertNull( Address::parse( 'bucket/', true ) );
		$this->assertNull( Address::parse( '', true ) );
	}

	/**
	 * An address built from a bucket and key parses back to them.
	 */
	public function test_an_address_round_trips(): void {
		$this->assertSame( 's3://bucket/packs/a.zip', Address::to( 'bucket', 'packs/a.zip' ) );
		$this->assertSame( 's3://bucket/packs/a.zip', Address::to( 'bucket', '/packs/a.zip' ) );
		$this->assertSame(
			array(
				'bucket' => 'bucket',
				'key'    => 'packs/a.zip',
			),
			Address::parse( Address::to( 'bucket', '//packs/a.zip' ) )
		);
	}
}
