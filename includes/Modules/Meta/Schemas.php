<?php
/**
 * JSON schemas for structured Claude answers in the Meta module.
 *
 * Only keywords the structured-output feature supports are used (types, enum, required, additionalProperties:false,
 * descriptions). Length limits and item counts are stated in descriptions and enforced by Validator.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Meta;

defined( 'ABSPATH' ) || exit;

final class Schemas {

	/**
	 * One title + description proposal.
	 *
	 * @return array
	 */
	public static function meta(): array {
		return self::object(
			array(
				'title'              => array(
					'type'        => 'string',
					'description' => 'SEO title, plain text, at most 60 characters (aim for 50-60). Empty string if not requested.',
				),
				'description'        => array(
					'type'        => 'string',
					'description' => 'Meta description, plain text, 120-155 characters, ending with a clear call to action. Empty string if not requested.',
				),
				'focus_keyword_used' => array(
					'type'        => 'boolean',
					'description' => 'True if the focus keyword appears in the title or the description.',
				),
				'rationale'          => array(
					'type'        => 'string',
					'description' => 'One or two short sentences explaining the choice.',
				),
			)
		);
	}

	/**
	 * Three alternative title + description options.
	 *
	 * @return array
	 */
	public static function alternatives(): array {
		return self::object(
			array(
				'options' => array(
					'type'        => 'array',
					'description' => 'Exactly 3 distinct options, best first.',
					'items'       => self::meta(),
				),
			)
		);
	}

	/**
	 * Alt texts for a list of images.
	 *
	 * @return array
	 */
	public static function images(): array {
		return self::object(
			array(
				'images' => array(
					'type'        => 'array',
					'description' => 'One entry per image listed in the task, in the same order.',
					'items'       => self::object(
						array(
							'attachment_id' => array(
								'type'        => 'integer',
								'description' => 'The attachment_id given for the image, unchanged (0 if none was given).',
							),
							'src'           => array(
								'type'        => 'string',
								'description' => 'The src given for the image, unchanged.',
							),
							'alt'           => array(
								'type'        => 'string',
								'description' => 'Alt text, plain text, at most 125 characters. Empty string for purely decorative images.',
							),
							'rationale'     => array(
								'type'        => 'string',
								'description' => 'One short sentence explaining the alt text.',
							),
						)
					),
				),
			)
		);
	}

	/**
	 * Object schema with every property required and no extra properties.
	 *
	 * @param array $properties Properties.
	 * @return array
	 */
	private static function object( array $properties ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}
}
