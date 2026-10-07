/* Refresh action tokens without making public HTML session-specific. */
(function ($) {
	if (!$ || !$.ajaxTransport) return;
	const tokenKeys = ['nonce', 'directorist_nonce', 'ajax_nonce', 'ajaxnonce', 'rest_nonce', 'login_nonce', 'quick_login_nonce', 'author_sort_nonce', 'comment_edit_nonce', 'comment_nonce', 'email_nonce'];
	const knownTokens = new Map();
	const actionFields = {
		ajaxlogin: { security: 'login_nonce' },
		atbdp_ajax_quick_login: { token: 'quick_login_nonce', 'directorist-quick-login-security': 'quick_login_nonce' },
		directorist_ajax_quick_login: { 'directorist-quick-login-security': 'quick_login_nonce' },
		directorist_author_alpha_sorting: { _nonce: 'author_sort_nonce' },
		directorist_get_comment_edit_form: { nonce: 'comment_edit_nonce' },
		directorist_process_comment_form: { directorist_comment_nonce: 'comment_nonce' },
		directorist_send_confirmation_email: { directorist_nonce: 'email_nonce' },
	};
	let pending;
	function rememberTokens() {
		const data = window.directorist || {};
		for (const key of tokenKeys) {
			if (typeof data[key] === 'string' && data[key]) knownTokens.set(data[key], key);
		}
	}
	function requestParts(options) {
		const url = new URL(options.url, window.location.href);
		let data;
		if (options.data instanceof FormData) {
			data = new FormData();
			for (const [key, value] of options.data) data.append(key, value);
		} else if (typeof options.data === 'string' && !/^application\/json/i.test(options.contentType || '')) {
			data = new URLSearchParams(options.data);
		} else {
			data = new URLSearchParams();
		}
		const action = data.get('action') || url.searchParams.get('action');
		return { url, data, action };
	}
	function tokenKind(field, value, action) {
		const explicit = actionFields[action] && actionFields[action][field];
		if (explicit) return explicit;
		return /nonce|security|^token$/i.test(field) ? knownTokens.get(value) : undefined;
	}
	function restRequest(url) {
		const root = new URL((window.directorist || {}).rest_url || '/wp-json/', window.location.href);
		return url.origin === window.location.origin && (
			url.href.startsWith(`${root.href.replace(/\/$/, '')}/directorist/`) ||
			/^\/directorist\/v\d+\//.test(url.searchParams.get('rest_route') || '')
		);
	}
	function refreshTokens() {
		if (pending) return pending.promise();
		const config = (window.directorist || {}).cache_interaction_tokens;
		const endpoint = config && new URL(config.url, window.location.href);
		if (!config || !config.enabled || endpoint.origin !== window.location.origin) return $.Deferred().reject().promise();
		const deferred = $.Deferred();
		pending = deferred;
		$.ajax({
			url: endpoint.href, type: 'POST', dataType: 'json', cache: false,
			data: { action: config.action }, timeout: 15000, global: false,
			directoristFreshTokens: true,
		}).done((response) => {
			const tokens = response && response.success && response.data;
			if (!tokens || tokenKeys.some((key) => typeof tokens[key] !== 'string' || !tokens[key])) {
				pending = undefined;
				deferred.reject();
				return;
			}
			rememberTokens();
			for (const key of tokenKeys) window.directorist[key] = tokens[key];
			rememberTokens();
			pending = undefined;
			deferred.resolve(tokens);
		}).fail(() => {
			pending = undefined;
			deferred.reject();
		});
		return deferred.promise();
	}
	rememberTokens();
	// A transport preserves the caller's jqXHR, cancellation, converters and
	// callback lifecycle. It does not replace $.ajax or touch unrelated requests.
	$.ajaxTransport('+*', (options) => {
		const config = (window.directorist || {}).cache_interaction_tokens;
		if (!config || !config.enabled || options.directoristFreshTokens || options.async === false || options.dataTypes.some((type) => ['script', 'jsonp'].includes(type))) return;
		rememberTokens();
		let parts, endpoint;
		try {
			parts = requestParts(options);
			endpoint = new URL(config.url, window.location.href);
		} catch (error) {
			return;
		}
		if (parts.url.origin !== window.location.origin || endpoint.origin !== window.location.origin) return;
		const ajax = parts.url.pathname === endpoint.pathname;
		const rest = restRequest(parts.url);
		const hasTokens = ajax && [...parts.url.searchParams, ...parts.data].some(([field, value]) => tokenKind(field, value, parts.action));
		if (!hasTokens && !rest) return;
		let inner;
		let aborted = false;
		return {
			send(headers, complete) {
				// beforeSend may have supplied REST headers after transport selection.
				const restHeader = rest && Object.keys(headers).find((key) => key.toLowerCase() === 'x-wp-nonce');
				const ready = hasTokens || restHeader ? refreshTokens() : $.Deferred().resolve({}).promise();
				ready.done((tokens) => {
					if (aborted) return;
					for (const parameters of [parts.url.searchParams, parts.data]) {
						for (const [field, value] of parameters) {
							const key = tokenKind(field, value, parts.action);
							if (key) parameters.set(field, tokens[key]);
						}
					}
					const request = {
						...options, url: parts.url.href, global: false,
						directoristFreshTokens: true, headers: { ...headers },
						dataType: 'text', timeout: 0,
					};
					if (options.data instanceof FormData) request.data = parts.data;
					else if (typeof options.data === 'string' && !/^application\/json/i.test(options.contentType || '')) request.data = parts.data.toString();
					if (restHeader) {
						const header = Object.keys(request.headers).find((key) => key.toLowerCase() === 'x-wp-nonce') || restHeader;
						request.headers[header] = tokens.rest_nonce;
					}
					for (const key of ['success', 'error', 'complete', 'beforeSend', 'statusCode', 'dataFilter', 'dataTypes']) delete request[key];
					inner = $.ajax(request);
					inner.always(() => {
						if (!aborted) complete(inner.status, inner.statusText, { text: inner.responseText }, inner.getAllResponseHeaders());
					});
				}).fail(() => { if (!aborted) complete(0, 'error'); });
			},
			abort() {
				aborted = true;
				if (inner) inner.abort();
			},
		};
	});
})(window.jQuery);
