import apiFetch from '@wordpress/api-fetch';

declare global {
	interface Window {
		directoristPerformance?: {
			rootId: string;
			restPath: string;
			nonce: string;
			homeUrl: string;
		};
	}
}

const config = window.directoristPerformance || {
	rootId: 'directorist-performance-app',
	restPath: '/directorist/v1/admin/performance',
	nonce: '',
	homeUrl: window.location.origin,
};

export const homeUrl = config.homeUrl;

if (config.nonce) {
	apiFetch.use(apiFetch.createNonceMiddleware(config.nonce));
}

export const rootId = config.rootId;

export function read<T>(path: string, params: Record<string, unknown> = {}): Promise<T> {
	const query = new URLSearchParams();

	Object.keys(params).forEach((key) => {
		const value = params[key];

		if (value !== undefined && value !== null && value !== '') {
			query.set(key, String(value));
		}
	});

	return apiFetch({
		path: `${config.restPath}${path}${query.toString() ? `?${query.toString()}` : ''}`,
	}) as Promise<T>;
}

export function mutate<T>(path: string, data: object, method = 'POST'): Promise<T> {
	return apiFetch({ path: `${config.restPath}${path}`, method, data }) as Promise<T>;
}
