/**
 * Audit REST client (routes under seo-console/v1/audit, see includes/Modules/Audit/AuditController.php).
 */
import { apiGet, apiPost } from '../shared';

export const getRuns = ( query = {}, signal ) =>
	apiGet( 'audit/runs', query, signal );
export const getRun = ( id, signal ) =>
	apiGet( `audit/runs/${ id }`, {}, signal );
export const startRun = () => apiPost( 'audit/runs' );
export const cancelRun = ( id ) => apiPost( `audit/runs/${ id }/cancel` );
export const advanceRun = ( id ) => apiPost( `audit/runs/${ id }/advance` );
export const getIssueGroups = ( id, query = {}, signal ) =>
	apiGet( `audit/runs/${ id }/issues`, { ...query, group: 1 }, signal );
export const getIssues = ( id, query = {}, signal ) =>
	apiGet( `audit/runs/${ id }/issues`, query, signal );
export const getPages = ( id, query = {}, signal ) =>
	apiGet( `audit/runs/${ id }/pages`, query, signal );
export const getPage = ( pageId, signal ) =>
	apiGet( `audit/pages/${ pageId }`, {}, signal );
export const getRobots = ( query = {}, signal ) =>
	apiGet( 'audit/robots', query, signal );
export const testRobots = ( url, userAgent ) =>
	apiGet( 'audit/robots/test', { url, user_agent: userAgent } );
export const getSitemaps = ( signal ) => apiGet( 'audit/sitemaps', {}, signal );
export const requestAiFixes = ( runId, ruleIds = [] ) =>
	apiPost( 'audit/ai-fixes', { run_id: runId, rule_ids: ruleIds } );
export const getRules = ( signal ) => apiGet( 'audit/rules', {}, signal );
