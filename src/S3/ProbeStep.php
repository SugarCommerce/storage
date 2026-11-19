<?php
/**
 * How one step of the connection test went.
 *
 * @package   SugarCommerce\Storage
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Storage\S3;

/**
 * Class ProbeStep
 *
 * @since 1.0.0
 */
final class ProbeStep {

	/**
	 * Record a step.
	 *
	 * @since 1.0.0
	 *
	 * @param ProbeCheck      $check   What was tried.
	 * @param ProbeFault|null $fault   What went wrong, or null when it passed.
	 * @param int             $status  The HTTP status that decided it, or 0.
	 * @param string          $detail  For Unreachable, the transport's reason
	 *                                 (for a log); for Elsewhere, the region
	 *                                 storage named; for CorsPolicy, the
	 *                                 policy's reason; for CorsOrigin, the
	 *                                 origin. Otherwise storage's own words.
	 * @param string          $message What happened, in a sentence for the
	 *                                 person fixing it. Translated; escape
	 *                                 it where it is shown.
	 * @param string          $note    Something worth knowing about a step
	 *                                 that passed, or ''.
	 * @param string          $code    Storage's own error code, or ''.
	 */
	public function __construct(
		public readonly ProbeCheck $check,
		public readonly ?ProbeFault $fault = null,
		public readonly int $status = 0,
		public readonly string $detail = '',
		public readonly string $message = '',
		public readonly string $note = '',
		public readonly string $code = '',
	) {}

	/**
	 * Whether the step passed.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return null === $this->fault;
	}

	/**
	 * The step for a REST answer or a script.
	 *
	 * @since 1.0.0
	 *
	 * @return array{check: string, label: string, ok: bool, fault: string, status: int, message: string, note: string, code: string}
	 */
	public function to_array(): array {
		return array(
			'check'   => $this->check->value,
			'label'   => $this->check->label(),
			'ok'      => $this->ok(),
			'fault'   => null === $this->fault ? '' : $this->fault->value,
			'status'  => $this->status,
			'message' => $this->message,
			'note'    => $this->note,
			'code'    => $this->code,
		);
	}
}
