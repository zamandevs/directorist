import {
	cacheActionMessage,
	cacheActionRefreshMode,
	clearPendingResourceActions,
	completedPerformancePollTargets,
	isInteractivePerformanceJob,
	performanceJobOutcome,
	performanceJobDescription,
	performanceFailureLabel,
	performanceJobFailureMessage,
	performancePollDelay,
	markPendingResourceActions,
	markPurgedResourceRows,
	pageCacheMode,
	nextModifiedSort,
	nextCacheStateSort,
	paginationMode,
	performancePollTargets,
	resourceActionIsPending,
	resourceActionUrlChunks,
	resourceFilterCount,
	resourceScope,
	settingsAreEqual,
	timestampDate,
	variantOutcome,
	warmQueueIsActive,
	warmQueuePollDelay,
	selectedWarmValidationMessage,
	selectedWarmFailureMessage,
	selectedWarmSuccessMessage,
} from './model';
import { CacheResource } from './types';

describe('Performance dashboard view model', () => {
	it('describes warm progress using the requested resource type and URL variant count', () => {
		expect(performanceJobDescription({
			action: 'warm',
			scope: { type: 'listing' },
			state: 'running',
			warmed: 40,
			failed: 2,
			queued: 1494,
			discovered: 510,
			total: 510,
			progress: 3,
		})).toBe('42 of 1494 listing pages finished; 40 cached, 2 failed; 510 listings found');
	});

	it('uses logical resource wording for purge and contextual discovery', () => {
		expect(performanceJobDescription({ action: 'purge', scope: { type: 'archive' }, state: 'running', processed: 3, total: 8, progress: 38 }))
			.toBe('3 of 8 archives purged');
		expect(performanceJobDescription({ action: 'warm', scope: { type: 'search' }, state: 'running', total: 0, progress: 0 }))
			.toBe('Finding matching search pages');
	});

	it('does not report queued success for an empty operation', () => {
		expect(cacheActionMessage('warm-filtered', { success: false, code: 'no-resources' })).toEqual({
			status: 'info',
			message: 'No matching Directorist pages were found.',
		});
	});

	it('uses preload terminology for a successfully queued operation', () => {
		expect(cacheActionMessage('warm-selected', { success: true, code: 'queued' })).toEqual({
			status: 'success',
			message: 'Preloading has been queued.',
		});
	});

	it('distinguishes partial cache completion from a total operation failure', () => {
		expect(performanceJobOutcome({ state: 'failed', code: 'completed-with-failures', warmed: 23, failed: 34 } as any)).toBe('partial');
		expect(performanceJobOutcome({ state: 'failed', code: 'dispatch-failed', warmed: 0, failed: 0 } as any)).toBe('failed');
		expect(performanceJobOutcome({ state: 'completed', code: 'completed', warmed: 57, failed: 0 } as any)).toBe('completed');
	});

	it('turns terminal warm failure codes into actionable messages', () => {
		expect(performanceJobFailureMessage({
			action: 'warm',
			scope: { type: 'listing' },
			state: 'failed',
			failure_code: 'http-status-404',
			failure_count: 2,
			progress: 99,
		})).toBe('2 listing pages returned 404. Check their permalinks and published status, then retry.');
		expect(performanceJobFailureMessage({
			action: 'warm',
			scope: { type: 'page' },
			state: 'failed',
			failure_code: 'uncached',
			failure_count: 1,
			progress: 99,
		})).toBe('The page loaded but did not produce a cache entry. Check page-cache eligibility, then retry.');
	});

	it('turns per-page preload failure codes into concise result labels', () => {
		expect(performanceFailureLabel('http-status-404')).toBe('Not found (404)');
		expect(performanceFailureLabel('http-status-403')).toBe('Access restricted');
		expect(performanceFailureLabel('uncached')).toBe('No cache entry');
		expect(performanceFailureLabel('http-status-503')).toBe('HTTP 503');
		expect(performanceFailureLabel('request-failed')).toBe('Preload failed');
	});

	it('rejects a listing whose selected preload URL resolves to the site homepage', () => {
		const listing = {
			type: 'listing',
			title: 'Broken listing',
			url: 'https://example.test/',
			variant_urls: ['https://example.test/'],
		} as CacheResource;

		expect(selectedWarmValidationMessage([listing], 'https://example.test/')).toBe(
			'Broken listing does not have a valid public listing permalink. Update the listing permalink and published status before preloading it.'
		);
		expect(selectedWarmValidationMessage([{ ...listing, url: 'https://example.test/directory/broken/', variant_urls: [] }], 'https://example.test/')).toBe('');
	});

	it('reports a selected row that remains failed after its warm worker finishes', () => {
		const listing = { id: 'listing-91', title: 'Broken listing', cache: { state: 'uncached' } } as CacheResource;
		const refreshed = { ...listing, cache: { ...listing.cache, state: 'failed' } } as CacheResource;

		expect(selectedWarmFailureMessage([listing], [refreshed])).toBe(
			'Broken listing could not be cached. Open its public URL to verify the permalink and page response, then retry.'
		);
		expect(selectedWarmFailureMessage([listing], [{ ...refreshed, cache: { ...refreshed.cache, state: 'current' } }])).toBe('');
	});

	it('reports selected preload completion after the worker finishes', () => {
		expect(selectedWarmSuccessMessage([{} as CacheResource])).toBe('1 selected page was preloaded.');
		expect(selectedWarmSuccessMessage([{} as CacheResource, {} as CacheResource])).toBe('2 selected pages were preloaded.');
	});

	it('refreshes selected cache actions without reloading the whole table', () => {
		expect(cacheActionRefreshMode('warm-selected')).toBe('silent');
		expect(cacheActionRefreshMode('purge-selected')).toBe('silent');
		expect(cacheActionRefreshMode('warm-filtered')).toBe('visible');
	});

	it('keeps external-provider variant state distinct from uncached state', () => {
		expect(variantOutcome({ exact: false, code: 'provider-managed', provider: 'wp-super-cache', items: [] })).toBe('provider-managed');
		expect(variantOutcome({ exact: true, code: 'uncached', provider: 'directorist-cache', items: [] })).toBe('uncached');
	});

	it('does not describe an unavailable provider as ready', () => {
		expect(pageCacheMode({
			enabled: true,
			state: 'needs-attention',
			provider: { available: false, managed: false },
		} as any)).toBe('attention');
		expect(pageCacheMode({
			enabled: true,
			state: 'optimized',
			provider: { available: true, managed: false },
		} as any)).toBe('ready');
		expect(pageCacheMode({
			enabled: true,
			state: 'optimized',
			provider: { available: true, managed: true },
		} as any)).toBe('managed');
	});

	it('compares only editable settings values', () => {
		const saved = { enabled: true, cache_duration: 'automatic', cache_filtered_results: true, duration_choices: ['automatic'] };

		expect(settingsAreEqual(saved, { ...saved, duration_choices: ['automatic', '3600'] })).toBe(true);
		expect(settingsAreEqual(saved, { ...saved, enabled: false })).toBe(false);
	});

	it('keeps listing filters in the resource and background-operation scope', () => {
		const filters = { directory_id: 11, category_id: 22, location_id: 0 };

		expect(resourceFilterCount(filters)).toBe(2);
		expect(resourceScope('all', 'hotel', filters)).toEqual({
			type: 'listing',
			search: 'hotel',
			directory_id: 11,
			category_id: 22,
			location_id: 0,
		});
	});

	it('deduplicates and chunks every selected language URL', () => {
		const resources = [
			{ url: 'https://example.test/en/one/', variant_urls: ['https://example.test/en/one/', 'https://example.test/sv/one/'] },
			{ url: 'https://example.test/sv/one/', variant_urls: ['https://example.test/sv/one/', 'https://example.test/no/one/'] },
		] as CacheResource[];

		expect(resourceActionUrlChunks(resources, 2)).toEqual([
			['https://example.test/en/one/', 'https://example.test/sv/one/'],
			['https://example.test/no/one/'],
		]);
	});

	it('keeps selected resource actions scoped to their own rows', () => {
		const resources = [
			{ id: 'listing:11' },
			{ id: 'listing:12' },
		] as CacheResource[];
		const pending = markPendingResourceActions({}, [resources[0]], 'warm');

		expect(resourceActionIsPending(pending, resources[0].id)).toBe(true);
		expect(resourceActionIsPending(pending, resources[1].id)).toBe(false);
	});

	it('updates only fully purged built-in resource rows', () => {
		const first = {
			id: 'listing:11',
			url: 'https://example.test/en/one/',
			variant_urls: ['https://example.test/en/one/', 'https://example.test/sv/one/'],
			cache: { state: 'current', exact: true, created_at: 100, expires_at: 200, body_size: 300, failure_code: '' },
		} as CacheResource;
		const second = {
			id: 'listing:12',
			url: 'https://example.test/two/',
			variant_urls: ['https://example.test/two/'],
			cache: { state: 'managed', exact: false, created_at: 0, expires_at: 0, body_size: 0, failure_code: '' },
		} as CacheResource;

		const partial = markPurgedResourceRows([first, second], [first, second], ['https://example.test/en/one/']);
		const complete = markPurgedResourceRows(partial, [first, second], ['https://example.test/en/one/', 'https://example.test/sv/one/', 'https://example.test/two/']);

		expect(partial[0]).toBe(first);
		expect(complete[0].cache).toMatchObject({ state: 'uncached', exact: true, created_at: 0, expires_at: 0, body_size: 0, failure_code: '' });
		expect(complete[1]).toBe(second);
	});

	it('clears completed rows without disturbing another pending action', () => {
		const resources = [
			{ id: 'listing:11' },
			{ id: 'listing:12' },
		] as CacheResource[];
		const pending = markPendingResourceActions(
			markPendingResourceActions({}, [resources[0]], 'warm'),
			[resources[1]],
			'purge'
		);

		expect(clearPendingResourceActions(pending, [resources[0]])).toEqual({ 'listing:12': 'purge' });
	});

	it('waits for queued, running, and deferred cache warming to finish', () => {
		expect(warmQueueIsActive({ queued: 1, running: false, deferred: 0 })).toBe(true);
		expect(warmQueueIsActive({ queued: 0, running: true, deferred: 0 })).toBe(true);
		expect(warmQueueIsActive({ queued: 0, running: false, deferred: 1 })).toBe(true);
		expect(warmQueueIsActive({ queued: 0, running: false, deferred: 0 })).toBe(false);
	});

	it('backs off polling while a deferred warm is waiting for recovery', () => {
		expect(warmQueuePollDelay({ deferred: 1, recovery_at: 110 }, 100)).toBe(10000);
		expect(warmQueuePollDelay({ deferred: 1, recovery_at: 102 }, 100)).toBe(2000);
		expect(warmQueuePollDelay({ queued: 1 }, 100)).toBe(1500);
	});

	it('toggles last-modified sorting from newest to oldest', () => {
		expect(nextModifiedSort({ orderby: 'id', order: 'ASC' })).toEqual({ orderby: 'modified', order: 'DESC' });
		expect(nextModifiedSort({ orderby: 'modified', order: 'DESC' })).toEqual({ orderby: 'modified', order: 'ASC' });
		expect(nextModifiedSort({ orderby: 'modified', order: 'ASC' })).toEqual({ orderby: 'modified', order: 'DESC' });
	});

	it('toggles cache status sorting without forcing listing scope', () => {
		expect(nextCacheStateSort({ orderby: 'id', order: 'ASC' })).toEqual({ orderby: 'cache_state', order: 'ASC' });
		expect(nextCacheStateSort({ orderby: 'cache_state', order: 'ASC' })).toEqual({ orderby: 'cache_state', order: 'DESC' });
	});

	it('shows pagination controls only when navigation is possible', () => {
		expect(paginationMode(0, 1)).toBe('hidden');
		expect(paginationMode(3, 1)).toBe('summary');
		expect(paginationMode(21, 2)).toBe('navigation');
	});

	it('normalizes Unix timestamps and ISO strings for date rendering', () => {
		expect(timestampDate(1724490000)?.toISOString()).toBe('2024-08-24T09:00:00.000Z');
		expect(timestampDate('2024-08-24T09:00:00.000Z')?.toISOString()).toBe('2024-08-24T09:00:00.000Z');
		expect(timestampDate(0)).toBeNull();
		expect(timestampDate('not-a-date')).toBeNull();
	});

	it('does not treat automatic cache maintenance as an interactive dashboard job', () => {
		const summary = {
			page_cache: { queue: { queued: 4, running: true } },
			listing_index: { queued: false, running: false },
			job: { state: 'idle', progress: 0 },
		} as any;

		expect(isInteractivePerformanceJob(summary.job)).toBe(false);
		expect(performancePollTargets(summary)).toEqual({ pageCache: false, listingIndex: false });
	});

	it('polls only explicit cache jobs and active listing-index work', () => {
		expect(performancePollTargets({
			page_cache: { queue: { queued: 0, running: false } },
			listing_index: { queued: false, running: false },
			job: { state: 'running', progress: 25 },
		} as any)).toEqual({ pageCache: true, listingIndex: false });

		expect(isInteractivePerformanceJob({ state: 'verifying', progress: 70 } as any)).toBe(true);

		expect(performancePollTargets({
			page_cache: { queue: { queued: 0, running: false } },
			listing_index: { queued: true, running: false },
			job: { state: 'completed', progress: 100 },
		} as any)).toEqual({ pageCache: false, listingIndex: true });
	});

	it('backs off bulk-job polling while server recovery is scheduled and stops for terminal jobs', () => {
		expect(performancePollDelay({
			page_cache: { queue: { deferred: 4, recovery_at: 110 } },
			listing_index: { queued: false, running: false },
			job: { state: 'verifying', progress: 80 },
		} as any, 100)).toBe(10000);

		expect(performancePollDelay({
			page_cache: { queue: { queued: 0, running: false } },
			listing_index: { queued: false, running: false },
			job: { state: 'failed', progress: 98 },
		} as any, 100)).toBe(0);
	});

	it('refreshes completed job data once per busy-to-idle transition', () => {
		const active = { pageCache: true, listingIndex: false };
		const idle = { pageCache: false, listingIndex: false };

		expect(completedPerformancePollTargets(active, idle)).toEqual({ pageCache: true, listingIndex: false });
		expect(completedPerformancePollTargets(idle, idle)).toEqual({ pageCache: false, listingIndex: false });
	});
});
