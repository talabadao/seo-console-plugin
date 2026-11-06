/**
 * REST calls of the Schema module (routes under seo-console/v1/schema/…).
 *
 * GET  schema/posts?post_type&status(has_ours|none|needs_input)&search&page&per_page
 *      → { items: PostRow[], total, page, per_page, seo_plugin, ai, batch, post_types: [ { value, label } ] }
 *      PostRow = { id, title, post_type, post_type_label, status, edit_link, view_link, modified,
 *                  detected: [ { type, label, source, source_label, detail } ], ours: string[],
 *                  needs_input: Incomplete[], pending_suggestion: { id, source, types, created_at } | null }
 * GET  schema/posts/{id} → { post: PostRow, stored, validation, preview: { mode, pieces, hidden, json },
 *                            incomplete: Incomplete[], pending_suggestion, seo_plugin }
 * POST schema/posts/{id}/remove → { change }
 * POST schema/suggest { post_ids, use_ai } → { queued, count, results? }
 * POST schema/validate { nodes, post_id? } → { valid, errors, warnings }
 * GET  schema/site?<business keys> → { type, node, errors, warnings, will_output, reason, json, note, field_errors }
 * GET  schema/types → { items: [ { type, label, rich_result, note, required, recommended, ai } ] }
 */
import { apiGet, apiPost } from '../shared';

export const getPosts = ( query, signal ) =>
	apiGet( 'schema/posts', query, signal );

export const getPost = ( id, signal ) =>
	apiGet( `schema/posts/${ parseInt( id, 10 ) }`, {}, signal );

export const removeSchema = ( id ) =>
	apiPost( `schema/posts/${ parseInt( id, 10 ) }/remove` );

export const suggest = ( postIds, useAi ) =>
	apiPost( 'schema/suggest', {
		post_ids: postIds.map( ( id ) => parseInt( id, 10 ) ),
		use_ai: !! useAi,
	} );

export const validateNodes = ( nodes, postId ) =>
	apiPost( 'schema/validate', { nodes, post_id: postId || 0 } );

export const getSite = ( values, signal ) =>
	apiGet( 'schema/site', values || {}, signal );

export const getTypes = ( signal ) => apiGet( 'schema/types', {}, signal );

/**
 * Plain-English name of the SEO plugin mode.
 *
 * @param {string} mode 'rankmath' | 'yoast' | 'none'.
 * @return {string} Name.
 */
export function seoPluginName( mode ) {
	if ( mode === 'rankmath' ) {
		return 'Rank Math';
	}
	if ( mode === 'yoast' ) {
		return 'Yoast SEO';
	}
	return '';
}
