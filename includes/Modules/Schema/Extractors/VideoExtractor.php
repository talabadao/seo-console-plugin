<?php
/**
 * VideoObject from YouTube / Vimeo embeds (embed blocks, iframes, bare URLs on their own line).
 * The video title comes from the block caption, the iframe title, or WordPress's cached oEmbed
 * HTML. Upload date (and Vimeo thumbnails) cannot be read from an embed, so they are reported as
 * missing for the user to fill in — never guessed.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema\Extractors;

use SEOConsole\Modules\Schema\Html;

defined( 'ABSPATH' ) || exit;

final class VideoExtractor extends AbstractExtractor {

	/** Max videos per post. */
	const MAX_VIDEOS = 5;

	const YOUTUBE = '#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^"\'\s<>]*&(?:amp;)?)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#i';

	const VIMEO = '#(?:player\.)?vimeo\.com/(?:video/|channels/[^/\s"\']+/|groups/[^/\s"\']+/videos/)?(\d{6,12})#i';

	/** {@inheritDoc} */
	public function type(): string {
		return 'VideoObject';
	}

	/** {@inheritDoc} */
	public function extract( \WP_Post $post ): array {
		$content = (string) $post->post_content;
		$videos  = array();

		foreach ( self::flatten_blocks( parse_blocks( $content ) ) as $block ) {
			$name = (string) $block['blockName'];
			if ( 'core/embed' !== $name && 0 !== strpos( $name, 'core-embed/' ) ) {
				continue;
			}
			$url   = isset( $block['attrs']['url'] ) ? (string) $block['attrs']['url'] : '';
			$video = self::parse_url( $url );
			if ( null === $video ) {
				continue;
			}
			if ( preg_match( '#<figcaption[^>]*>(.*?)</figcaption>#is', (string) $block['innerHTML'], $m ) ) {
				$video['name'] = Html::to_text( $m[1] );
			}
			$videos[ $video['key'] ] = $video;
		}

		if ( preg_match_all( '#<iframe\b[^>]*>#i', $content, $iframes ) ) {
			foreach ( $iframes[0] as $tag ) {
				if ( ! preg_match( '#\ssrc\s*=\s*["\']([^"\']+)#i', $tag, $src ) ) {
					continue;
				}
				$video = self::parse_url( html_entity_decode( $src[1] ) );
				if ( null === $video || isset( $videos[ $video['key'] ] ) ) {
					continue;
				}
				if ( preg_match( '#\stitle\s*=\s*["\']([^"\']+)#i', $tag, $title ) ) {
					$video['name'] = self::useful_title( Html::clean_text( $title[1] ) );
				}
				$videos[ $video['key'] ] = $video;
			}
		}

		// Classic editor: a bare URL on its own line is auto-embedded by WordPress.
		if ( preg_match_all( '#^\s*(https?://\S+)\s*$#mi', wp_strip_all_tags( $content ), $lines ) ) {
			foreach ( $lines[1] as $line ) {
				$video = self::parse_url( $line );
				if ( null !== $video && ! isset( $videos[ $video['key'] ] ) ) {
					$videos[ $video['key'] ] = $video;
				}
			}
		}

		$out = array();
		foreach ( array_slice( $videos, 0, self::MAX_VIDEOS ) as $video ) {
			if ( empty( $video['name'] ) ) {
				$video['name'] = self::oembed_title( $post->ID, $video['id'] );
			}
			$node  = array(
				'@type'        => 'VideoObject',
				'name'         => isset( $video['name'] ) ? $video['name'] : '',
				'embedUrl'     => $video['embed'],
				'url'          => $video['watch'],
				'thumbnailUrl' => $video['thumbnail'],
				'uploadDate'   => '',
			);
			$label = 'youtube' === $video['provider'] ? 'YouTube' : 'Vimeo';
			/* translators: 1: YouTube or Vimeo, 2: video URL. */
			$out[] = $this->candidate( $node, sprintf( __( 'Found an embedded %1$s video (%2$s).', 'seo-console' ), $label, $video['watch'] ), array( 'description' ) );
		}
		return $out;
	}

	/**
	 * Recognises a YouTube or Vimeo URL.
	 *
	 * @param string $url URL.
	 * @return array|null [ key, provider, id, embed, watch, thumbnail ]
	 */
	public static function parse_url( string $url ): ?array {
		if ( preg_match( self::YOUTUBE, $url, $m ) ) {
			return array(
				'key'       => 'yt:' . $m[1],
				'provider'  => 'youtube',
				'id'        => $m[1],
				'embed'     => 'https://www.youtube.com/embed/' . $m[1],
				'watch'     => 'https://www.youtube.com/watch?v=' . $m[1],
				'thumbnail' => 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg',
			);
		}
		if ( preg_match( self::VIMEO, $url, $m ) ) {
			return array(
				'key'       => 'vimeo:' . $m[1],
				'provider'  => 'vimeo',
				'id'        => $m[1],
				'embed'     => 'https://player.vimeo.com/video/' . $m[1],
				'watch'     => 'https://vimeo.com/' . $m[1],
				'thumbnail' => '',
			);
		}
		return null;
	}

	/**
	 * Title from WordPress's cached oEmbed HTML (post meta _oembed_<hash>), if any.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $video_id Video id.
	 */
	private static function oembed_title( int $post_id, string $video_id ): string {
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( 0 !== strpos( (string) $key, '_oembed_' ) || 0 === strpos( (string) $key, '_oembed_time_' ) ) {
				continue;
			}
			$html = is_array( $values ) && isset( $values[0] ) ? (string) $values[0] : '';
			if ( false === strpos( $html, $video_id ) ) {
				continue;
			}
			if ( preg_match( '#\stitle\s*=\s*["\']([^"\']+)#i', $html, $m ) ) {
				return self::useful_title( Html::clean_text( $m[1] ) );
			}
		}
		return '';
	}

	/**
	 * Drops generic iframe titles ("YouTube video player").
	 *
	 * @param string $title Title.
	 */
	private static function useful_title( string $title ): string {
		return preg_match( '/^(youtube|vimeo)( video)?( player| embed)?$/i', $title ) ? '' : $title;
	}

	/**
	 * All blocks, depth first.
	 *
	 * @param array[] $blocks Blocks.
	 * @return array[]
	 */
	private static function flatten_blocks( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$out[] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, self::flatten_blocks( $block['innerBlocks'] ) );
			}
		}
		return $out;
	}
}
