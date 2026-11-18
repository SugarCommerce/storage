<?php
/**
 * VirusTotalTest
 *
 * @package SugarCommerce\Storage
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Tests\Scan;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Storage\Scan\State;
use SugarCommerce\Storage\Scan\Subject;
use SugarCommerce\Storage\Scan\VirusTotal;
use SugarCommerce\Storage\Tests\Support\FakeHttp;

/**
 * VirusTotal is answered through the WordPress HTTP API stubs throughout.
 * Nothing here reaches it.
 */
final class VirusTotalTest extends TestCase {

	private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	protected function setUp(): void {
		wp_test_reset();
	}

	private static function stats( int $malicious, string $label = '' ): void {
		$attributes = array(
			'last_analysis_stats' => array(
				'malicious'  => $malicious,
				'undetected' => 60,
			),
		);

		if ( '' !== $label ) {
			$attributes['popular_threat_classification'] = array( 'suggested_threat_label' => $label );
		}

		FakeHttp::queue( 200, (string) json_encode( array( 'data' => array( 'attributes' => $attributes ) ) ) );
	}

	private static function subject(): Subject {
		return new Subject( 5, self::SHA );
	}

	public function test_it_asks_by_digest_with_the_key_and_never_sends_the_file(): void {
		self::stats( 0 );

		$verdict  = ( new VirusTotal( 'vt-key' ) )->scan( Subject::from_string( 'abcde' )->with_sha256( self::SHA ) );
		$requests = FakeHttp::requests();

		$this->assertSame( State::Clean, $verdict->state );
		$this->assertCount( 1, $requests );
		$this->assertSame( VirusTotal::ENDPOINT . self::SHA, $requests[0]['url'] );
		$this->assertSame( 'GET', $requests[0]['args']['method'] );
		$this->assertSame( 'vt-key', $requests[0]['args']['headers']['x-apikey'] );
		$this->assertArrayNotHasKey( 'body', $requests[0]['args'], 'The file is never sent.' );
	}

	/**
	 * The answer is a page of JSON; the request says so, and does not
	 * follow a redirect somewhere else with the key on it.
	 */
	public function test_the_answer_is_capped_and_redirects_are_not_followed(): void {
		self::stats( 0 );

		( new VirusTotal( 'k', timeout: 7 ) )->scan( self::subject() );

		$args = FakeHttp::last()['args'];

		$this->assertSame( VirusTotal::MAX_RESPONSE, $args['limit_response_size'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 7, $args['timeout'] );
	}

	public function test_a_file_it_has_never_seen_is_unknown_not_infected(): void {
		FakeHttp::queue( 404, '{"error":{"code":"NotFoundError"}}' );

		$this->assertSame( State::Unknown, ( new VirusTotal( 'k' ) )->scan( self::subject() )->state );
	}

	public function test_two_engines_agreeing_is_infected_with_the_label(): void {
		self::stats( VirusTotal::ENGINES, 'trojan.eicar/test' );

		$verdict = ( new VirusTotal( 'k' ) )->scan( self::subject() );

		$this->assertTrue( $verdict->is_infected() );
		$this->assertSame( 'trojan.eicar/test (2 engines on VirusTotal)', $verdict->detail );
	}

	public function test_one_engine_alone_is_not_enough(): void {
		self::stats( 1 );

		$this->assertSame( State::Clean, ( new VirusTotal( 'k' ) )->scan( self::subject() )->state );
	}

	public function test_the_threshold_can_be_raised_or_lowered(): void {
		self::stats( 1 );

		$this->assertTrue( ( new VirusTotal( 'k', engines: 1 ) )->scan( self::subject() )->is_infected() );
		$this->assertTrue( VirusTotal::judge( array( 'data' => array( 'attributes' => array( 'last_analysis_stats' => array( 'malicious' => 1 ) ) ) ), 0 )->is_infected(), 'Never below one vote.' );
		$this->assertFalse( VirusTotal::judge( array( 'data' => array( 'attributes' => array( 'last_analysis_stats' => array( 'malicious' => 3 ) ) ) ), 4 )->is_infected() );
	}

	/**
	 * Every engine timed out, failed or could not read the type: nobody
	 * judged it, so it is not clean.
	 */
	public function test_an_analysis_no_engine_judged_is_unknown_not_clean(): void {
		$stats = array(
			'malicious'        => 0,
			'suspicious'       => 0,
			'undetected'       => 0,
			'harmless'         => 0,
			'timeout'          => 12,
			'type-unsupported' => 60,
		);

		$this->assertSame( State::Unknown, VirusTotal::judge( array( 'data' => array( 'attributes' => array( 'last_analysis_stats' => $stats ) ) ) )->state );
		$this->assertSame( State::Clean, VirusTotal::judge( array( 'data' => array( 'attributes' => array( 'last_analysis_stats' => array( 'undetected' => 70 ) ) ) ) )->state );
		$this->assertSame( State::Unknown, VirusTotal::judge( array( 'data' => array( 'attributes' => array( 'last_analysis_stats' => array( 'undetected' => -5 ) ) ) ) )->state );
	}

	public function test_a_dump_does_not_show_the_key(): void {
		$this->assertStringNotContainsString( 'vt-secret', print_r( new VirusTotal( 'vt-secret' ), true ) );
	}

	public function test_no_analysis_at_all_is_unknown(): void {
		$this->assertSame( State::Unknown, VirusTotal::judge( array( 'data' => array( 'attributes' => array() ) ) )->state );
	}

	/**
	 * An outage, a quota or a body that is not JSON: ask again later,
	 * never a pass.
	 */
	public function test_an_outage_or_quota_is_an_error_to_retry(): void {
		FakeHttp::queue( 429 );
		FakeHttp::queue( 503 );
		FakeHttp::queue_error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		foreach ( array( 429, 503, 'timeout' ) as $case ) {
			$verdict = ( new VirusTotal( 'k' ) )->scan( self::subject() );

			$this->assertSame( State::Error, $verdict->state, (string) $case );
			$this->assertTrue( $verdict->state->worth_retrying() );
		}
	}

	/**
	 * A 200 that is not an analysis has no stats, so it says nothing.
	 */
	public function test_a_success_that_is_not_json_is_not_a_pass(): void {
		FakeHttp::queue( 200, '<html>captive portal</html>' );

		$this->assertSame( State::Unknown, ( new VirusTotal( 'k' ) )->scan( self::subject() )->state );
	}

	public function test_it_takes_files_of_any_size(): void {
		$scanner = new VirusTotal( 'k' );

		$this->assertTrue( $scanner->accepts( PHP_INT_MAX ) );
		$this->assertSame( 'virustotal', $scanner->name() );
	}

	public function test_a_file_without_a_whole_file_digest_is_not_looked_up(): void {
		$verdict = ( new VirusTotal( 'k' ) )->scan( new Subject( 5, 'short' ) );

		$this->assertSame( State::Unknown, $verdict->state );
		$this->assertSame( array(), FakeHttp::requests() );
	}

	public function test_the_key_is_kept_out_of_stack_traces(): void {
		$parameter = ( new \ReflectionMethod( VirusTotal::class, '__construct' ) )->getParameters()[0];

		$this->assertCount( 1, $parameter->getAttributes( \SensitiveParameter::class ) );
	}
}
