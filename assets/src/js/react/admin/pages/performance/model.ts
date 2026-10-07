import { __, _n, sprintf } from '@wordpress/i18n';
import {
	CacheActionResult,
	CacheResource,
	CacheVariantResponse,
	PerformanceJob,
	PerformanceSettings,
	PerformanceSummary,
	PendingResourceAction,
	PendingResourceActions,
	ResourceFilters,
	ResourceSort,
	WarmQueueStatus,
} from './types';

export function pageCacheMode(pageCache: PerformanceSummary['page_cache']) {
	if (pageCache.provider.managed && pageCache.provider.available) return 'managed';
	if (!pageCache.enabled) return 'disabled';
	if (!pageCache.provider.available || pageCache.state === 'needs-attention') return 'attention';

	return 'ready';
}

export function cacheActionMessage(action: string, result: CacheActionResult) {
	if (result.code === 'no-resources') {
		return {
			status: 'info' as const,
			message: __('No matching Directorist pages were found.', 'directorist'),
		};
	}

	return {
		status: 'success' as const,
		message: action.startsWith('warm')
			? __('Preloading has been queued.', 'directorist')
			: __('Cached pages were purged.', 'directorist'),
	};
}

export function cacheActionRefreshMode(action: string): 'silent' | 'visible' {
	return action.endsWith('-selected') ? 'silent' : 'visible';
}

export function variantOutcome(result: CacheVariantResponse) {
	if (!result.exact && result.code === 'provider-managed') return 'provider-managed';
	if (result.exact && result.code === 'uncached') return 'uncached';
	if (result.items.length) return 'ready';

	return 'unavailable';
}

export function settingsAreEqual(first: PerformanceSettings, second: PerformanceSettings) {
	return first.enabled === second.enabled
		&& first.cache_duration === second.cache_duration
		&& first.cache_filtered_results === second.cache_filtered_results;
}

export function resourceFilterCount(filters: ResourceFilters) {
	return Object.values(filters).filter((value) => value > 0).length;
}

export function resourceScope(type: string, search: string, filters: ResourceFilters) {
	return {
		type: resourceFilterCount(filters) > 0 ? 'listing' : type,
		search,
		...filters,
	};
}

export function resourceActionUrlChunks(resources: CacheResource[], limit = 50) {
	const urls = Array.from(new Set(resources.flatMap((resource) => resource.variant_urls?.length ? resource.variant_urls : [resource.url])));
	const chunkSize = Math.max(1, limit);

	return Array.from({ length: Math.ceil(urls.length / chunkSize) }, (_, index) => urls.slice(index * chunkSize, (index + 1) * chunkSize));
}

export function markPendingResourceActions(current: PendingResourceActions, resources: CacheResource[], action: PendingResourceAction): PendingResourceActions {
	return resources.reduce((next, resource) => ({ ...next, [resource.id]: action }), { ...current });
}

export function clearPendingResourceActions(current: PendingResourceActions, resources: CacheResource[]): PendingResourceActions {
	const next = { ...current };

	resources.forEach((resource) => delete next[resource.id]);

	return next;
}

export function markPurgedResourceRows(current: CacheResource[], requested: CacheResource[], completedUrls: string[]): CacheResource[] {
	const completed = new Set(completedUrls);
	const purgedIds = new Set(requested.filter((resource) => {
		const urls = resource.variant_urls?.length ? resource.variant_urls : [resource.url];

		return resource.cache.exact && urls.length > 0 && urls.every((url) => completed.has(url));
	}).map((resource) => resource.id));

	return current.map((resource) => {
		if (!purgedIds.has(resource.id)) return resource;

		return {
			...resource,
			cache: {
				...resource.cache,
				state: 'uncached',
				created_at: 0,
				expires_at: 0,
				body_size: 0,
				failure_code: '',
			},
		};
	});
}

export function resourceActionIsPending(current: PendingResourceActions, resourceId: string) {
	return Boolean(current[resourceId]);
}

export function warmQueueIsActive(queue: WarmQueueStatus) {
	return Boolean(queue.running || (queue.queued || 0) > 0 || (queue.deferred || 0) > 0);
}

export function warmQueuePollDelay(queue: WarmQueueStatus, now = Math.floor(Date.now() / 1000)) {
	const recoveryAt = Math.max(queue.recovery_at || 0, queue.open_until || 0);

	if (recoveryAt > now) {
		return Math.min(10000, Math.max(1500, (recoveryAt - now) * 1000));
	}

	return 1500;
}

export function nextModifiedSort(sort: ResourceSort): ResourceSort {
	return {
		orderby: 'modified',
		order: sort.orderby === 'modified' && sort.order === 'DESC' ? 'ASC' : 'DESC',
	};
}

export function nextCacheStateSort(sort: ResourceSort): ResourceSort {
	return {
		orderby: 'cache_state',
		order: sort.orderby === 'cache_state' && sort.order === 'ASC' ? 'DESC' : 'ASC',
	};
}

export function paginationMode(total: number, pages: number): 'hidden' | 'summary' | 'navigation' {
	if (total < 1) return 'hidden';
	return pages > 1 ? 'navigation' : 'summary';
}

export function isInteractivePerformanceJob(job: PerformanceJob) {
	return ['queued', 'running', 'verifying'].includes(job.state);
}

export function performanceJobOutcome(job: PerformanceJob): 'active' | 'partial' | 'failed' | 'completed' | 'idle' {
	if (isInteractivePerformanceJob(job)) return 'active';
	if (job.state === 'failed' && (job.warmed || 0) > 0 && (job.failed || 0) > 0) return 'partial';
	if (job.state === 'failed') return 'failed';
	if (job.state === 'completed') return 'completed';

	return 'idle';
}

export function performanceJobFailureMessage(job: PerformanceJob): string {
	const code = job.failure_code || '';
	const count = Math.max(1, job.failure_count || job.failed || 1);
	const type = job.scope?.type || 'all';
	let subject: string;

	switch (type) {
		case 'listing': subject = sprintf(_n('%d listing page', '%d listing pages', count, 'directorist'), count); break;
		case 'archive': subject = sprintf(_n('%d archive page', '%d archive pages', count, 'directorist'), count); break;
		case 'search': subject = sprintf(_n('%d search page', '%d search pages', count, 'directorist'), count); break;
		case 'page': subject = sprintf(_n('%d page', '%d pages', count, 'directorist'), count); break;
		default: subject = sprintf(_n('%d public page', '%d public pages', count, 'directorist'), count);
	}

	if (code === 'http-status-404') {
		return sprintf(
			count === 1
				? __('%s returned 404. Check its permalink and published status, then retry.', 'directorist')
				: __('%s returned 404. Check their permalinks and published status, then retry.', 'directorist'),
			subject
		);
	}

	if (['http-status-401', 'http-status-403'].includes(code)) {
		return sprintf(__('%s could not be reached anonymously. Remove access restrictions for public preloading, then retry.', 'directorist'), subject);
	}

	if (['uncached', 'invalid-cache-key'].includes(code)) {
		return count === 1
			? __('The page loaded but did not produce a cache entry. Check page-cache eligibility, then retry.', 'directorist')
			: sprintf(__('%s loaded but did not produce cache entries. Check page-cache eligibility, then retry.', 'directorist'), subject);
	}

	if (code === 'incomplete-warm-results') {
		return __('Some background cache requests did not return a final result. Retry the failed pages.', 'directorist');
	}

	if (code.startsWith('http-status-')) {
		return sprintf(__('%s returned an unsuccessful HTTP response. Open the affected public URLs, then retry.', 'directorist'), subject);
	}

	return sprintf(__('%s could not be cached. Open the affected public URLs to verify their responses, then retry.', 'directorist'), subject);
}

export function performanceFailureLabel(code: string): string {
	if (code === 'http-status-404') return __('Not found (404)', 'directorist');
	if (['http-status-401', 'http-status-403'].includes(code)) return __('Access restricted', 'directorist');
	if (['uncached', 'invalid-cache-key'].includes(code)) return __('No cache entry', 'directorist');
	if (code === 'incomplete-warm-results') return __('No final response', 'directorist');
	if (code.startsWith('http-status-')) return sprintf(__('HTTP %s', 'directorist'), code.slice('http-status-'.length));

	return __('Preload failed', 'directorist');
}

function comparableUrl(url: string): string {
	try {
		const parsed = new URL(url);
		const path = parsed.pathname.replace(/\/+$/, '') || '/';

		return `${parsed.origin.toLowerCase()}${path}`;
	} catch (error) {
		return '';
	}
}

export function selectedWarmValidationMessage(resources: CacheResource[], siteHomeUrl: string): string {
	const home = comparableUrl(siteHomeUrl);
	const invalid = resources.find((resource) => resource.type === 'listing'
		&& [resource.url, ...(resource.variant_urls || [])].some((url) => comparableUrl(url) === home));

	if (!invalid) return '';

	return sprintf(
		__('%s does not have a valid public listing permalink. Update the listing permalink and published status before preloading it.', 'directorist'),
		invalid.title
	);
}

export function selectedWarmFailureMessage(requested: CacheResource[], refreshed: CacheResource[]): string {
	const requestedIds = new Set(requested.map((resource) => resource.id));
	const failed = refreshed.filter((resource) => requestedIds.has(resource.id) && resource.cache.state === 'failed');

	if (!failed.length) return '';
	if (failed.length === 1) {
		return sprintf(__('%s could not be cached. Open its public URL to verify the permalink and page response, then retry.', 'directorist'), failed[0].title);
	}

	return sprintf(_n('%d selected page could not be cached. Open its public URL, then retry.', '%d selected pages could not be cached. Open their public URLs, then retry.', failed.length, 'directorist'), failed.length);
}

export function selectedWarmSuccessMessage(resources: CacheResource[]): string {
	return sprintf(
		_n('%d selected page was preloaded.', '%d selected pages were preloaded.', resources.length, 'directorist'),
		resources.length
	);
}

export function performanceJobDescription(job: PerformanceJob): string {
	const type = job.scope?.type || 'all';
	const total = Math.max(0, job.total || 0);

	if (total < 1) {
		switch (type) {
			case 'listing': return __('Finding matching listings', 'directorist');
			case 'archive': return __('Finding matching archives', 'directorist');
			case 'page': return __('Finding matching pages', 'directorist');
			case 'search': return __('Finding matching search pages', 'directorist');
			default: return __('Finding matching content', 'directorist');
		}
	}

	if (job.action === 'purge') {
		const processed = Math.max(0, job.processed || 0);

		switch (type) {
			case 'listing': return sprintf(_n('%1$d of %2$d listing purged', '%1$d of %2$d listings purged', total, 'directorist'), processed, total);
			case 'archive': return sprintf(_n('%1$d of %2$d archive purged', '%1$d of %2$d archives purged', total, 'directorist'), processed, total);
			case 'page': return sprintf(_n('%1$d of %2$d page purged', '%1$d of %2$d pages purged', total, 'directorist'), processed, total);
			case 'search': return sprintf(_n('%1$d of %2$d search page purged', '%1$d of %2$d search pages purged', total, 'directorist'), processed, total);
			default: return sprintf(_n('%1$d of %2$d content item purged', '%1$d of %2$d content items purged', total, 'directorist'), processed, total);
		}
	}

	const finished = Math.max(0, (job.warmed || 0) + (job.failed || 0));
	const queued = Math.max(0, job.queued || 0);
	const discovered = Math.max(0, job.discovered || 0);
	let progress: string;
	let found: string;

	switch (type) {
		case 'listing':
			progress = sprintf(_n('%1$d of %2$d listing page finished', '%1$d of %2$d listing pages finished', queued, 'directorist'), finished, queued);
			found = sprintf(_n('%d listing found', '%d listings found', discovered, 'directorist'), discovered);
			break;
		case 'archive':
			progress = sprintf(_n('%1$d of %2$d archive page finished', '%1$d of %2$d archive pages finished', queued, 'directorist'), finished, queued);
			found = sprintf(_n('%d archive found', '%d archives found', discovered, 'directorist'), discovered);
			break;
		case 'page':
			progress = sprintf(_n('%1$d of %2$d page finished', '%1$d of %2$d pages finished', queued, 'directorist'), finished, queued);
			found = sprintf(_n('%d page found', '%d pages found', discovered, 'directorist'), discovered);
			break;
		case 'search':
			progress = sprintf(_n('%1$d of %2$d search page finished', '%1$d of %2$d search pages finished', queued, 'directorist'), finished, queued);
			found = sprintf(_n('%d search page found', '%d search pages found', discovered, 'directorist'), discovered);
			break;
		default:
			progress = sprintf(_n('%1$d of %2$d public page finished', '%1$d of %2$d public pages finished', queued, 'directorist'), finished, queued);
			found = sprintf(_n('%d content item found', '%d content items found', discovered, 'directorist'), discovered);
	}

	const outcome = sprintf(__('%1$d cached, %2$d failed', 'directorist'), Math.max(0, job.warmed || 0), Math.max(0, job.failed || 0));

	return sprintf(__('%1$s; %2$s; %3$s', 'directorist'), progress, outcome, found);
}

export function performancePollTargets(summary: PerformanceSummary) {
	return {
		pageCache: isInteractivePerformanceJob(summary.job),
		listingIndex: summary.listing_index.running || summary.listing_index.queued,
	};
}

export function performancePollDelay(summary: PerformanceSummary, now = Math.floor(Date.now() / 1000)) {
	const targets = performancePollTargets(summary);

	if (!targets.pageCache && !targets.listingIndex) return 0;
	if (targets.pageCache) return warmQueuePollDelay(summary.page_cache.queue, now);

	return 2000;
}

export function completedPerformancePollTargets(previous: ReturnType<typeof performancePollTargets>, current: ReturnType<typeof performancePollTargets>) {
	return {
		pageCache: previous.pageCache && !current.pageCache,
		listingIndex: previous.listingIndex && !current.listingIndex,
	};
}

export function timestampDate(timestamp: number | string): Date | null {
	if (!timestamp) return null;
	const date = typeof timestamp === 'number' ? new Date(timestamp * 1000) : new Date(timestamp);
	return Number.isNaN(date.getTime()) ? null : date;
}
