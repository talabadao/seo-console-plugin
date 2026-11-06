<?php
/**
 * One audit rule: metadata + check.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Audit rule value object.
 */
final class Rule {

	const SEVERITIES = array( 'error', 'warning', 'notice' );

	const CATEGORIES = array( 'indexability', 'content', 'links', 'images', 'performance', 'international', 'social', 'structured-data' );

	const FIXABLE_BY = array( 'ai', 'user', 'developer' );

	/** @var string */
	public $id;

	/** @var string error|warning|notice */
	public $severity;

	/** @var string One of CATEGORIES. */
	public $category;

	/** @var string Translated short title. */
	public $title;

	/** @var string Translated: why it matters. */
	public $why;

	/** @var string Translated: how to fix, plain English. */
	public $how_to_fix;

	/** @var string|null ai|user|developer|null */
	public $fixable_by;

	/** @var string page|run */
	public $scope;

	/** @var callable Page rule: fn( array $page, RuleContext $ctx ): ?array; run rule: fn( RuleContext $ctx ): array[]. */
	private $check;

	/**
	 * Constructor.
	 *
	 * @param array    $definition id, severity, category, title, why, how_to_fix, fixable_by, scope.
	 * @param callable $check      Check callback.
	 */
	public function __construct( array $definition, callable $check ) {
		$this->id         = (string) $definition['id'];
		$this->severity   = in_array( $definition['severity'], self::SEVERITIES, true ) ? $definition['severity'] : 'notice';
		$this->category   = in_array( $definition['category'], self::CATEGORIES, true ) ? $definition['category'] : 'indexability';
		$this->title      = (string) $definition['title'];
		$this->why        = (string) $definition['why'];
		$this->how_to_fix = (string) $definition['how_to_fix'];
		$this->fixable_by = isset( $definition['fixable_by'] ) && in_array( $definition['fixable_by'], self::FIXABLE_BY, true ) ? $definition['fixable_by'] : null;
		$this->scope      = isset( $definition['scope'] ) && 'run' === $definition['scope'] ? 'run' : 'page';
		$this->check      = $check;
	}

	/**
	 * Evaluates a page rule.
	 *
	 * @param array       $page Decoded page row.
	 * @param RuleContext $ctx  Context.
	 * @return array|null Issue data, or null when the page passes.
	 */
	public function check_page( array $page, RuleContext $ctx ): ?array {
		if ( 'page' !== $this->scope ) {
			return null;
		}
		$result = call_user_func( $this->check, $page, $ctx );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Evaluates a run rule.
	 *
	 * @param RuleContext $ctx Context.
	 * @return array[] Issue data list (one entry per issue).
	 */
	public function check_run( RuleContext $ctx ): array {
		if ( 'run' !== $this->scope ) {
			return array();
		}
		$result = call_user_func( $this->check, $ctx );
		return is_array( $result ) ? array_values( $result ) : array();
	}

	/** Metadata for REST/UI. */
	public function to_array(): array {
		return array(
			'id'         => $this->id,
			'severity'   => $this->severity,
			'category'   => $this->category,
			'title'      => $this->title,
			'why'        => $this->why,
			'how_to_fix' => $this->how_to_fix,
			'fixable_by' => $this->fixable_by,
			'scope'      => $this->scope,
		);
	}
}
