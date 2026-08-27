import {
	Button,
	ComboboxControl,
	Dropdown,
	Modal,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { chevronDown, chevronLeft, chevronRight, chevronUp, closeSmall, cog, external, info, trash, update } from '@wordpress/icons';
import { homeUrl, mutate, read } from './api';
import { cacheActionMessage, cacheActionRefreshMode, clearPendingResourceActions, completedPerformancePollTargets, markPendingResourceActions, nextCacheStateSort, nextModifiedSort, pageCacheMode, paginationMode, performanceFailureLabel, performanceJobDescription, performanceJobFailureMessage, performanceJobOutcome, performancePollDelay, performancePollTargets, resourceActionIsPending, resourceActionUrlChunks, resourceFilterCount, resourceScope, selectedWarmFailureMessage, selectedWarmSuccessMessage, selectedWarmValidationMessage, settingsAreEqual, timestampDate, variantOutcome, warmQueueIsActive, warmQueuePollDelay } from './model';
import {
	CacheActionResult,
	CacheCoverage as CacheCoverageData,
	CacheResource,
	CacheResourceType,
	CacheStateFilter,
	CacheVariantResponse,
	DirectoryItem,
	DirectoryResponse,
	PerformanceSettings,
	PerformanceSummary,
	PerformanceTab,
	PendingResourceActions,
	ResourceFilterOptionsResponse,
	ResourceFilters,
	ResourceResponse,
	ResourceSort,
} from './types';

const EMPTY_RESOURCES: ResourceResponse = {
	items: [],
	total: 0,
	page: 1,
	per_page: 20,
	pages: 1,
	type: 'all',
	search: '',
	orderby: 'id',
	order: 'ASC',
	cache_state: '',
};

const EMPTY_DIRECTORIES: DirectoryResponse = {
	items: [],
	total: 0,
	page: 1,
	per_page: 20,
	pages: 1,
	search: '',
};

const EMPTY_RESOURCE_FILTERS: ResourceFilters = {
	directory_id: 0,
	category_id: 0,
	location_id: 0,
};

const SELECTED_WARM_TIMEOUT = 6 * 60 * 1000;

function wait(milliseconds: number) {
	return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
}

const STATE_LABELS: Record<string, string> = {
	optimized: __('Ready', 'directorist'),
	disabled: __('Disabled', 'directorist'),
	'needs-attention': __('Needs attention', 'directorist'),
	current: __('Cached', 'directorist'),
	stale: __('Refreshing', 'directorist'),
	expired: __('Expired', 'directorist'),
	invalidated: __('Outdated', 'directorist'),
	invalid: __('Unavailable', 'directorist'),
	uncached: __('Not cached', 'directorist'),
	managed: __('Provider managed', 'directorist'),
	unavailable: __('Unavailable', 'directorist'),
	failed: __('Failed', 'directorist'),
	active: __('Active', 'directorist'),
	inactive: __('Inactive', 'directorist'),
	updating: __('Updating', 'directorist'),
	attention: __('Needs attention', 'directorist'),
	'needs-update': __('Needs update', 'directorist'),
};

const TYPE_LABELS: Record<string, string> = {
	listing: __('Listing', 'directorist'),
	archive: __('Archive', 'directorist'),
	page: __('Page', 'directorist'),
	search: __('Search', 'directorist'),
};

const COVERAGE_TYPE_LABELS: Record<string, string> = {
	listing: __('Listings', 'directorist'),
	archive: __('Archives', 'directorist'),
	page: __('Pages', 'directorist'),
	search: __('Search', 'directorist'),
};

type NoticeState = { status: 'success' | 'error' | 'warning' | 'info'; message: string };
type Confirmation = { title: string; text: string; actionLabel?: string; destructive?: boolean; run: () => void };
type ResourceDetail = { resource: CacheResource; response: CacheVariantResponse | null; loading: boolean };

function stateLabel(state: string): string {
	return STATE_LABELS[state] || state.replace(/-/g, ' ');
}

function stateTone(state: string): string {
	if (['optimized', 'current', 'active'].includes(state)) return 'healthy';
	if (['disabled', 'inactive', 'uncached', 'managed'].includes(state)) return 'neutral';
	if (['stale', 'updating'].includes(state)) return 'progress';
	return 'warning';
}

function DateValue({ timestamp, fallback = __('Never', 'directorist') }: { timestamp: number | string; fallback?: string }) {
	const date = timestampDate(timestamp);
	if (!date) return <span className="directorist-performance-date is-unavailable">{fallback}</span>;

	const compact = new Intl.DateTimeFormat(undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	}).format(date);

	return <time className="directorist-performance-date" dateTime={date.toISOString()} title={date.toLocaleString()}>{compact}</time>;
}

function readableError(error: any): string {
	return error && error.message ? error.message : __('The operation could not be completed.', 'directorist');
}

function StatusBadge({ state, label }: { state: string; label?: string }) {
	return <span className={`directorist-performance-status is-${stateTone(state)}`}><span aria-hidden="true" />{label || stateLabel(state)}</span>;
}

function CacheStatus({ resource }: { resource: CacheResource }) {
	const code = resource.cache.failure_code || resource.failures?.[0]?.code || '';

	return <div className="directorist-performance-cache-status"><StatusBadge state={resource.cache.state} />{resource.cache.state === 'failed' && code && <small>{performanceFailureLabel(code)}</small>}</div>;
}

function Pagination({ page, pages, total, onChange }: { page: number; pages: number; total: number; onChange: (page: number) => void }) {
	const mode = paginationMode(total, pages);
	if (mode === 'hidden') return null;

	return (
		<div className={`directorist-performance-pagination is-${mode}`}>
			<span>{sprintf(_n('%d item', '%d items', total, 'directorist'), total)}</span>
			{mode === 'navigation' && <div>
				<Button icon={chevronLeft} label={__('Previous page', 'directorist')} showTooltip disabled={page <= 1} onClick={() => onChange(page - 1)} />
				<span>{sprintf(__('Page %1$d of %2$d', 'directorist'), page, pages)}</span>
				<Button icon={chevronRight} label={__('Next page', 'directorist')} showTooltip disabled={page >= pages} onClick={() => onChange(page + 1)} />
			</div>}
		</div>
	);
}

function FailedPagesModal({ job, onClose }: { job: PerformanceSummary['job']; onClose: () => void }) {
	const items = job.failure_items || [];
	const omitted = job.failure_items_omitted || 0;

	return (
		<Modal title={__('Pages that could not be cached', 'directorist')} onRequestClose={onClose} className="directorist-performance-details directorist-performance-failures">
			<p className="directorist-performance-failures__intro">{__('Open each public page to check its permalink and published status.', 'directorist')}</p>
			<table>
				<thead><tr><th scope="col">{__('Page', 'directorist')}</th><th scope="col">{__('Result', 'directorist')}</th></tr></thead>
				<tbody>{items.map((item) => (
					<tr key={`${item.url}-${item.code}`}>
						<td>
							<a href={item.url} target="_blank" rel="noreferrer">{item.title || __('Public page', 'directorist')}</a>
							<span>{TYPE_LABELS[item.type] || __('Page', 'directorist')}</span>
							<code>{item.url}</code>
						</td>
						<td><strong>{performanceFailureLabel(item.code)}</strong></td>
					</tr>
				))}</tbody>
			</table>
			{omitted > 0 && <p className="directorist-performance-failures__omitted">{sprintf(_n('%d additional failed page is not shown.', '%d additional failed pages are not shown.', omitted, 'directorist'), omitted)}</p>}
		</Modal>
	);
}

function JobProgress({ summary, onCancel, onRetry }: { summary: PerformanceSummary; onCancel: () => void; onRetry: () => void }) {
	const { job } = summary;
	const outcome = performanceJobOutcome(job);
	const [showFailures, setShowFailures] = useState(false);
	const canViewFailures = job.action === 'warm' && (job.failure_items || []).length > 0;
	const retry = () => {
		setShowFailures(false);
		onRetry();
	};

	if (!['active', 'partial', 'failed'].includes(outcome)) return null;

	if (outcome === 'partial') {
		const failureMessage = performanceJobFailureMessage(job);

		return <>
			<div className="directorist-performance-job is-failed" role="alert">
				<div>
					<strong>{__('Cache preload partially completed', 'directorist')}</strong>
					<span>{sprintf(_n('%1$d page cached; %2$d could not be cached.', '%1$d pages cached; %2$d could not be cached.', job.warmed || 0, 'directorist'), job.warmed || 0, job.failed || 0)} {failureMessage}</span>
				</div>
				<div className="directorist-performance-job__actions">
					{canViewFailures && <Button variant="tertiary" icon={info} onClick={() => setShowFailures(true)}>{__('View failed pages', 'directorist')}</Button>}
					<Button variant="secondary" icon={update} onClick={retry}>{__('Retry', 'directorist')}</Button>
				</div>
			</div>
			{showFailures && <FailedPagesModal job={job} onClose={() => setShowFailures(false)} />}
		</>;
	}

	if (outcome === 'failed') {
		const purging = job.action === 'purge';

		return <>
			<div className="directorist-performance-job is-failed" role="alert">
				<div>
					<strong>{purging ? __('Cache purge stopped', 'directorist') : __('Cache preload stopped', 'directorist')}</strong>
					<span>{purging ? __('Directorist could not purge the matching pages. Retry when ready.', 'directorist') : performanceJobFailureMessage(job)}</span>
				</div>
				<div className="directorist-performance-job__actions">
					{canViewFailures && <Button variant="tertiary" icon={info} onClick={() => setShowFailures(true)}>{__('View failed pages', 'directorist')}</Button>}
					<Button variant="secondary" icon={update} onClick={retry}>{__('Retry', 'directorist')}</Button>
				</div>
			</div>
			{showFailures && <FailedPagesModal job={job} onClose={() => setShowFailures(false)} />}
		</>;
	}

	return (
		<div className="directorist-performance-job" role="status" aria-live="polite">
			<div>
				<strong>{job.action === 'purge' ? __('Purging content cache', 'directorist') : job.code === 'recovering' ? __('Resuming content preload', 'directorist') : __('Preloading content', 'directorist')}</strong>
				<span>{performanceJobDescription(job)}</span>
			</div>
			<div className="directorist-performance-job__track" aria-hidden="true"><span style={{ width: `${job.progress || 2}%` }} /></div>
			<Button variant="tertiary" onClick={onCancel}>{__('Cancel', 'directorist')}</Button>
		</div>
	);
}

function CacheCoverage({ coverage }: { coverage: CacheCoverageData }) {
	if (!coverage.exact || !coverage.ready) return null;

	return (
		<section className="directorist-performance-coverage" aria-labelledby="directorist-performance-coverage-title">
			<h3 id="directorist-performance-coverage-title">{__('Cache coverage', 'directorist')}</h3>
			<div>
				{(['listing', 'archive', 'page', 'search'] as const).map((type) => {
					const item = coverage.types[type];

					return (
						<div className="directorist-performance-coverage__item" key={type}>
							<span>{COVERAGE_TYPE_LABELS[type]}</span>
							<strong>{sprintf(__('%1$s of %2$s cached', 'directorist'), item.cached.toLocaleString(), item.total.toLocaleString())}</strong>
							<small>{sprintf(_n('%s needs refresh', '%s need refresh', item.needs_refresh, 'directorist'), item.needs_refresh.toLocaleString())}</small>
						</div>
					);
				})}
			</div>
		</section>
	);
}

function ConfirmAction({ confirmation, onClose }: { confirmation: Confirmation | null; onClose: () => void }) {
	if (!confirmation) return null;

	return (
		<Modal title={confirmation.title} onRequestClose={onClose} className="directorist-performance-confirm">
			<p>{confirmation.text}</p>
			<div className="directorist-performance-confirm__actions">
				<Button variant="tertiary" onClick={onClose}>{__('Cancel', 'directorist')}</Button>
				<Button variant="primary" isDestructive={confirmation.destructive} onClick={() => { confirmation.run(); onClose(); }}>
					{confirmation.actionLabel || __('Continue', 'directorist')}
				</Button>
			</div>
		</Modal>
	);
}

function CacheSettings({ summary, value, saved, saving, onChange, onReset, onSave }: { summary: PerformanceSummary; value: PerformanceSettings; saved: PerformanceSettings; saving: boolean; onChange: (value: PerformanceSettings) => void; onReset: () => void; onSave: () => void }) {
	const builtIn = summary.page_cache.provider.id === 'directorist-cache';
	const dirty = !settingsAreEqual(value, saved);
	const durations = [
		{ label: __('Automatic', 'directorist'), value: 'automatic' },
		{ label: __('1 hour', 'directorist'), value: '3600' },
		{ label: __('6 hours', 'directorist'), value: '21600' },
		{ label: __('12 hours', 'directorist'), value: '43200' },
		{ label: __('24 hours', 'directorist'), value: '86400' },
	];

	return (
		<div className="directorist-performance-settings-panel">
			<div><h2>{__('Page Cache settings', 'directorist')}</h2><p>{__('Directorist handles invalidation and background refresh automatically.', 'directorist')}</p></div>
			<ToggleControl label={__('Page Cache', 'directorist')} help={value.enabled ? __('Public directory pages can be served from cache.', 'directorist') : __('Pages will render normally without Directorist page caching.', 'directorist')} checked={value.enabled} onChange={(enabled) => onChange({ ...value, enabled })} />
			{builtIn && <SelectControl __next40pxDefaultSize label={__('Refresh interval', 'directorist')} help={__('Automatic adjusts refresh timing for listings, archives, and searches.', 'directorist')} value={value.cache_duration} options={durations} onChange={(cache_duration) => onChange({ ...value, cache_duration })} />}
			<ToggleControl label={__('Cache filtered results', 'directorist')} help={__('Cache valid directory searches after their first request.', 'directorist')} checked={value.cache_filtered_results} onChange={(cache_filtered_results) => onChange({ ...value, cache_filtered_results })} />
			<div className="directorist-performance-settings-panel__actions">
				<Button variant="tertiary" disabled={!dirty || saving} onClick={onReset}>{__('Reset', 'directorist')}</Button>
				<Button variant="primary" isBusy={saving} disabled={!dirty || saving} onClick={onSave}>{__('Save changes', 'directorist')}</Button>
			</div>
		</div>
	);
}

function IndexSettings({ summary, busy, onAction }: { summary: PerformanceSummary; busy: boolean; onAction: (action: string) => void }) {
	return (
		<div className="directorist-performance-settings-panel">
			<div><h2>{__('Listing Index settings', 'directorist')}</h2><p>{__('Directorist keeps the index synchronized as listings and directory fields change.', 'directorist')}</p></div>
			<div className="directorist-performance-settings-panel__status"><span>{__('Accelerated queries', 'directorist')}</span><StatusBadge state={summary.listing_index.reads_active ? 'active' : 'inactive'} /></div>
			<Button variant="secondary" disabled={busy || (!summary.listing_index.reads_active && !summary.listing_index.data_current)} onClick={() => onAction(summary.listing_index.reads_active ? 'disable-reads' : 'enable-reads')}>
				{summary.listing_index.reads_active ? __('Pause accelerated queries', 'directorist') : __('Resume accelerated queries', 'directorist')}
			</Button>
			{summary.listing_index.repair_available && <Button variant="secondary" icon={update} disabled={busy} onClick={() => onAction('repair')}>{__('Repair index', 'directorist')}</Button>}
		</div>
	);
}

function EmptyState({ filtered, kind, onClear }: { filtered: boolean; kind: 'resources' | 'directories'; onClear: () => void }) {
	const title = filtered
		? __('No matching results', 'directorist')
		: (kind === 'resources' ? __('No public directory content yet', 'directorist') : __('No directory types yet', 'directorist'));
	const description = filtered
		? __('Try a different search or clear the current filters.', 'directorist')
		: (kind === 'resources' ? __('Published listings, archives, and configured directory pages will appear here.', 'directorist') : __('Directory types will appear after they are created.', 'directorist'));

	return (
		<div className="directorist-performance-empty" role="status">
			<strong>{title}</strong>
			<p>{description}</p>
			{filtered && <Button variant="secondary" onClick={onClear}>{__('Clear filters', 'directorist')}</Button>}
		</div>
	);
}

type ResourceFilterKind = 'directory' | 'category' | 'location';

function ResourceFilterCombobox({ kind, label, placeholder, value, onChange, onFailureChange }: { kind: ResourceFilterKind; label: string; placeholder: string; value: number; onChange: (value: number) => void; onFailureChange: (kind: ResourceFilterKind, failed: boolean) => void }) {
	const [options, setOptions] = useState<ResourceFilterOptionsResponse['items']>([]);
	const [search, setSearch] = useState('');
	const [loading, setLoading] = useState(false);
	const [failed, setFailed] = useState(false);
	const request = useRef(0);

	useEffect(() => {
		const requestId = ++request.current;
		const timer = window.setTimeout(async () => {
			setLoading(true);
			setFailed(false);
			try {
				const response = await read<ResourceFilterOptionsResponse>('/resources/filter-options', { kind, search, selected: value });
				if (requestId === request.current) {
					setOptions(response.items);
					onFailureChange(kind, false);
				}
			} catch (error) {
				if (requestId === request.current) {
					setOptions([]);
					setFailed(true);
					onFailureChange(kind, true);
				}
			} finally {
				if (requestId === request.current) setLoading(false);
			}
		}, 250);

		return () => window.clearTimeout(timer);
	}, [kind, onFailureChange, search, value]);

	return (
		<div className="directorist-performance-resource-filter">
			<span className="directorist-performance-filter-label">{label}</span>
			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				hideLabelFromVision
				label={label}
				className={failed ? 'has-error' : undefined}
				value={value > 0 ? String(value) : null}
				options={options}
				isLoading={loading}
				placeholder={placeholder}
				onFilterValueChange={setSearch}
				onChange={(selected) => onChange(selected ? Number(selected) || 0 : 0)}
				__experimentalRenderItem={({ item }) => <span className="directorist-performance-filter-option"><span title={item.label}>{item.label}</span><small>{item.count.toLocaleString()}</small></span>}
			/>
		</div>
	);
}

export default function App() {
	const initialUrl = new URL(window.location.href);
	const initialTab = initialUrl.searchParams.get('performance_tab') === 'listing-index' ? 'listing-index' : 'page-cache';
	const initialResourceFilters: ResourceFilters = {
		directory_id: Math.max(0, Number(initialUrl.searchParams.get('resource_directory')) || 0),
		category_id: Math.max(0, Number(initialUrl.searchParams.get('resource_category')) || 0),
		location_id: Math.max(0, Number(initialUrl.searchParams.get('resource_location')) || 0),
	};
	const requestedResourceType = initialUrl.searchParams.get('resource_type');
	const requestedOrderBy = initialUrl.searchParams.get('resource_orderby');
	const initialResourceOrderBy = ['modified', 'cache_state'].includes(requestedOrderBy || '') ? requestedOrderBy as ResourceSort['orderby'] : 'id';
	const initialResourceSort: ResourceSort = {
		orderby: initialResourceOrderBy,
		order: initialUrl.searchParams.get('resource_order') === 'DESC' ? 'DESC' : 'ASC',
	};
	const requestedCacheState = initialUrl.searchParams.get('resource_cache_state');
	const initialCacheState: CacheStateFilter = ['needs-refresh', 'uncached', 'current', 'stale', 'expired', 'invalidated', 'failed'].includes(requestedCacheState || '') ? requestedCacheState as CacheStateFilter : '';
	const initialResourceType: CacheResourceType = resourceFilterCount(initialResourceFilters) > 0 || initialResourceSort.orderby === 'modified' ? 'listing' : (['listing', 'archive', 'page', 'search'].includes(requestedResourceType || '') ? requestedResourceType as CacheResourceType : 'all');
	const [tab, setTab] = useState<PerformanceTab>(initialTab);
	const [summary, setSummary] = useState<PerformanceSummary | null>(null);
	const [savedSettings, setSavedSettings] = useState<PerformanceSettings | null>(null);
	const [settingsValue, setSettingsValue] = useState<PerformanceSettings | null>(null);
	const [resourceData, setResources] = useState<ResourceResponse>(EMPTY_RESOURCES);
	const [directories, setDirectories] = useState<DirectoryResponse>(EMPTY_DIRECTORIES);
	const [resourceType, setResourceType] = useState<CacheResourceType>(initialResourceType);
	const [resourcePage, setResourcePage] = useState(Math.max(1, Number(initialUrl.searchParams.get('resource_page')) || 1));
	const [resourcePerPage] = useState(Math.min(50, Math.max(1, Number(initialUrl.searchParams.get('resource_per_page')) || 20)));
	const [resourceSearch, setResourceSearch] = useState(initialUrl.searchParams.get('resource_search') || '');
	const [resourceFilters, setResourceFilters] = useState<ResourceFilters>(initialResourceFilters);
	const [resourceSort, setResourceSort] = useState<ResourceSort>(initialResourceSort);
	const [resourceCacheState, setResourceCacheState] = useState<CacheStateFilter>(initialCacheState);
	const [directoryPage, setDirectoryPage] = useState(Math.max(1, Number(initialUrl.searchParams.get('directory_page')) || 1));
	const [directoryPerPage] = useState(Math.min(50, Math.max(1, Number(initialUrl.searchParams.get('directory_per_page')) || 20)));
	const [directorySearch, setDirectorySearch] = useState(initialUrl.searchParams.get('directory_search') || '');
	const [selection, setSelection] = useState<Set<string>>(new Set());
	const [loading, setLoading] = useState(true);
	const [initialError, setInitialError] = useState('');
	const [resourceLoading, setResourceLoading] = useState(false);
	const [directoryLoading, setDirectoryLoading] = useState(false);
	const [busy, setBusy] = useState(false);
	const [pendingResourceActions, setPendingResourceActions] = useState<PendingResourceActions>({});
	const [notice, setNotice] = useState<NoticeState | null>(null);
	const [confirmation, setConfirmation] = useState<Confirmation | null>(null);
	const [detail, setDetail] = useState<ResourceDetail | null>(null);
	const [failedResourceFilters, setFailedResourceFilters] = useState<Set<ResourceFilterKind>>(new Set());
	const resourceRequest = useRef(0);
	const directoryRequest = useRef(0);
	const previousPollTargets = useRef({ pageCache: false, listingIndex: false });
	const pendingResourceActionsRef = useRef<PendingResourceActions>({});

	const loadSummary = useCallback(async () => {
		const result = await read<PerformanceSummary>('/summary');
		setSummary(result);
		return result;
	}, []);

	const loadSettings = useCallback(async () => {
		const result = await read<PerformanceSettings>('/settings');
		setSavedSettings(result);
		setSettingsValue(result);
	}, []);

	const loadResources = useCallback(async (options: { silent?: boolean } = {}) => {
		const requestId = ++resourceRequest.current;
		if (!options.silent) setResourceLoading(true);
		try {
			const result = await read<ResourceResponse>('/resources', { page: resourcePage, per_page: resourcePerPage, ...resourceScope(resourceType, resourceSearch, resourceFilters), ...resourceSort, cache_state: resourceCacheState });
			if (requestId === resourceRequest.current) {
				setResources(result);
				if (result.page !== resourcePage) setResourcePage(result.page);
			}

			return result;
		} catch (error) {
			if (requestId === resourceRequest.current) setNotice({ status: 'error', message: readableError(error) });
		} finally {
			if (requestId === resourceRequest.current) setResourceLoading(false);
		}
	}, [resourceCacheState, resourceFilters, resourcePage, resourcePerPage, resourceSearch, resourceSort, resourceType]);

	const loadDirectories = useCallback(async () => {
		const requestId = ++directoryRequest.current;
		setDirectoryLoading(true);
		try {
			const result = await read<DirectoryResponse>('/listing-index/directories', { page: directoryPage, per_page: directoryPerPage, search: directorySearch });
			if (requestId === directoryRequest.current) {
				setDirectories(result);
				if (result.page !== directoryPage) setDirectoryPage(result.page);
			}
		} catch (error) {
			if (requestId === directoryRequest.current) setNotice({ status: 'error', message: readableError(error) });
		} finally {
			if (requestId === directoryRequest.current) setDirectoryLoading(false);
		}
	}, [directoryPage, directoryPerPage, directorySearch]);

	const loadInitial = useCallback(async () => {
		setLoading(true);
		setInitialError('');
		try {
			await Promise.all([loadSummary(), loadSettings()]);
		} catch (error) {
			setInitialError(readableError(error));
		} finally {
			setLoading(false);
		}
	}, [loadSettings, loadSummary]);

	useEffect(() => { loadInitial(); }, [loadInitial]);

	useEffect(() => {
		const timer = window.setTimeout(() => tab === 'page-cache' ? loadResources() : loadDirectories(), 250);
		return () => window.clearTimeout(timer);
	}, [loadDirectories, loadResources, tab]);

	useEffect(() => { setSelection(new Set()); }, [resourceCacheState, resourceFilters, resourcePage, resourceSearch, resourceSort, resourceType]);

	useEffect(() => {
		if (!summary) return;
		const targets = performancePollTargets(summary);
		const previous = previousPollTargets.current;
		const completed = completedPerformancePollTargets(previous, targets);
		previousPollTargets.current = targets;

		if (completed.pageCache) loadResources();
		if (completed.listingIndex) loadDirectories();
		if (!targets.pageCache && !targets.listingIndex) return;

		let timer = 0;
		const poll = () => {
			if (document.hidden) return;
			timer = window.setTimeout(() => {
				loadSummary().catch(() => undefined);
			}, performancePollDelay(summary));
		};
		const onVisibilityChange = () => {
			window.clearTimeout(timer);

			if (!document.hidden) loadSummary().catch(() => undefined);
		};

		poll();
		document.addEventListener('visibilitychange', onVisibilityChange);

		return () => {
			window.clearTimeout(timer);
			document.removeEventListener('visibilitychange', onVisibilityChange);
		};
	}, [loadDirectories, loadResources, loadSummary, summary]);

	useEffect(() => {
		const next = new URL(window.location.href);
		next.searchParams.set('performance_tab', tab);
		if (resourceType === 'all') next.searchParams.delete('resource_type');
		else next.searchParams.set('resource_type', resourceType);
		const state: Record<string, string | number> = {
			resource_page: resourcePage,
			resource_per_page: resourcePerPage,
			resource_search: resourceSearch,
			resource_directory: resourceFilters.directory_id,
			resource_category: resourceFilters.category_id,
			resource_location: resourceFilters.location_id,
			resource_orderby: resourceSort.orderby === 'id' ? '' : resourceSort.orderby,
			resource_order: resourceSort.orderby === 'id' || resourceSort.order === 'ASC' ? '' : resourceSort.order,
			resource_cache_state: resourceCacheState,
			directory_page: directoryPage,
			directory_per_page: directoryPerPage,
			directory_search: directorySearch,
		};
		Object.keys(state).forEach((key) => {
			const value = state[key];
			if (value === '' || value === 0 || value === 1 || value === 20) next.searchParams.delete(key);
			else next.searchParams.set(key, String(value));
		});
		window.history.replaceState({}, '', next.toString());
	}, [directoryPage, directoryPerPage, directorySearch, resourceCacheState, resourceFilters, resourcePage, resourcePerPage, resourceSearch, resourceSort, resourceType, tab]);

	useEffect(() => {
		const restoreUrlState = () => {
			const current = new URL(window.location.href);
			const type = current.searchParams.get('resource_type');
			const restoredFilters = {
				directory_id: Math.max(0, Number(current.searchParams.get('resource_directory')) || 0),
				category_id: Math.max(0, Number(current.searchParams.get('resource_category')) || 0),
				location_id: Math.max(0, Number(current.searchParams.get('resource_location')) || 0),
			};
			const orderBy = current.searchParams.get('resource_orderby');
			const restoredOrderBy = ['modified', 'cache_state'].includes(orderBy || '') ? orderBy as ResourceSort['orderby'] : 'id';
			const restoredSort: ResourceSort = {
				orderby: restoredOrderBy,
				order: current.searchParams.get('resource_order') === 'DESC' ? 'DESC' : 'ASC',
			};
			setTab(current.searchParams.get('performance_tab') === 'listing-index' ? 'listing-index' : 'page-cache');
			setResourceType(resourceFilterCount(restoredFilters) > 0 || restoredSort.orderby === 'modified' ? 'listing' : (['listing', 'archive', 'page', 'search'].includes(type || '') ? type as CacheResourceType : 'all'));
			setResourcePage(Math.max(1, Number(current.searchParams.get('resource_page')) || 1));
			setResourceSearch(current.searchParams.get('resource_search') || '');
			setResourceFilters(restoredFilters);
			setResourceSort(restoredSort);
			const cacheState = current.searchParams.get('resource_cache_state');
			setResourceCacheState(['needs-refresh', 'uncached', 'current', 'stale', 'expired', 'invalidated', 'failed'].includes(cacheState || '') ? cacheState as CacheStateFilter : '');
			setDirectoryPage(Math.max(1, Number(current.searchParams.get('directory_page')) || 1));
			setDirectorySearch(current.searchParams.get('directory_search') || '');
		};
		window.addEventListener('popstate', restoreUrlState);
		return () => window.removeEventListener('popstate', restoreUrlState);
	}, []);

	const runCacheAction = useCallback(async (action: string, data: Record<string, unknown> = {}) => {
		setBusy(true);
		try {
			const result = await mutate<CacheActionResult>('/cache/actions', { action, ...data });
			setNotice(cacheActionMessage(action, result));
			await Promise.all([loadSummary(), loadResources()]);
		} catch (error) {
			setNotice({ status: 'error', message: readableError(error) });
			// A rejected retry can still clear an obsolete terminal job on the server.
			// Refresh summary state so an old failure banner is not left behind.
			await loadSummary().catch(() => undefined);
		} finally {
			setBusy(false);
		}
	}, [loadResources, loadSummary]);

	const waitForSelectedWarm = useCallback(async () => {
		const deadline = Date.now() + SELECTED_WARM_TIMEOUT;
		let latest = await loadSummary();

		while (warmQueueIsActive(latest.page_cache.queue) && Date.now() < deadline) {
			await wait(warmQueuePollDelay(latest.page_cache.queue));
			latest = await loadSummary();
		}

		return !warmQueueIsActive(latest.page_cache.queue);
	}, [loadSummary]);

	const runSelectedCacheAction = useCallback(async (action: 'warm-selected' | 'purge-selected', items: CacheResource[]) => {
		const actionName = action === 'warm-selected' ? 'warm' : 'purge';
		const actionableItems = items.filter((item) => !resourceActionIsPending(pendingResourceActionsRef.current, item.id) && item.actions[actionName]);
		const validationMessage = action === 'warm-selected' ? selectedWarmValidationMessage(actionableItems, homeUrl) : '';

		if (validationMessage) {
			setNotice({ status: 'error', message: validationMessage });
			return;
		}

		const chunks = resourceActionUrlChunks(actionableItems);

		if (!chunks.length) return;
		pendingResourceActionsRef.current = markPendingResourceActions(pendingResourceActionsRef.current, actionableItems, actionName);
		setPendingResourceActions(pendingResourceActionsRef.current);
		try {
			let result: CacheActionResult = { success: true, code: 'processed' };

			for (const urls of chunks) {
				result = await mutate<CacheActionResult>('/cache/actions', { action, urls });
			}

			setNotice(cacheActionMessage(action, result));
			if (action === 'warm-selected') {
				const completed = await waitForSelectedWarm();
				const refreshed = await loadResources({ silent: cacheActionRefreshMode(action) === 'silent' });
				const failureMessage = completed && refreshed ? selectedWarmFailureMessage(actionableItems, refreshed.items) : '';

				if (failureMessage) setNotice({ status: 'error', message: failureMessage });
				else if (completed && refreshed) setNotice({ status: 'success', message: selectedWarmSuccessMessage(actionableItems) });
				else if (!completed) setNotice({ status: 'info', message: __('Preloading continues in the background.', 'directorist') });
			} else {
				await Promise.all([loadSummary(), loadResources({ silent: cacheActionRefreshMode(action) === 'silent' })]);
			}
		} catch (error) {
			setNotice({ status: 'error', message: readableError(error) });
		} finally {
			pendingResourceActionsRef.current = clearPendingResourceActions(pendingResourceActionsRef.current, actionableItems);
			setPendingResourceActions(pendingResourceActionsRef.current);
		}
	}, [loadResources, loadSummary, waitForSelectedWarm]);

	const runIndexAction = useCallback(async (action: string, directoryId?: number) => {
		setBusy(true);
		try {
			await mutate('/listing-index/actions', { action, directory_id: directoryId || 0 });
			const message = action === 'disable-reads'
				? __('Accelerated queries are paused.', 'directorist')
				: (action === 'enable-reads' ? __('Accelerated queries are active.', 'directorist') : __('Listing Index update queued.', 'directorist'));
			setNotice({ status: 'success', message });
			await Promise.all([loadSummary(), loadDirectories()]);
		} catch (error) {
			setNotice({ status: 'error', message: readableError(error) });
		} finally {
			setBusy(false);
		}
	}, [loadDirectories, loadSummary]);

	const persistSettings = useCallback(async () => {
		if (!settingsValue) return;
		setBusy(true);
		try {
			const saved = await mutate<PerformanceSettings>('/settings', settingsValue);
			setSavedSettings(saved);
			setSettingsValue(saved);
			setNotice({ status: 'success', message: __('Performance settings saved.', 'directorist') });
			await loadSummary();
		} catch (error) {
			setNotice({ status: 'error', message: readableError(error) });
		} finally {
			setBusy(false);
		}
	}, [loadSummary, settingsValue]);

	const requestSettingsSave = useCallback(() => {
		if (!settingsValue || !savedSettings) return;
		if (savedSettings.enabled && !settingsValue.enabled) {
			setConfirmation({
				title: __('Disable Directorist Page Cache?', 'directorist'),
				text: __('Public directory pages will continue to work, but they will be generated on every request.', 'directorist'),
				actionLabel: __('Disable cache', 'directorist'),
				destructive: true,
				run: persistSettings,
			});
			return;
		}
		persistSettings();
	}, [persistSettings, savedSettings, settingsValue]);

	const showDetails = useCallback(async (resource: CacheResource) => {
		setDetail({ resource, response: null, loading: true });
		try {
			const response = await read<CacheVariantResponse>('/resources/variants', { url: resource.url, route_type: resource.route_type });
			setDetail({ resource, response, loading: false });
		} catch (error) {
			setDetail(null);
			setNotice({ status: 'error', message: readableError(error) });
		}
	}, []);

	const toggleSelection = useCallback((id: string) => {
		setSelection((current) => {
			const next = new Set(current);
			if (next.has(id)) next.delete(id);
			else next.add(id);
			return next;
		});
	}, []);

	const setResourceFilterFailure = useCallback((kind: ResourceFilterKind, failed: boolean) => {
		setFailedResourceFilters((current) => {
			if (current.has(kind) === failed) return current;
			const next = new Set(current);
			if (failed) next.add(kind);
			else next.delete(kind);
			return next;
		});
	}, []);

	const resources = useMemo<ResourceResponse>(() => ({ ...resourceData, items: resourceData.items.map((item) => {
		const pendingAction = pendingResourceActions[item.id];

		if (!pendingAction) return item;

		return {
			...item,
			cache: { ...item.cache, state: pendingAction === 'warm' ? 'stale' : item.cache.state },
			actions: { warm: false, purge: false },
		};
	}) }), [pendingResourceActions, resourceData]);
	const selectedResources = useMemo(() => resources.items.filter((item) => selection.has(item.id)), [resources.items, selection]);
	const allSelected = resources.items.length > 0 && selectedResources.length === resources.items.length;
	const canWarmSelected = selectedResources.some((item) => item.actions.warm && !resourceActionIsPending(pendingResourceActions, item.id));
	const canPurgeSelected = selectedResources.some((item) => item.actions.purge && !resourceActionIsPending(pendingResourceActions, item.id));
	const activeResourceFilterCount = resourceFilterCount(resourceFilters);
	const currentResourceScope = { ...resourceScope(resourceType, resourceSearch, resourceFilters), cache_state: resourceCacheState };
	const resourceFiltered = resourceType !== 'all' || Boolean(resourceSearch) || activeResourceFilterCount > 0 || Boolean(resourceCacheState);
	const directoryFiltered = Boolean(directorySearch);

	if (loading) return <div className="directorist-performance-loading"><Spinner /><span>{__('Loading Directorist Performance...', 'directorist')}</span></div>;
	if (!summary || !settingsValue || !savedSettings) return <div className="directorist-performance-load-error" role="alert"><h1>{__('Performance', 'directorist')}</h1><p>{initialError || __('Performance data is temporarily unavailable.', 'directorist')}</p><Button variant="primary" icon={update} onClick={loadInitial}>{__('Try again', 'directorist')}</Button></div>;
	const cacheMode = pageCacheMode(summary.page_cache);

	const retryJob = () => runCacheAction(summary.job.action === 'purge' ? 'purge-filtered' : 'warm-filtered', { ...(summary.job.scope || {}) });
	const switchTab = (nextTab: PerformanceTab) => {
		if (nextTab === tab) return;
		const next = new URL(window.location.href);
		next.searchParams.set('performance_tab', nextTab);
		window.history.pushState({}, '', next.toString());
		setSettingsValue(savedSettings);
		setTab(nextTab);
	};
	const onTabKeyDown = (event: React.KeyboardEvent<HTMLButtonElement>, currentTab: PerformanceTab) => {
		if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
		event.preventDefault();
		const nextTab = currentTab === 'page-cache' ? 'listing-index' : 'page-cache';
		switchTab(nextTab);
		window.requestAnimationFrame(() => document.getElementById(`directorist-performance-tab-${nextTab}`)?.focus());
	};

	const confirmPurge = (items: CacheResource[]) => setConfirmation({
		title: __('Purge selected cache?', 'directorist'),
		text: sprintf(_n('%d selected page will be purged.', '%d selected pages will be purged.', items.length, 'directorist'), items.length),
		actionLabel: __('Purge cache', 'directorist'),
		destructive: true,
		run: () => runSelectedCacheAction('purge-selected', items),
	});

	return (
		<div className="directorist-performance-shell">
			<div className="directorist-performance-breadcrumb"><span>{__('Directorist', 'directorist')}</span><span aria-current="page">{__('Performance', 'directorist')}</span></div>
			<div className="directorist-performance-workspace">
				<header className="directorist-performance-header">
					<div><h1>{__('Performance', 'directorist')}</h1><p>{__('Automatic delivery and query optimization for your directory.', 'directorist')}</p></div>
					<Dropdown popoverProps={{ placement: 'bottom-end' }} renderToggle={({ onToggle, isOpen }) => <Button className="directorist-performance-settings-toggle" icon={cog} label={tab === 'page-cache' ? __('Page Cache settings', 'directorist') : __('Listing Index settings', 'directorist')} showTooltip aria-expanded={isOpen} onClick={() => { if (isOpen) setSettingsValue(savedSettings); onToggle(); }} />} renderContent={() => tab === 'page-cache' ? <CacheSettings summary={summary} value={settingsValue} saved={savedSettings} saving={busy} onChange={setSettingsValue} onReset={() => setSettingsValue(savedSettings)} onSave={requestSettingsSave} /> : <IndexSettings summary={summary} busy={busy} onAction={(action) => {
						if (action === 'disable-reads') setConfirmation({ title: __('Pause accelerated queries?', 'directorist'), text: __('Directory queries will use the standard WordPress path until acceleration is resumed.', 'directorist'), actionLabel: __('Pause acceleration', 'directorist'), run: () => runIndexAction(action) });
						else runIndexAction(action);
					}} />} />
				</header>

				<div className="directorist-performance-tabs" role="tablist" aria-label={__('Performance sections', 'directorist')}>
					<button id="directorist-performance-tab-page-cache" role="tab" aria-selected={tab === 'page-cache'} aria-controls="directorist-performance-panel-page-cache" tabIndex={tab === 'page-cache' ? 0 : -1} className={tab === 'page-cache' ? 'is-active' : ''} onClick={() => switchTab('page-cache')} onKeyDown={(event) => onTabKeyDown(event, 'page-cache')}>{__('Page Cache', 'directorist')}<StatusBadge state={summary.page_cache.state} /></button>
					<button id="directorist-performance-tab-listing-index" role="tab" aria-selected={tab === 'listing-index'} aria-controls="directorist-performance-panel-listing-index" tabIndex={tab === 'listing-index' ? 0 : -1} className={tab === 'listing-index' ? 'is-active' : ''} onClick={() => switchTab('listing-index')} onKeyDown={(event) => onTabKeyDown(event, 'listing-index')}>{__('Listing Index', 'directorist')}<StatusBadge state={summary.listing_index.state} /></button>
				</div>

				<JobProgress summary={summary} onRetry={retryJob} onCancel={async () => {
					if (!summary.job.id) return;
					try {
						await mutate('/jobs', { id: summary.job.id }, 'DELETE');
						await loadSummary();
						setNotice({ status: 'success', message: __('Cache operation cancelled.', 'directorist') });
					} catch (error) {
						setNotice({ status: 'error', message: readableError(error) });
					}
				}} />
				{notice && <Notice status={notice.status} onRemove={() => setNotice(null)}>{notice.message}</Notice>}

				<main className="directorist-performance-main">
					{tab === 'page-cache' ? (
						<section id="directorist-performance-panel-page-cache" role="tabpanel" aria-labelledby="directorist-performance-tab-page-cache">
							<div className={`directorist-performance-outcome is-${stateTone(summary.page_cache.state)}${summary.page_cache.coverage.exact && summary.page_cache.coverage.ready ? ' has-coverage' : ''}`}>
								<div className="directorist-performance-outcome__message">
									<StatusBadge state={summary.page_cache.state} />
									<h2>{cacheMode === 'managed' ? sprintf(__('Caching is managed by %s', 'directorist'), summary.page_cache.provider.label) : cacheMode === 'ready' ? __('Automatic page caching is ready', 'directorist') : cacheMode === 'attention' ? __('Page caching needs attention', 'directorist') : __('Page caching is turned off', 'directorist')}</h2>
									<p>{cacheMode === 'managed' ? sprintf(__('%s delivers cached pages while Directorist keeps directory content synchronized.', 'directorist'), summary.page_cache.provider.label) : cacheMode === 'ready' ? __('Published directory content is invalidated when it changes and refreshed in the background.', 'directorist') : cacheMode === 'attention' ? __('Directorist could not connect to a supported page-cache provider. Directory pages continue through normal WordPress rendering.', 'directorist') : __('Directory pages continue to work through normal WordPress rendering.', 'directorist')}</p>
								</div>
								<div className="directorist-performance-outcome__facts">
									<div><span>{__('Tracked content', 'directorist')}</span><strong>{(summary.page_cache.coverage.ready ? summary.page_cache.coverage.total : resources.total).toLocaleString()}</strong></div>
									{summary.page_cache.coverage.exact && summary.page_cache.coverage.ready && <div><span>{__('Cached', 'directorist')}</span><strong>{summary.page_cache.coverage.cached.toLocaleString()}</strong></div>}
									{summary.page_cache.coverage.exact && summary.page_cache.coverage.ready && <div><span>{__('Needs refresh', 'directorist')}</span><strong>{summary.page_cache.coverage.needs_refresh.toLocaleString()}</strong></div>}
									<div><span>{__('Automatic preload', 'directorist')}</span><strong>{summary.page_cache.automation.warming ? __('On', 'directorist') : __('Unavailable', 'directorist')}</strong></div>
								</div>
							</div>
							<CacheCoverage coverage={summary.page_cache.coverage} />

							<div className="directorist-performance-section-heading">
								<div><h2>{__('Directory content', 'directorist')}</h2><p>{__('Review and refresh cacheable Directorist pages.', 'directorist')}</p></div>
								<div>
									<Button variant="primary" icon={update} disabled={busy || resourceLoading || resources.total < 1 || !summary.page_cache.automation.warming} onClick={() => runCacheAction('warm-filtered', { ...currentResourceScope, cache_state: resourceCacheState || 'needs-refresh' })}>{resourceType === 'listing' ? __('Preload listings', 'directorist') : resourceFiltered ? __('Preload results', 'directorist') : __('Preload uncached content', 'directorist')}</Button>
									<Button variant="secondary" isDestructive disabled={busy || !summary.page_cache.automation.invalidation || (resourceFiltered && resources.total < 1)} onClick={() => setConfirmation({ title: resourceFiltered ? __('Purge matching cache?','directorist') : __('Purge all Directorist cache?', 'directorist'), text: resourceFiltered ? __('Cached responses matching the current content filters will be removed.', 'directorist') : __('Cached Directorist pages will be regenerated automatically as they are requested.', 'directorist'), actionLabel: __('Purge cache', 'directorist'), destructive: true, run: () => runCacheAction(resourceFiltered ? 'purge-filtered' : 'purge-all', currentResourceScope) })}>{resourceFiltered ? __('Purge results', 'directorist') : __('Purge all', 'directorist')}</Button>
								</div>
							</div>

								<div className="directorist-performance-list">
									<div className="directorist-performance-toolbar">
										<div className="directorist-performance-toolbar__filters">
											<div className="directorist-performance-filter-field is-content-type"><span className="directorist-performance-filter-label">{__('Content', 'directorist')}</span><SelectControl __next40pxDefaultSize hideLabelFromVision label={__('Content type', 'directorist')} value={resourceType} options={[{ label: __('All content', 'directorist'), value: 'all' }, { label: __('Listings', 'directorist'), value: 'listing' }, { label: __('Archives', 'directorist'), value: 'archive' }, { label: __('Pages', 'directorist'), value: 'page' }, { label: __('Search', 'directorist'), value: 'search' }]} onChange={(type) => { setResourceType(type as CacheResourceType); if (type !== 'listing') { setResourceFilters(EMPTY_RESOURCE_FILTERS); if (resourceSort.orderby === 'modified') setResourceSort({ orderby: 'id', order: 'ASC' }); } setResourcePage(1); }} /></div>
											<div className="directorist-performance-filter-field is-search"><span className="directorist-performance-filter-label">{__('Search', 'directorist')}</span><SearchControl value={resourceSearch} onChange={(search) => { setResourceSearch(search); setResourcePage(1); }} label={__('Search cached content', 'directorist')} placeholder={__('Search content', 'directorist')} /></div>
											{cacheMode !== 'managed' && <div className="directorist-performance-filter-field is-cache-state"><span className="directorist-performance-filter-label">{__('Cache status', 'directorist')}</span><SelectControl __next40pxDefaultSize hideLabelFromVision label={__('Cache status', 'directorist')} value={resourceCacheState} options={[{ label: __('All statuses', 'directorist'), value: '' }, { label: __('Needs refresh', 'directorist'), value: 'needs-refresh' }, { label: __('Not cached', 'directorist'), value: 'uncached' }, { label: __('Cached', 'directorist'), value: 'current' }, { label: __('Refreshing', 'directorist'), value: 'stale' }, { label: __('Expired', 'directorist'), value: 'expired' }, { label: __('Outdated', 'directorist'), value: 'invalidated' }, { label: __('Failed', 'directorist'), value: 'failed' }]} onChange={(cacheState) => { setResourceCacheState(cacheState as CacheStateFilter); setResourcePage(1); }} /></div>}
											<ResourceFilterCombobox kind="directory" label={__('Directory type', 'directorist')} placeholder={__('All directory types', 'directorist')} value={resourceFilters.directory_id} onChange={(directory_id) => { setResourceFilters({ ...resourceFilters, directory_id }); setResourceType('listing'); setResourcePage(1); }} onFailureChange={setResourceFilterFailure} />
											<ResourceFilterCombobox kind="category" label={__('Category', 'directorist')} placeholder={__('All categories', 'directorist')} value={resourceFilters.category_id} onChange={(category_id) => { setResourceFilters({ ...resourceFilters, category_id }); setResourceType('listing'); setResourcePage(1); }} onFailureChange={setResourceFilterFailure} />
											<ResourceFilterCombobox kind="location" label={__('Location', 'directorist')} placeholder={__('All locations', 'directorist')} value={resourceFilters.location_id} onChange={(location_id) => { setResourceFilters({ ...resourceFilters, location_id }); setResourceType('listing'); setResourcePage(1); }} onFailureChange={setResourceFilterFailure} />
										</div>
									</div>
									{failedResourceFilters.size > 0 && <div className="directorist-performance-filter-error" role="status">{__('Some listing filter options are temporarily unavailable.', 'directorist')}</div>}
									<div className="directorist-performance-bulk-actions">
										<div><label className="directorist-performance-mobile-select-all"><input type="checkbox" disabled={resources.items.length < 1} checked={allSelected} onChange={() => setSelection(allSelected ? new Set() : new Set(resources.items.map((item) => item.id)))} />{allSelected ? __('Deselect visible', 'directorist') : __('Select visible', 'directorist')}</label><strong>{sprintf(_n('%d selected', '%d selected', selectedResources.length, 'directorist'), selectedResources.length)}</strong>{activeResourceFilterCount > 1 && <Button className="directorist-performance-clear-filters" variant="tertiary" icon={closeSmall} onClick={() => { setResourceFilters(EMPTY_RESOURCE_FILTERS); setResourcePage(1); }}>{__('Clear filters', 'directorist')}</Button>}</div>
										<div><Button variant="secondary" icon={update} disabled={busy || !canWarmSelected} onClick={() => runSelectedCacheAction('warm-selected', selectedResources)}>{__('Preload', 'directorist')}</Button><Button variant="tertiary" isDestructive icon={trash} disabled={busy || !canPurgeSelected} onClick={() => confirmPurge(selectedResources)}>{__('Purge', 'directorist')}</Button></div>
									</div>

								<div className={`directorist-performance-list__body${resourceLoading ? ' is-loading' : ''}`}>
									{resourceLoading && <div className="directorist-performance-list__loading"><Spinner /><span className="screen-reader-text">{__('Loading content', 'directorist')}</span></div>}
									{!resourceLoading && resources.items.length < 1 ? <EmptyState filtered={resourceFiltered} kind="resources" onClear={() => { setResourceType('all'); setResourceSearch(''); setResourceFilters(EMPTY_RESOURCE_FILTERS); setResourceCacheState(''); setResourceSort({ orderby: 'id', order: 'ASC' }); setResourcePage(1); }} /> : <>
										<div className="directorist-performance-table-wrap">
										<table className={`directorist-performance-table ${cacheMode === 'managed' ? 'is-provider-managed' : 'has-cache-timestamps'}`}><caption className="screen-reader-text">{__('Directorist cacheable content', 'directorist')}</caption><thead><tr><th className="is-selection"><input type="checkbox" checked={allSelected} onChange={() => setSelection(allSelected ? new Set() : new Set(resources.items.map((item) => item.id)))} aria-label={allSelected ? __('Deselect all visible content', 'directorist') : __('Select all visible content', 'directorist')} /></th><th>{__('Content', 'directorist')}</th><th>{__('Type', 'directorist')}</th><th className="is-sortable" aria-sort={resourceSort.orderby === 'modified' ? (resourceSort.order === 'DESC' ? 'descending' : 'ascending') : 'none'}><Button className={resourceSort.orderby === 'modified' ? 'is-active' : 'is-inactive'} variant="link" icon={resourceSort.orderby === 'modified' && resourceSort.order === 'ASC' ? chevronUp : chevronDown} iconPosition="right" aria-label={resourceSort.orderby === 'modified' && resourceSort.order === 'DESC' ? __('Sort by last modified, oldest first', 'directorist') : __('Sort by last modified, newest first', 'directorist')} onClick={() => { setResourceSort(nextModifiedSort(resourceSort)); setResourceType('listing'); setResourcePage(1); }}>{__('Last modified', 'directorist')}</Button></th><th className={cacheMode !== 'managed' ? 'is-sortable' : undefined} aria-sort={resourceSort.orderby === 'cache_state' ? (resourceSort.order === 'DESC' ? 'descending' : 'ascending') : 'none'}>{cacheMode === 'managed' ? __('Cache status', 'directorist') : <Button className={resourceSort.orderby === 'cache_state' ? 'is-active' : 'is-inactive'} variant="link" icon={resourceSort.orderby === 'cache_state' && resourceSort.order === 'DESC' ? chevronDown : chevronUp} iconPosition="right" onClick={() => { setResourceSort(nextCacheStateSort(resourceSort)); setResourcePage(1); }}>{__('Cache status', 'directorist')}</Button>}</th>{cacheMode !== 'managed' && <th>{__('Last cached', 'directorist')}</th>}<th><span className="screen-reader-text">{__('Actions', 'directorist')}</span></th></tr></thead><tbody>{resources.items.map((item) => <tr key={item.id}><td className="is-selection"><input type="checkbox" checked={selection.has(item.id)} onChange={() => toggleSelection(item.id)} aria-label={selection.has(item.id) ? sprintf(__('Deselect %s', 'directorist'), item.title) : sprintf(__('Select %s', 'directorist'), item.title)} /></td><td><div className="directorist-performance-content-cell"><a href={item.url} target="_blank" rel="noreferrer">{item.title}<span className="screen-reader-text"> {__('opens in a new tab', 'directorist')}</span></a>{item.variants > 1 && <span>{sprintf(_n('%d language version', '%d language versions', item.variants, 'directorist'), item.variants)}</span>}<span>{new URL(item.url).pathname}</span></div></td><td>{TYPE_LABELS[item.type] || item.type}</td><td><DateValue timestamp={item.modified_at} fallback={__('Not available', 'directorist')} /></td><td><CacheStatus resource={item} /></td>{cacheMode !== 'managed' && <td><DateValue timestamp={item.cache.exact ? item.cache.created_at : 0} fallback={__('Not available', 'directorist')} /></td>}<td><div className="directorist-performance-row-actions"><Button icon={info} label={sprintf(__('View cache details for %s', 'directorist'), item.title)} showTooltip onClick={() => showDetails(item)} /><Button icon={update} label={sprintf(__('Preload %s', 'directorist'), item.title)} showTooltip disabled={busy || !item.actions.warm} onClick={() => runSelectedCacheAction('warm-selected', [item])} /><Button icon={trash} label={sprintf(__('Purge cache for %s', 'directorist'), item.title)} showTooltip isDestructive disabled={busy || !item.actions.purge} onClick={() => confirmPurge([item])} /></div></td></tr>)}</tbody></table>
										</div>
									<div className="directorist-performance-mobile-list">{resources.items.map((item) => <article key={item.id}><div className="directorist-performance-mobile-list__heading"><input type="checkbox" checked={selection.has(item.id)} onChange={() => toggleSelection(item.id)} aria-label={selection.has(item.id) ? sprintf(__('Deselect %s', 'directorist'), item.title) : sprintf(__('Select %s', 'directorist'), item.title)} /><div><a href={item.url} target="_blank" rel="noreferrer">{item.title}</a><span>{TYPE_LABELS[item.type] || item.type}{item.variants > 1 ? `, ${sprintf(_n('%d language version', '%d language versions', item.variants, 'directorist'), item.variants)}` : ''}</span></div><CacheStatus resource={item} /></div><div className="directorist-performance-mobile-list__meta"><span>{__('Last modified', 'directorist')}</span><strong><DateValue timestamp={item.modified_at} fallback={__('Not available', 'directorist')} /></strong></div>{cacheMode !== 'managed' && <div className="directorist-performance-mobile-list__meta"><span>{__('Last cached', 'directorist')}</span><strong><DateValue timestamp={item.cache.exact ? item.cache.created_at : 0} fallback={__('Not available', 'directorist')} /></strong></div>}<div className="directorist-performance-row-actions"><Button icon={info} label={sprintf(__('View cache details for %s', 'directorist'), item.title)} showTooltip onClick={() => showDetails(item)} /><Button icon={update} label={sprintf(__('Preload %s', 'directorist'), item.title)} showTooltip disabled={busy || !item.actions.warm} onClick={() => runSelectedCacheAction('warm-selected', [item])} /><Button icon={trash} label={sprintf(__('Purge cache for %s', 'directorist'), item.title)} showTooltip isDestructive disabled={busy || !item.actions.purge} onClick={() => confirmPurge([item])} /></div></article>)}</div>
									</>}
								</div>
								<Pagination page={resources.page} pages={resources.pages} total={resources.total} onChange={setResourcePage} />
							</div>
						</section>
					) : (
						<section id="directorist-performance-panel-listing-index" role="tabpanel" aria-labelledby="directorist-performance-tab-listing-index">
							<div className={`directorist-performance-outcome is-${stateTone(summary.listing_index.state)}`}>
								<div className="directorist-performance-outcome__message"><StatusBadge state={summary.listing_index.state} /><h2>{summary.listing_index.reads_active ? __('Accelerated listing queries are active', 'directorist') : __('Listing queries are using the WordPress fallback', 'directorist')}</h2><p>{summary.listing_index.data_current ? __('The index is synchronized automatically as directory content changes.', 'directorist') : __('The index needs an update before accelerated queries can resume.', 'directorist')}</p></div>
							<div className="directorist-performance-outcome__facts"><div><span>{__('Listings indexed', 'directorist')}</span><strong>{sprintf(__('%1$d of %2$d', 'directorist'), summary.listing_index.indexed_listings, summary.listing_index.canonical_listings)}</strong></div><div><span>{directoryFiltered ? __('Matching types', 'directorist') : __('Directory types', 'directorist')}</span><strong>{directories.total.toLocaleString()}</strong></div><div><span>{__('Synchronization', 'directorist')}</span><strong>{summary.listing_index.running || summary.listing_index.queued ? __('In progress', 'directorist') : __('Automatic', 'directorist')}</strong></div></div>
							</div>

							<div className="directorist-performance-section-heading"><div><h2>{__('Directory indexes', 'directorist')}</h2><p>{__('Review readiness by directory type.', 'directorist')}</p></div><Button variant="primary" icon={update} disabled={busy || summary.listing_index.running || summary.listing_index.queued} onClick={() => setConfirmation({ title: __('Regenerate all directory indexes?', 'directorist'), text: __('All directory indexes will be verified and refreshed in the background.', 'directorist'), actionLabel: __('Regenerate all', 'directorist'), run: () => runIndexAction('regenerate-all') })}>{__('Regenerate all', 'directorist')}</Button></div>
								<div className="directorist-performance-list">
									<div className="directorist-performance-toolbar"><div className="directorist-performance-filter-field is-search"><span className="directorist-performance-filter-label">{__('Search', 'directorist')}</span><SearchControl value={directorySearch} onChange={(search) => { setDirectorySearch(search); setDirectoryPage(1); }} label={__('Search directory types', 'directorist')} placeholder={__('Search directory types', 'directorist')} /></div></div>
								<div className={`directorist-performance-list__body${directoryLoading ? ' is-loading' : ''}`}>
									{directoryLoading && <div className="directorist-performance-list__loading"><Spinner /><span className="screen-reader-text">{__('Loading directory indexes', 'directorist')}</span></div>}
									{!directoryLoading && directories.items.length < 1 ? <EmptyState filtered={directoryFiltered} kind="directories" onClear={() => { setDirectorySearch(''); setDirectoryPage(1); }} /> : <>
										<div className="directorist-performance-table-wrap"><table className="directorist-performance-table"><caption className="screen-reader-text">{__('Directorist directory indexes', 'directorist')}</caption><thead><tr><th>{__('Directory type', 'directorist')}</th><th>{__('Listings', 'directorist')}</th><th>{__('Filter fields', 'directorist')}</th><th>{__('Status', 'directorist')}</th><th>{__('Last updated', 'directorist')}</th><th><span className="screen-reader-text">{__('Actions', 'directorist')}</span></th></tr></thead><tbody>{directories.items.map((item: DirectoryItem) => <tr key={item.id}><td><strong>{item.name}</strong></td><td>{item.listings.toLocaleString()}</td><td>{item.filter_fields.toLocaleString()}</td><td><StatusBadge state={item.state} />{item.state === 'updating' && <span className="directorist-performance-row-progress">{item.progress}%</span>}</td><td><DateValue timestamp={item.updated_at} /></td><td><div className="directorist-performance-row-actions"><Button icon={update} label={sprintf(__('Regenerate index for %s', 'directorist'), item.name)} showTooltip disabled={busy || item.state === 'updating'} onClick={() => setConfirmation({ title: sprintf(__('Regenerate %s?', 'directorist'), item.name), text: __('This directory index will be verified and refreshed in the background.', 'directorist'), actionLabel: __('Regenerate', 'directorist'), run: () => runIndexAction('regenerate-directory', item.id) })} /></div></td></tr>)}</tbody></table></div>
										<div className="directorist-performance-mobile-list">{directories.items.map((item) => <article key={item.id}><div className="directorist-performance-mobile-list__heading"><div><strong>{item.name}</strong><span>{sprintf(_n('%d listing', '%d listings', item.listings, 'directorist'), item.listings)}</span></div><StatusBadge state={item.state} /></div><dl><div><dt>{__('Filter fields', 'directorist')}</dt><dd>{item.filter_fields.toLocaleString()}</dd></div><div><dt>{__('Last updated', 'directorist')}</dt><dd><DateValue timestamp={item.updated_at} /></dd></div></dl><div className="directorist-performance-row-actions"><Button icon={update} label={sprintf(__('Regenerate index for %s', 'directorist'), item.name)} showTooltip disabled={busy || item.state === 'updating'} onClick={() => setConfirmation({ title: sprintf(__('Regenerate %s?', 'directorist'), item.name), text: __('This directory index will be verified and refreshed in the background.', 'directorist'), actionLabel: __('Regenerate', 'directorist'), run: () => runIndexAction('regenerate-directory', item.id) })} /></div></article>)}</div>
									</>}
								</div>
								<Pagination page={directories.page} pages={directories.pages} total={directories.total} onChange={setDirectoryPage} />
							</div>
						</section>
					)}
				</main>
			</div>

			<ConfirmAction confirmation={confirmation} onClose={() => setConfirmation(null)} />
			{detail && <Modal title={detail.resource.title} onRequestClose={() => setDetail(null)} className={`directorist-performance-details${detail.response?.items.length ? ' has-variants' : ' is-compact'}`}><Button variant="link" icon={external} iconPosition="right" href={detail.resource.url} target="_blank" rel="noreferrer">{__('Open page', 'directorist')}</Button>{detail.loading ? <div className="directorist-performance-details__loading"><Spinner /></div> : detail.response && variantOutcome(detail.response) === 'provider-managed' ? <div className="directorist-performance-details__empty"><strong>{__('Cache details are provider managed', 'directorist')}</strong><p>{sprintf(__('%s manages the cached response and does not expose exact page variants.', 'directorist'), summary.page_cache.provider.label)}</p></div> : detail.response && detail.response.items.length ? <table><thead><tr><th>{__('Variant', 'directorist')}</th><th>{__('Status', 'directorist')}</th><th>{__('Last cached', 'directorist')}</th></tr></thead><tbody>{detail.response.items.map((variant, index) => <tr key={`${variant.url}-${index}`}><td>{variant.language ? variant.language.toUpperCase() : __('Default', 'directorist')}{variant.filtered ? ` / ${__('Filtered', 'directorist')}` : ''}</td><td><StatusBadge state={variant.state} /></td><td><DateValue timestamp={variant.created_at} /></td></tr>)}</tbody></table> : ['expired', 'invalidated', 'stale'].includes(detail.resource.cache.state) ? <div className="directorist-performance-details__empty"><strong>{__('This cached response needs a refresh', 'directorist')}</strong><p>{__('Preload it now or open the public page to replace the outdated response.', 'directorist')}</p></div> : <div className="directorist-performance-details__empty"><strong>{__('This page is not cached yet', 'directorist')}</strong><p>{__('Preload it now or open the public page to create its first cached response.', 'directorist')}</p></div>}</Modal>}
		</div>
	);
}
