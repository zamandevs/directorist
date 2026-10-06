export type PerformanceTab = 'page-cache' | 'listing-index';
export type CacheResourceType = 'all' | 'listing' | 'archive' | 'page' | 'search';
export type ResourceOrderBy = 'id' | 'modified' | 'cache_state';
export type ResourceOrder = 'ASC' | 'DESC';
export type CacheStateFilter = '' | 'needs-refresh' | 'uncached' | 'current' | 'stale' | 'expired' | 'invalidated' | 'failed';
export type PendingResourceAction = 'warm' | 'purge';
export type PendingResourceActions = Record<string, PendingResourceAction>;

export interface WarmQueueStatus {
	queued?: number;
	deferred?: number;
	running?: boolean;
	circuit_open?: boolean;
	open_until?: number;
	recovery_at?: number;
}

export interface ResourceSort {
	orderby: ResourceOrderBy;
	order: ResourceOrder;
}

export interface ResourceFilters {
	directory_id: number;
	category_id: number;
	location_id: number;
}

export interface ResourceFilterOption {
	value: string;
	label: string;
	count: number;
}

export interface ResourceFilterOptionsResponse {
	items: ResourceFilterOption[];
	total: number;
	kind: 'directory' | 'category' | 'location';
	search: string;
	has_more: boolean;
}

export interface CacheResource {
	id: string;
	title: string;
	url: string;
	type: string;
	route_type: string;
	object_id: number;
	modified_at: number;
	variants: number;
	variant_urls: string[];
	languages: string[];
	cache: {
		state: string;
		exact: boolean;
		provider: string;
		created_at: number;
		expires_at: number;
		body_size: number;
		variants: number;
		failure_code: string;
	};
	failures: Array<{ url: string; language: string; code: string; checked_at: number }>;
	actions: { warm: boolean; purge: boolean };
}

export interface ResourceResponse {
	items: CacheResource[];
	total: number;
	page: number;
	per_page: number;
	pages: number;
	type: string;
	search: string;
	orderby: ResourceOrderBy;
	order: ResourceOrder;
	cache_state: CacheStateFilter;
}

export interface DirectoryItem {
	id: number;
	name: string;
	listings: number;
	filter_fields: number;
	state: string;
	phase: string;
	progress: number;
	updated_at: string;
	actions: { regenerate: boolean };
}

export interface DirectoryResponse {
	items: DirectoryItem[];
	total: number;
	page: number;
	per_page: number;
	pages: number;
	search: string;
}

export interface PerformanceSummary {
	page_cache: {
		state: string;
		enabled: boolean;
		delivery: string;
		provider: { id: string; label: string; available: boolean; managed: boolean };
		automation: { invalidation: boolean; warming: boolean; cleanup: boolean };
		queue: WarmQueueStatus;
		coverage: CacheCoverage;
	};
	listing_index: {
		state: string;
		schema_healthy: boolean;
		data_current: boolean;
		reads_active: boolean;
		canonical_listings: number;
		indexed_listings: number;
		queued: boolean;
		running: boolean;
		phase: string;
		repair_available: boolean;
	};
	job: PerformanceJob;
}

export interface CacheCoverageItem {
	total: number;
	cached: number;
	needs_refresh: number;
}

export interface CacheCoverage extends CacheCoverageItem {
	exact: boolean;
	ready: boolean;
	types: Record<'listing' | 'archive' | 'page' | 'search', CacheCoverageItem>;
}

export interface PerformanceJob {
	id?: string;
	action?: string;
	scope?: { type?: string; search?: string; directory_id?: number; category_id?: number; location_id?: number; cache_state?: CacheStateFilter };
	state: string;
	code?: string;
	processed?: number;
	discovered?: number;
	queued?: number;
	warmed?: number;
	failed?: number;
	failure_code?: string;
	failure_count?: number;
	failure_items?: PerformanceFailureItem[];
	failure_items_omitted?: number;
	total?: number;
	progress: number;
}

export interface PerformanceFailureItem {
	url: string;
	title: string;
	type: string;
	code: string;
}

export interface PerformanceSettings {
	enabled: boolean;
	cache_duration: string;
	cache_filtered_results: boolean;
	duration_choices: string[];
}

export interface CacheVariant {
	url: string;
	state: string;
	created_at: number;
	expires_at: number;
	body_size: number;
	language: string;
	filtered: boolean;
}

export interface CacheVariantResponse {
	exact: boolean;
	provider: string;
	items: CacheVariant[];
	code: string;
}

export interface CacheActionResult {
	success: boolean;
	code: string;
	job?: PerformanceJob;
}
