<?php
/**
 * Browser Templates
 *
 * Prints Underscore templates for wp.template(), the same mechanism WordPress
 * uses for its own media modal.
 *
 * These replace markup that was assembled in JavaScript by string
 * concatenation, where escaping was a per-interpolation decision. That is not
 * a theoretical concern: getting one of those decisions wrong produced a
 * stored XSS in the rename dialog, where a filename was escaped for text
 * context and then interpolated into an attribute.
 *
 * Here escaping is the default. `{{ }}` escapes; `{{{ }}}` is an explicit,
 * visible opt-out. Translated strings are rendered by PHP at print time, so
 * they go through esc_html__() and never travel through a JavaScript i18n
 * object.
 *
 * @package     SugarCommerce\Storage\Browser\Admin
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\Browser\Admin;

/**
 * Class Templates
 */
class Templates {

	/**
	 * Build a template printer.
	 *
	 * @param string $capability Capability a user needs to be shown these.
	 */
	public function __construct( private string $capability ) {
	}

	/**
	 * Whether the templates have already been printed this request.
	 *
	 * @var bool
	 */
	private bool $templates_printed = false;

	/**
	 * Print the templates the browser needs.
	 *
	 * Hooked to both admin_footer and admin_print_footer_scripts: the media
	 * upload iframe fires only the latter. Both fire on an ordinary admin page,
	 * hence the guard — duplicate script blocks would give two elements the
	 * same id.
	 *
	 * @return void
	 */
	public function print_templates(): void {
		if ( $this->templates_printed || ! current_user_can( $this->capability ) ) {
			return;
		}

		$this->templates_printed = true;

		$this->template_bucket_details();
		$this->template_file_details();
	}

	/**
	 * Open a template block.
	 *
	 * @param string $id Template id, without the 'tmpl-' prefix.
	 *
	 * @return void
	 */
	private function open_template( string $id ): void {
		printf( '<script type="text/html" id="tmpl-%s">', esc_attr( $id ) );
	}

	/**
	 * Close a template block.
	 *
	 * @return void
	 */
	private function close_template(): void {
		echo '</script>';
	}

	/**
	 * Bucket details modal.
	 *
	 * @return void
	 */
	private function template_bucket_details(): void {
		$this->open_template( 's3-bucket-details' );
		?>
		<div class="s3-bucket-details-content">

			<div class="s3-details-section">
				<h4><?php esc_html_e( 'Bucket Information', 'sugarcommerce' ); ?></h4>
				<table class="s3-details-table">
					<tr>
						<td><strong><?php esc_html_e( 'Bucket Name', 'sugarcommerce' ); ?></strong></td>
						<td><code>{{ data.bucket }}</code></td>
					</tr>
					<# if ( data.basic && data.basic.region ) { #>
					<tr>
						<td><strong><?php esc_html_e( 'Region', 'sugarcommerce' ); ?></strong></td>
						<td>{{ data.basic.region }}</td>
					</tr>
					<# } #>
					<# if ( data.basic && data.basic.created ) { #>
					<tr>
						<td><strong><?php esc_html_e( 'Created', 'sugarcommerce' ); ?></strong></td>
						<td>{{ data.basic.created }}</td>
					</tr>
					<# } #>
					<tr>
						<td><strong><?php esc_html_e( 'Provider', 'sugarcommerce' ); ?></strong></td>
						<td>{{ data.providerName }}</td>
					</tr>
				</table>
			</div>

			<# if ( data.cors ) { #>
			<div class="s3-details-section">
				<h4><?php esc_html_e( 'Upload Capability', 'sugarcommerce' ); ?></h4>
				<table class="s3-details-table">
					<tr>
						<td><strong><?php esc_html_e( 'Upload Ready', 'sugarcommerce' ); ?></strong></td>
						<td>
							<# if ( data.cors.upload_ready ) { #>
								<span class="s3-status-success">&#10003; <?php esc_html_e( 'Yes', 'sugarcommerce' ); ?></span>
							<# } else { #>
								<span class="s3-status-error">&#10007; <?php esc_html_e( 'No', 'sugarcommerce' ); ?></span>
							<# } #>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Current Domain', 'sugarcommerce' ); ?></strong></td>
						<td>{{ data.cors.current_origin }}</td>
					</tr>
					<# if ( data.cors.details ) { #>
					<tr>
						<td colspan="2"><small>{{ data.cors.details }}</small></td>
					</tr>
					<# } #>
				</table>
			</div>
			<# } #>

			<# if ( data.cors && data.cors.analysis ) { #>
			<div class="s3-details-section">
				<h4><?php esc_html_e( 'CORS Configuration', 'sugarcommerce' ); ?></h4>
				<table class="s3-details-table">
					<tr>
						<td><strong><?php esc_html_e( 'Has CORS', 'sugarcommerce' ); ?></strong></td>
						<td>
							<# if ( data.cors.analysis.has_cors ) { #><?php esc_html_e( 'Yes', 'sugarcommerce' ); ?><# } else { #><?php esc_html_e( 'No', 'sugarcommerce' ); ?><# } #>
						</td>
					</tr>
					<# if ( data.cors.analysis.has_cors ) { #>
					<tr>
						<td><strong><?php esc_html_e( 'Rules', 'sugarcommerce' ); ?></strong></td>
						<td>{{ data.cors.analysis.rules_count || 0 }}</td>
					</tr>
					<# if ( data.cors.analysis.security_warnings && data.cors.analysis.security_warnings.length ) { #>
					<tr>
						<td><strong><?php esc_html_e( 'Security Warnings', 'sugarcommerce' ); ?></strong></td>
						<td><span class="s3-status-warning">{{ data.cors.analysis.security_warnings.length }}</span></td>
					</tr>
					<# } #>
					<# } #>
				</table>
			</div>
			<# } #>

			<# if ( data.permissions ) { #>
			<div class="s3-details-section">
				<h4><?php esc_html_e( 'Permissions', 'sugarcommerce' ); ?></h4>
				<table class="s3-details-table">
					<# _.each( [
						[ 'read',   '<?php echo esc_js( __( 'Read Access', 'sugarcommerce' ) ); ?>' ],
						[ 'write',  '<?php echo esc_js( __( 'Write Access', 'sugarcommerce' ) ); ?>' ],
						[ 'delete', '<?php echo esc_js( __( 'Delete Access', 'sugarcommerce' ) ); ?>' ]
					], function ( row ) { #>
					<tr>
						<td><strong>{{ row[1] }}</strong></td>
						<td>
							<# if ( data.permissions[ row[0] ] ) { #>
								<span class="s3-status-success">&#10003; <?php esc_html_e( 'Yes', 'sugarcommerce' ); ?></span>
							<# } else { #>
								<span class="s3-status-error">&#10007; <?php esc_html_e( 'No', 'sugarcommerce' ); ?></span>
							<# } #>
						</td>
					</tr>
					<# } ); #>
				</table>
			</div>
			<# } #>

			<# if ( data.cors && data.cors.analysis && data.cors.analysis.recommendations && data.cors.analysis.recommendations.length ) { #>
			<div class="s3-details-section">
				<h4><?php esc_html_e( 'Recommendations', 'sugarcommerce' ); ?></h4>
				<ul class="s3-recommendations-list">
					<# _.each( data.cors.analysis.recommendations, function ( rec ) { #>
						<li>{{ rec }}</li>
					<# } ); #>
				</ul>
			</div>
			<# } #>

		</div>
		<?php
		$this->close_template();
	}

	/**
	 * File details modal.
	 *
	 * @return void
	 */
	private function template_file_details(): void {
		$this->open_template( 's3-file-details' );
		?>
		<div class="s3-details-content">

			<div class="s3-details-section">
				<h4><?php esc_html_e( 'Basic Information', 'sugarcommerce' ); ?></h4>
				<table class="s3-details-table">
					<tr>
						<td><strong><?php esc_html_e( 'Filename', 'sugarcommerce' ); ?></strong></td>
						<td>{{ data.filename }}</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Object Key', 'sugarcommerce' ); ?></strong></td>
						<td><code>{{ data.key }}</code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Size', 'sugarcommerce' ); ?></strong></td>
						<td>{{ data.sizeFormatted }}</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Last Modified', 'sugarcommerce' ); ?></strong></td>
						<td>{{ data.modifiedFormatted }}</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'MIME Type', 'sugarcommerce' ); ?></strong></td>
						<td>{{ data.mimeType }}</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Category', 'sugarcommerce' ); ?></strong></td>
						<td>{{ data.category }}</td>
					</tr>
				</table>
			</div>

			<div class="s3-details-section">
				<h4><?php esc_html_e( 'Storage Information', 'sugarcommerce' ); ?></h4>
				<table class="s3-details-table">
					<tr>
						<td><strong><?php esc_html_e( 'Storage Class', 'sugarcommerce' ); ?></strong></td>
						<td>{{ data.storageClass }}</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'ETag', 'sugarcommerce' ); ?></strong></td>
						<td><code>{{ data.etag }}</code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Upload Type', 'sugarcommerce' ); ?></strong></td>
						<td>
							<# if ( data.isMultipart ) { #>
								<?php esc_html_e( 'Multipart', 'sugarcommerce' ); ?>
								<# if ( data.partCount ) { #>({{ data.partCount }})<# } #>
							<# } else { #>
								<?php esc_html_e( 'Single part', 'sugarcommerce' ); ?>
							<# } #>
						</td>
					</tr>
				</table>
			</div>

		</div>
		<?php
		$this->close_template();
	}
}
