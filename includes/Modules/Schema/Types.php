<?php
/**
 * Registry of the schema.org types the Schema module knows: which properties each type may carry,
 * which ones Google requires or recommends, what kind of value each property takes, and an honest
 * plain-English note about rich-result eligibility.
 *
 * Property kinds (used by Validator), written as alternatives separated by "|":
 *  - lower-case kinds: text, url, date, duration, price, currency, number, integer, time, email, bool,
 *    enum:<list> (see enums());
 *  - Capitalised names: a nested node of that schema.org type (or an {"@id"} reference to one);
 *  - "*": any known node.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

defined( 'ABSPATH' ) || exit;

final class Types {

	/** Rich result may be shown when the markup is valid and policies are met. */
	const RICH_ELIGIBLE = 'eligible';

	/** Rich result exists but only for a restricted set of sites. */
	const RICH_LIMITED = 'limited';

	/** Google retired the rich result; the markup only helps machines understand the page. */
	const RICH_REMOVED = 'removed';

	/** No Google rich result for this type. */
	const RICH_NONE = 'none';

	/** @var array<string,array>|null Resolved definitions (inheritance applied). */
	private static $resolved = null;

	/**
	 * Global property → kind map. A type definition can override a kind with its own 'kinds' key.
	 *
	 * @return array<string,string>
	 */
	public static function property_kinds(): array {
		return array(
			// Plain text.
			'name'                      => 'text',
			'alternateName'             => 'text',
			'headline'                  => 'text',
			'alternativeHeadline'       => 'text',
			'description'               => 'text',
			'text'                      => 'text',
			'articleSection'            => 'text',
			'keywords'                  => 'text',
			'inLanguage'                => 'text',
			'jobTitle'                  => 'text',
			'telephone'                 => 'text',
			'priceRange'                => 'text',
			'servesCuisine'             => 'text',
			'acceptsReservations'       => 'bool|text|url',
			'recipeYield'               => 'text',
			'recipeCategory'            => 'text',
			'recipeCuisine'             => 'text',
			'recipeIngredient'          => 'text',
			'serviceType'               => 'text',
			'category'                  => 'text',
			'sku'                       => 'text',
			'mpn'                       => 'text',
			'gtin'                      => 'text',
			'gtin8'                     => 'text',
			'gtin12'                    => 'text',
			'gtin13'                    => 'text',
			'gtin14'                    => 'text',
			'color'                     => 'text',
			'material'                  => 'text',
			'streetAddress'             => 'text',
			'addressLocality'           => 'text',
			'addressRegion'             => 'text',
			'postalCode'                => 'text',
			'addressCountry'            => 'text',
			'reviewBody'                => 'text',
			'legalName'                 => 'text',
			'slogan'                    => 'text',
			'contactType'               => 'text',
			'availableLanguage'         => 'text',
			'paymentAccepted'           => 'text',
			'currenciesAccepted'        => 'text',
			'calories'                  => 'text',
			'servingSize'               => 'text',
			'caption'                   => 'text',
			'query-input'               => 'text',
			'urlTemplate'               => 'text',
			'email'                     => 'email',
			// URLs.
			'url'                       => 'url',
			'sameAs'                    => 'url',
			'contentUrl'                => 'url',
			'embedUrl'                  => 'url',
			'thumbnailUrl'              => 'url',
			'menu'                      => 'url',
			'hasMap'                    => 'url',
			'item'                      => 'url|*',
			'image'                     => 'url|ImageObject',
			'logo'                      => 'url|ImageObject',
			'mainEntityOfPage'          => 'url|*',
			'isPartOf'                  => 'url|*',
			'target'                    => 'url|text|EntryPoint',
			// Dates and times.
			'datePublished'             => 'date',
			'dateModified'              => 'date',
			'uploadDate'                => 'date',
			'startDate'                 => 'date',
			'endDate'                   => 'date',
			'foundingDate'              => 'date',
			'priceValidUntil'           => 'date',
			'validFrom'                 => 'date',
			'validThrough'              => 'date',
			'duration'                  => 'duration',
			'totalTime'                 => 'duration',
			'prepTime'                  => 'duration',
			'cookTime'                  => 'duration',
			'opens'                     => 'time',
			'closes'                    => 'time',
			// Numbers.
			'price'                     => 'price',
			'lowPrice'                  => 'price',
			'highPrice'                 => 'price',
			'value'                     => 'price',
			'priceCurrency'             => 'currency',
			'currency'                  => 'currency',
			'ratingValue'               => 'number',
			'bestRating'                => 'number',
			'worstRating'               => 'number',
			'latitude'                  => 'number',
			'longitude'                 => 'number',
			'ratingCount'               => 'integer',
			'reviewCount'               => 'integer',
			'offerCount'                => 'integer',
			'position'                  => 'integer',
			'wordCount'                 => 'integer',
			'width'                     => 'integer',
			'height'                    => 'integer',
			// Enumerations.
			'availability'              => 'enum:availability',
			'itemCondition'             => 'enum:condition',
			'eventStatus'               => 'enum:event_status',
			'eventAttendanceMode'       => 'enum:attendance',
			'dayOfWeek'                 => 'enum:day',
			// Nested nodes.
			'author'                    => 'Person|Organization',
			'publisher'                 => 'Organization|Person',
			'offers'                    => 'Offer|AggregateOffer',
			'aggregateRating'           => 'AggregateRating',
			'review'                    => 'Review',
			'reviewRating'              => 'Rating',
			'itemReviewed'              => '*',
			'brand'                     => 'Brand|Organization',
			'address'                   => 'PostalAddress|text',
			'geo'                       => 'GeoCoordinates',
			'openingHoursSpecification' => 'OpeningHoursSpecification',
			'mainEntity'                => 'Question',
			'acceptedAnswer'            => 'Answer',
			'step'                      => 'HowToStep|HowToSection',
			'itemListElement'           => 'ListItem|HowToStep',
			'supply'                    => 'HowToSupply|text',
			'tool'                      => 'HowToTool|text',
			'estimatedCost'             => 'MonetaryAmount|text',
			'location'                  => 'Place|VirtualLocation',
			'organizer'                 => 'Organization|Person',
			'performer'                 => 'Person|PerformingGroup|Organization',
			'provider'                  => 'Organization|Person',
			'areaServed'                => 'text|Place',
			'potentialAction'           => 'SearchAction',
			'recipeInstructions'        => 'HowToStep|HowToSection',
			'nutrition'                 => 'NutritionInformation',
			'video'                     => 'VideoObject',
			'about'                     => '*',
			'parentOrganization'        => 'Organization',
			'contactPoint'              => 'ContactPoint',
			'worksFor'                  => 'Organization',
			'founder'                   => 'Person',
		);
	}

	/**
	 * Allowed values for enum kinds (short names; "https://schema.org/<Name>" is accepted too).
	 *
	 * @return array<string,string[]>
	 */
	public static function enums(): array {
		return array(
			'availability' => array( 'InStock', 'OutOfStock', 'PreOrder', 'PreSale', 'BackOrder', 'Discontinued', 'LimitedAvailability', 'OnlineOnly', 'InStoreOnly', 'SoldOut', 'MadeToOrder', 'Reserved' ),
			'condition'    => array( 'NewCondition', 'UsedCondition', 'RefurbishedCondition', 'DamagedCondition' ),
			'event_status' => array( 'EventScheduled', 'EventCancelled', 'EventMovedOnline', 'EventPostponed', 'EventRescheduled' ),
			'attendance'   => array( 'OfflineEventAttendanceMode', 'OnlineEventAttendanceMode', 'MixedEventAttendanceMode' ),
			'day'          => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'PublicHolidays' ),
		);
	}

	/**
	 * LocalBusiness subtypes offered in settings (all inherit LocalBusiness rules).
	 *
	 * @return string[]
	 */
	public static function local_business_types(): array {
		return array(
			'LocalBusiness',
			'Restaurant',
			'CafeOrCoffeeShop',
			'BarOrPub',
			'Bakery',
			'Store',
			'ClothingStore',
			'ElectronicsStore',
			'FurnitureStore',
			'HardwareStore',
			'ProfessionalService',
			'LegalService',
			'Attorney',
			'AccountingService',
			'FinancialService',
			'InsuranceAgency',
			'RealEstateAgent',
			'TravelAgency',
			'Dentist',
			'MedicalClinic',
			'Physician',
			'BeautySalon',
			'HairSalon',
			'DaySpa',
			'ExerciseGym',
			'AutoRepair',
			'Plumber',
			'Electrician',
			'HVACBusiness',
			'RoofingContractor',
			'GeneralContractor',
			'Hotel',
			'ChildCare',
		);
	}

	/**
	 * Types the AI generator may propose (deterministic extractors handle the rest).
	 *
	 * @return string[]
	 */
	public static function ai_types(): array {
		return array( 'FAQPage', 'HowTo', 'Service', 'Event', 'Recipe', 'Product' );
	}

	/**
	 * Raw definitions. Keys: label, parent, top_level, single (one per page), required, one_of (groups:
	 * at least one property of each group), recommended, optional, kinds (overrides), rich, note,
	 * id_slug, link (how Composer attaches the node to the page: isPartOf|mainEntityOfPage|none),
	 * skip_with_seo_plugin (never proposed when Rank Math / Yoast is active).
	 *
	 * @return array<string,array>
	 */
	private static function definitions(): array {
		$article_note = __( 'Helps Google show the headline, image and dates (Top stories and article enhancements). Rank Math and Yoast already output Article markup for posts, so SEO Console only adds it when no SEO plugin does.', 'seo-console' );
		$lb_note      = __( 'Describes your business (address, phone, opening hours). Google mainly uses your Google Business Profile for the map pack; this markup supports it and helps search engines and AI assistants connect your site to the business.', 'seo-console' );

		$defs = array(
			// Articles.
			'Article'                   => array(
				'label'       => __( 'Article', 'seo-console' ),
				'single'      => true,
				'recommended' => array( 'headline', 'image', 'datePublished', 'dateModified', 'author' ),
				'optional'    => array( 'publisher', 'description', 'mainEntityOfPage', 'isPartOf', 'articleSection', 'keywords', 'wordCount', 'inLanguage', 'url', 'name', 'about', 'video', 'thumbnailUrl', 'alternativeHeadline' ),
				'rich'        => self::RICH_ELIGIBLE,
				'note'        => $article_note,
				'id_slug'     => 'article',
				'link'        => 'mainEntityOfPage',
			),
			'BlogPosting'               => array(
				'parent' => 'Article',
				'label'  => __( 'Blog post', 'seo-console' ),
			),
			'NewsArticle'               => array(
				'parent' => 'Article',
				'label'  => __( 'News article', 'seo-console' ),
			),

			// FAQ.
			'FAQPage'                   => array(
				'label'    => __( 'FAQ page', 'seo-console' ),
				'single'   => true,
				'required' => array( 'mainEntity' ),
				'optional' => array( 'name', 'url', 'isPartOf', 'about', 'inLanguage', 'description' ),
				'rich'     => self::RICH_LIMITED,
				'note'     => __( 'Since August 2023 Google shows FAQ rich results only for well-known, authoritative government and health websites, so this usually will not change how your result looks. It still helps search engines and AI assistants understand the questions your page answers. Every question and answer must be visible on the page.', 'seo-console' ),
				'id_slug'  => 'faq',
				'link'     => 'isPartOf',
			),
			'Question'                  => array(
				'label'     => __( 'Question', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'name', 'acceptedAnswer' ),
				'optional'  => array( 'text' ),
			),
			'Answer'                    => array(
				'label'     => __( 'Answer', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'text' ),
				'optional'  => array( 'url' ),
			),

			// HowTo.
			'HowTo'                     => array(
				'label'       => __( 'How-to', 'seo-console' ),
				'single'      => true,
				'required'    => array( 'name', 'step' ),
				'recommended' => array( 'image', 'totalTime', 'supply', 'tool', 'description' ),
				'optional'    => array( 'estimatedCost', 'url', 'isPartOf', 'mainEntityOfPage', 'video', 'inLanguage' ),
				'rich'        => self::RICH_REMOVED,
				'note'        => __( 'Google removed How-to rich results in 2023, so this will not add anything visible to search results. The markup can still help search engines and AI assistants follow your steps.', 'seo-console' ),
				'id_slug'     => 'howto',
				'link'        => 'mainEntityOfPage',
			),
			'HowToStep'                 => array(
				'label'     => __( 'Step', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'text' ),
				'optional'  => array( 'name', 'url', 'image', 'position' ),
			),
			'HowToSection'              => array(
				'label'     => __( 'Section', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'name', 'itemListElement' ),
				'optional'  => array( 'position' ),
				'kinds'     => array( 'itemListElement' => 'HowToStep' ),
			),
			'HowToSupply'               => array(
				'label'     => __( 'Supply', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'name' ),
			),
			'HowToTool'                 => array(
				'label'     => __( 'Tool', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'name' ),
			),
			'MonetaryAmount'            => array(
				'label'     => __( 'Amount', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'currency', 'value' ),
			),

			// Product.
			'Product'                   => array(
				'label'       => __( 'Product', 'seo-console' ),
				'single'      => true,
				'required'    => array( 'name' ),
				'one_of'      => array( array( 'offers', 'review', 'aggregateRating' ) ),
				'recommended' => array( 'image', 'description', 'sku', 'brand' ),
				'optional'    => array( 'mpn', 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14', 'color', 'material', 'category', 'url', 'mainEntityOfPage', 'isPartOf' ),
				'rich'        => self::RICH_ELIGIBLE,
				'note'        => __( 'Can show price, availability and review stars in Google. Only include prices, ratings and reviews that are real and shown on the page; WooCommerce, Rank Math and Yoast often output Product markup already.', 'seo-console' ),
				'id_slug'     => 'product',
				'link'        => 'mainEntityOfPage',
			),
			'Offer'                     => array(
				'label'       => __( 'Offer', 'seo-console' ),
				'top_level'   => false,
				'required'    => array( 'price', 'priceCurrency' ),
				'recommended' => array( 'availability', 'url' ),
				'optional'    => array( 'priceValidUntil', 'itemCondition', 'validFrom' ),
			),
			'AggregateOffer'            => array(
				'label'       => __( 'Price range offer', 'seo-console' ),
				'top_level'   => false,
				'required'    => array( 'lowPrice', 'priceCurrency' ),
				'recommended' => array( 'highPrice', 'offerCount' ),
				'optional'    => array( 'availability', 'url' ),
			),
			'AggregateRating'           => array(
				'label'     => __( 'Rating summary', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'ratingValue' ),
				'one_of'    => array( array( 'ratingCount', 'reviewCount' ) ),
				'optional'  => array( 'bestRating', 'worstRating', 'itemReviewed' ),
			),
			'Review'                    => array(
				'label'       => __( 'Review', 'seo-console' ),
				'top_level'   => false,
				'required'    => array( 'author', 'reviewRating' ),
				'recommended' => array( 'datePublished', 'reviewBody' ),
				'optional'    => array( 'name', 'itemReviewed', 'publisher' ),
			),
			'Rating'                    => array(
				'label'     => __( 'Rating', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'ratingValue' ),
				'optional'  => array( 'bestRating', 'worstRating' ),
			),
			'Brand'                     => array(
				'label'     => __( 'Brand', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'name' ),
				'optional'  => array( 'url', 'logo' ),
			),

			// Breadcrumbs.
			'BreadcrumbList'            => array(
				'label'    => __( 'Breadcrumbs', 'seo-console' ),
				'single'   => true,
				'required' => array( 'itemListElement' ),
				'kinds'    => array( 'itemListElement' => 'ListItem' ),
				'rich'     => self::RICH_ELIGIBLE,
				'note'     => __( 'Shows the page\'s position in your site instead of the raw URL (desktop results). Rank Math and Yoast output breadcrumbs markup already, so SEO Console only adds it when no SEO plugin does.', 'seo-console' ),
				'id_slug'  => 'breadcrumb',
				'link'     => 'none',
			),
			'ListItem'                  => array(
				'label'       => __( 'Breadcrumb item', 'seo-console' ),
				'top_level'   => false,
				'required'    => array( 'position', 'name' ),
				'recommended' => array( 'item' ),
			),

			// Organisations and people.
			'Organization'              => array(
				'label'       => __( 'Organization', 'seo-console' ),
				'single'      => true,
				'required'    => array( 'name' ),
				'recommended' => array( 'url', 'logo', 'sameAs' ),
				'optional'    => array( 'description', 'address', 'telephone', 'email', 'legalName', 'alternateName', 'foundingDate', 'contactPoint', 'parentOrganization', 'founder', 'slogan', 'image', 'areaServed' ),
				'rich'        => self::RICH_ELIGIBLE,
				'note'        => __( 'Tells Google your organization\'s name, logo and social profiles (used in the knowledge panel and logo in results). Rank Math and Yoast already output this site-wide, so SEO Console only adds it when no SEO plugin does.', 'seo-console' ),
				'id_slug'     => 'organization',
				'link'        => 'none',
				'site_level'  => true,
			),
			'LocalBusiness'             => array(
				'parent'      => 'Organization',
				'label'       => __( 'Local business', 'seo-console' ),
				'required'    => array( 'name', 'address' ),
				'recommended' => array( 'telephone', 'url', 'geo', 'openingHoursSpecification', 'priceRange', 'image' ),
				'optional'    => array( 'menu', 'servesCuisine', 'acceptsReservations', 'hasMap', 'paymentAccepted', 'currenciesAccepted' ),
				'kinds'       => array( 'address' => 'PostalAddress' ),
				'rich'        => self::RICH_ELIGIBLE,
				'note'        => $lb_note,
				'id_slug'     => 'localbusiness',
			),
			'ContactPoint'              => array(
				'label'       => __( 'Contact point', 'seo-console' ),
				'top_level'   => false,
				'recommended' => array( 'telephone', 'contactType' ),
				'optional'    => array( 'email', 'areaServed', 'availableLanguage', 'url' ),
			),
			'PostalAddress'             => array(
				'label'       => __( 'Address', 'seo-console' ),
				'top_level'   => false,
				'one_of'      => array( array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' ) ),
				'recommended' => array( 'streetAddress', 'addressLocality', 'postalCode', 'addressCountry' ),
				'optional'    => array( 'addressRegion' ),
			),
			'GeoCoordinates'            => array(
				'label'     => __( 'Map coordinates', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'latitude', 'longitude' ),
			),
			'OpeningHoursSpecification' => array(
				'label'     => __( 'Opening hours', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'dayOfWeek', 'opens', 'closes' ),
				'optional'  => array( 'validFrom', 'validThrough' ),
			),
			'Person'                    => array(
				'label'       => __( 'Person', 'seo-console' ),
				'required'    => array( 'name' ),
				'recommended' => array( 'url', 'image', 'sameAs', 'jobTitle' ),
				'optional'    => array( 'worksFor', 'description', 'alternateName', 'email', 'telephone', 'address', 'mainEntityOfPage', 'isPartOf' ),
				'rich'        => self::RICH_NONE,
				'note'        => __( 'Describes a person (an author or team member). There is no rich result for it on its own, but it helps search engines connect content to its author.', 'seo-console' ),
				'id_slug'     => 'person',
				'link'        => 'mainEntityOfPage',
			),
			'PerformingGroup'           => array(
				'label'     => __( 'Performing group', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'name' ),
				'optional'  => array( 'url', 'sameAs' ),
			),

			// Video.
			'VideoObject'               => array(
				'label'       => __( 'Video', 'seo-console' ),
				'required'    => array( 'name', 'thumbnailUrl', 'uploadDate' ),
				'recommended' => array( 'description', 'duration', 'embedUrl', 'contentUrl' ),
				'optional'    => array( 'url', 'isPartOf', 'mainEntityOfPage', 'inLanguage', 'publisher', 'author' ),
				'rich'        => self::RICH_ELIGIBLE,
				'note'        => __( 'Can show a video thumbnail and key moments in Google and helps the video appear in video search. Google needs the video\'s title, thumbnail and upload date; we cannot read the upload date from an embed, so you may need to fill it in.', 'seo-console' ),
				'id_slug'     => 'video',
				'link'        => 'isPartOf',
			),
			'ImageObject'               => array(
				'label'     => __( 'Image', 'seo-console' ),
				'top_level' => false,
				'one_of'    => array( array( 'url', 'contentUrl' ) ),
				'optional'  => array( 'width', 'height', 'caption', 'name' ),
			),

			// Events.
			'Event'                     => array(
				'label'       => __( 'Event', 'seo-console' ),
				'required'    => array( 'name', 'startDate', 'location' ),
				'recommended' => array( 'description', 'endDate', 'eventStatus', 'eventAttendanceMode', 'image', 'offers', 'organizer', 'performer' ),
				'optional'    => array( 'url', 'isPartOf', 'mainEntityOfPage', 'inLanguage' ),
				'rich'        => self::RICH_ELIGIBLE,
				'note'        => __( 'Can show the event date and place in Google\'s event listings. Use it only for real events with a date, not for promotions or courses.', 'seo-console' ),
				'id_slug'     => 'event',
				'link'        => 'mainEntityOfPage',
			),
			'Place'                     => array(
				'label'     => __( 'Place', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'address' ),
				'optional'  => array( 'name', 'geo', 'url', 'telephone' ),
			),
			'VirtualLocation'           => array(
				'label'     => __( 'Online location', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'url' ),
				'optional'  => array( 'name' ),
			),

			// Recipe.
			'Recipe'                    => array(
				'label'       => __( 'Recipe', 'seo-console' ),
				'single'      => true,
				'required'    => array( 'name', 'image' ),
				'recommended' => array( 'author', 'datePublished', 'description', 'recipeIngredient', 'recipeInstructions', 'prepTime', 'cookTime', 'totalTime', 'recipeYield', 'recipeCategory', 'recipeCuisine', 'keywords', 'nutrition' ),
				'optional'    => array( 'aggregateRating', 'video', 'url', 'isPartOf', 'mainEntityOfPage', 'review' ),
				'rich'        => self::RICH_ELIGIBLE,
				'note'        => __( 'Can show a recipe card with image, cooking time and ratings in Google. Ratings must come from real visitor reviews.', 'seo-console' ),
				'id_slug'     => 'recipe',
				'link'        => 'mainEntityOfPage',
			),
			'NutritionInformation'      => array(
				'label'       => __( 'Nutrition', 'seo-console' ),
				'top_level'   => false,
				'recommended' => array( 'calories' ),
				'optional'    => array( 'servingSize' ),
			),

			// Service.
			'Service'                   => array(
				'label'       => __( 'Service', 'seo-console' ),
				'required'    => array( 'name' ),
				'recommended' => array( 'description', 'provider', 'serviceType', 'areaServed' ),
				'optional'    => array( 'offers', 'url', 'image', 'category', 'isPartOf', 'mainEntityOfPage', 'brand' ),
				'rich'        => self::RICH_NONE,
				'note'        => __( 'Describes a service you offer. Google has no rich result for it, but it helps search engines and AI assistants understand what the page sells and where.', 'seo-console' ),
				'id_slug'     => 'service',
				'link'        => 'mainEntityOfPage',
			),

			// Site-level pieces output by SEO plugins.
			'WebSite'                   => array(
				'label'                => __( 'Website', 'seo-console' ),
				'single'               => true,
				'required'             => array( 'name', 'url' ),
				'optional'             => array( 'potentialAction', 'publisher', 'inLanguage', 'alternateName', 'description' ),
				'rich'                 => self::RICH_NONE,
				'note'                 => __( 'Gives Google your site name. Google retired the sitelinks search box (SearchAction) in November 2024. Rank Math and Yoast already output this, so SEO Console does not propose it.', 'seo-console' ),
				'id_slug'              => 'website',
				'link'                 => 'none',
				'skip_with_seo_plugin' => true,
				'site_level'           => true,
			),
			'SearchAction'              => array(
				'label'     => __( 'Site search', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'target', 'query-input' ),
			),
			'EntryPoint'                => array(
				'label'     => __( 'Entry point', 'seo-console' ),
				'top_level' => false,
				'required'  => array( 'urlTemplate' ),
			),
			'WebPage'                   => array(
				'label'                => __( 'Web page', 'seo-console' ),
				'optional'             => array( 'name', 'url', 'description', 'isPartOf', 'about', 'inLanguage', 'datePublished', 'dateModified', 'image' ),
				'rich'                 => self::RICH_NONE,
				'note'                 => __( 'The page itself. Rank Math and Yoast output it; SEO Console links its own markup to it.', 'seo-console' ),
				'id_slug'              => 'webpage',
				'link'                 => 'none',
				'skip_with_seo_plugin' => true,
			),
		);

		$restaurant_extra = array( 'servesCuisine', 'menu', 'acceptsReservations' );
		foreach ( self::local_business_types() as $sub ) {
			if ( 'LocalBusiness' === $sub ) {
				continue;
			}
			$defs[ $sub ] = array(
				'parent' => 'LocalBusiness',
				/* translators: %s: schema.org business type such as Restaurant. */
				'label'  => sprintf( __( 'Local business (%s)', 'seo-console' ), $sub ),
			);
			if ( in_array( $sub, array( 'Restaurant', 'CafeOrCoffeeShop', 'BarOrPub', 'Bakery' ), true ) ) {
				$defs[ $sub ]['recommended'] = array_merge( array( 'telephone', 'url', 'geo', 'openingHoursSpecification', 'priceRange', 'image' ), $restaurant_extra );
			}
		}

		return $defs;
	}

	/**
	 * All resolved definitions keyed by type.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		if ( null === self::$resolved ) {
			$raw      = self::definitions();
			$resolved = array();
			foreach ( array_keys( $raw ) as $type ) {
				$resolved[ $type ] = self::resolve( $type, $raw );
			}
			self::$resolved = $resolved;
		}
		return self::$resolved;
	}

	/**
	 * Applies inheritance: lists are merged, scalar keys fall back to the parent.
	 *
	 * @param string $type Type.
	 * @param array  $raw  Raw definitions.
	 * @return array
	 */
	private static function resolve( string $type, array $raw ): array {
		$def    = $raw[ $type ];
		$chain  = array( $type );
		$parent = isset( $def['parent'] ) ? $def['parent'] : null;
		$base   = array(
			'label'                => $type,
			'parent'               => null,
			'top_level'            => true,
			'single'               => false,
			'required'             => array(),
			'one_of'               => array(),
			'recommended'          => array(),
			'optional'             => array(),
			'kinds'                => array(),
			'rich'                 => self::RICH_NONE,
			'note'                 => '',
			'id_slug'              => strtolower( $type ),
			'link'                 => 'none',
			'skip_with_seo_plugin' => false,
			'site_level'           => false,
		);
		if ( null !== $parent && isset( $raw[ $parent ] ) ) {
			$base           = self::resolve( $parent, $raw );
			$chain          = array_merge( array( $type ), $base['chain'] );
			$base['parent'] = $parent;
		}
		$out = $base;
		foreach ( $def as $key => $value ) {
			if ( 'parent' === $key ) {
				continue;
			}
			if ( in_array( $key, array( 'optional', 'recommended' ), true ) ) {
				$out[ $key ] = array_values( array_unique( array_merge( $base[ $key ], $value ) ) );
			} elseif ( 'kinds' === $key ) {
				$out['kinds'] = array_merge( $base['kinds'], $value );
			} else {
				$out[ $key ] = $value;
			}
		}
		$out['chain']   = $chain;
		$out['allowed'] = array_values( array_unique( array_merge( $out['required'], self::flatten( $out['one_of'] ), $out['recommended'], $out['optional'] ) ) );
		return $out;
	}

	/**
	 * @param array $groups List of lists.
	 * @return string[]
	 */
	private static function flatten( array $groups ): array {
		$out = array();
		foreach ( $groups as $group ) {
			$out = array_merge( $out, (array) $group );
		}
		return $out;
	}

	/**
	 * Resolved definition of one type.
	 *
	 * @param string $type Type.
	 * @return array|null
	 */
	public static function get( string $type ): ?array {
		$all = self::all();
		return isset( $all[ $type ] ) ? $all[ $type ] : null;
	}

	/**
	 * @param string $type Type.
	 */
	public static function is_known( string $type ): bool {
		return null !== self::get( $type );
	}

	/**
	 * Kind spec of a property on a type (type override, then global map).
	 *
	 * @param string $type Type.
	 * @param string $prop Property.
	 */
	public static function kind( string $type, string $prop ): string {
		$def = self::get( $type );
		if ( $def && isset( $def['kinds'][ $prop ] ) ) {
			return $def['kinds'][ $prop ];
		}
		$kinds = self::property_kinds();
		return isset( $kinds[ $prop ] ) ? $kinds[ $prop ] : 'text';
	}

	/**
	 * @param string $type Type.
	 * @return string[]
	 */
	public static function allowed_properties( string $type ): array {
		$def = self::get( $type );
		return $def ? $def['allowed'] : array();
	}

	/**
	 * @param string $type Type.
	 * @return string[]
	 */
	public static function required( string $type ): array {
		$def = self::get( $type );
		return $def ? $def['required'] : array();
	}

	/**
	 * @param string $type Type.
	 * @return string[]
	 */
	public static function recommended( string $type ): array {
		$def = self::get( $type );
		return $def ? $def['recommended'] : array();
	}

	/**
	 * Plain-English note about rich results for a type.
	 *
	 * @param string $type Type.
	 */
	public static function note( string $type ): string {
		$def = self::get( $type );
		return $def ? $def['note'] : '';
	}

	/**
	 * Rich result status (one of the RICH_* constants).
	 *
	 * @param string $type Type.
	 */
	public static function rich_result( string $type ): string {
		$def = self::get( $type );
		return $def ? $def['rich'] : self::RICH_NONE;
	}

	/**
	 * Human label ("FAQ page").
	 *
	 * @param string $type Type.
	 */
	public static function label( string $type ): string {
		$def = self::get( $type );
		return $def ? $def['label'] : $type;
	}

	/**
	 * Slug used in stable @ids ({permalink}#seoc-<slug>).
	 *
	 * @param string $type Type.
	 */
	public static function id_slug( string $type ): string {
		$def = self::get( $type );
		return $def ? $def['id_slug'] : strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $type ) );
	}

	/**
	 * Whether $type is $ancestor or inherits from it.
	 *
	 * @param string $type     Type.
	 * @param string $ancestor Ancestor type.
	 */
	public static function is_a( string $type, string $ancestor ): bool {
		$def = self::get( $type );
		return $def ? in_array( $ancestor, $def['chain'], true ) : $type === $ancestor;
	}

	/**
	 * Family used for de-duplication: two nodes of the same family on one page are duplicates.
	 * Works for types we do not know too (e.g. "TechArticle" from another plugin).
	 *
	 * @param string $type Type.
	 */
	public static function group( string $type ): string {
		if ( self::is_a( $type, 'LocalBusiness' ) ) {
			return 'LocalBusiness';
		}
		if ( self::is_a( $type, 'Article' ) || preg_match( '/(Article|Posting)$/', $type ) ) {
			return 'Article';
		}
		if ( self::is_a( $type, 'Organization' ) || in_array( $type, array( 'Corporation', 'NGO', 'OnlineBusiness', 'OnlineStore', 'EducationalOrganization', 'GovernmentOrganization', 'NewsMediaOrganization' ), true ) ) {
			return 'Organization';
		}
		if ( in_array( $type, array( 'Product', 'ProductGroup', 'IndividualProduct', 'ProductModel' ), true ) ) {
			return 'Product';
		}
		if ( 'FAQPage' !== $type && preg_match( '/^(WebPage|[A-Z][A-Za-z]*Page)$/', $type ) ) {
			return 'WebPage';
		}
		return $type;
	}

	/**
	 * Types that may be used as top-level nodes in a post's schema.
	 *
	 * @return string[]
	 */
	public static function top_level_types(): array {
		$out = array();
		foreach ( self::all() as $type => $def ) {
			if ( $def['top_level'] ) {
				$out[] = $type;
			}
		}
		return $out;
	}

	/**
	 * Picks the most specific known type from an @type value (string or list).
	 *
	 * @param mixed $type_value Value of @type.
	 * @return string|null
	 */
	public static function primary( $type_value ): ?string {
		$best  = null;
		$depth = -1;
		foreach ( (array) $type_value as $type ) {
			if ( ! is_string( $type ) ) {
				continue;
			}
			$def = self::get( $type );
			if ( $def && count( $def['chain'] ) > $depth ) {
				$best  = $type;
				$depth = count( $def['chain'] );
			}
		}
		return $best;
	}

	/**
	 * Human label of a property in error messages.
	 *
	 * @param string $prop Property.
	 */
	public static function property_label( string $prop ): string {
		$labels = array(
			'mainEntity'                => __( 'questions', 'seo-console' ),
			'acceptedAnswer'            => __( 'answer', 'seo-console' ),
			'step'                      => __( 'steps', 'seo-console' ),
			'itemListElement'           => __( 'items', 'seo-console' ),
			'offers'                    => __( 'price offer', 'seo-console' ),
			'aggregateRating'           => __( 'rating summary', 'seo-console' ),
			'reviewRating'              => __( 'rating', 'seo-console' ),
			'openingHoursSpecification' => __( 'opening hours', 'seo-console' ),
			'geo'                       => __( 'map coordinates', 'seo-console' ),
			'thumbnailUrl'              => __( 'thumbnail URL', 'seo-console' ),
			'uploadDate'                => __( 'upload date', 'seo-console' ),
			'startDate'                 => __( 'start date', 'seo-console' ),
			'endDate'                   => __( 'end date', 'seo-console' ),
			'datePublished'             => __( 'publish date', 'seo-console' ),
			'dateModified'              => __( 'last-updated date', 'seo-console' ),
			'priceCurrency'             => __( 'currency', 'seo-console' ),
			'ratingValue'               => __( 'rating value', 'seo-console' ),
			'name'                      => __( 'name', 'seo-console' ),
			'text'                      => __( 'text', 'seo-console' ),
		);
		return isset( $labels[ $prop ] ) ? $labels[ $prop ] : $prop;
	}

	/** Clears the cache (tests, locale switch). */
	public static function reset(): void {
		self::$resolved = null;
	}
}
