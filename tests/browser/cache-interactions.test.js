/* Run in a browser with WordPress jQuery, then load the production bootstrap. */
(function ($) {
	const reports = [];
	let calls = [];
	let tokenDelay = 5;
	let tokenFailure = false;
	const keys = ['nonce', 'directorist_nonce', 'ajax_nonce', 'ajaxnonce', 'rest_nonce', 'login_nonce', 'quick_login_nonce', 'author_sort_nonce', 'comment_edit_nonce', 'comment_nonce', 'email_nonce'];
	const tokens = Object.fromEntries(keys.map((key) => [key, `fresh-${key}`]));
	const originalAjax = $.ajax;
	window.directorist = {
		...Object.fromEntries(keys.map((key) => [key, `cached-${key}`])),
		cache_interaction_tokens: { enabled: true, url: '/wp-admin/admin-ajax.php', action: 'directorist_cache_interaction_tokens' },
		rest_url: `${location.origin}/wp-json/`,
	};
	function dataOf(options) {
		let result;
		try { result = Object.fromEntries(new URL(options.url, location.href).searchParams); }
		catch (error) { result = {}; }
		const data = options.data instanceof FormData ? options.data.entries() : new URLSearchParams(options.data || '').entries();
		for (const [key, value] of data) result[key] = value;
		return result;
	}
	// Installed first so the production transport has first refusal. No network,
	// WordPress state or third-party plugin requests are modified by this fixture.
	$.ajaxTransport('+*', (options) => {
		let timer;
		return {
			send(headers, complete) {
				const data = dataOf(options);
				const tokenRequest = data.action === 'directorist_cache_interaction_tokens';
				calls.push({ data, headers, url: options.url });
				timer = setTimeout(() => complete(
					tokenRequest && tokenFailure ? 503 : 200,
					tokenRequest && tokenFailure ? 'Unavailable' : 'OK',
					{ text: JSON.stringify(tokenRequest ? { success: !tokenFailure, data: tokens } : { success: true, data }) },
					'Content-Type: application/json\r\nX-Test: preserved\r\n'
				), tokenRequest ? tokenDelay : 2);
			},
			abort() { clearTimeout(timer); },
		};
	});
	function assert(value, message) { if (!value) throw new Error(message); }
	function request(options) {
		return new Promise((resolve, reject) => $.ajax(options).done(resolve).fail((xhr, status) => reject(new Error(status))));
	}
	function options(data, extra = {}) { return { url: '/wp-admin/admin-ajax.php', type: 'POST', dataType: 'json', data, ...extra }; }
	async function test(name, callback) {
		calls = [];
		tokenDelay = 5;
		tokenFailure = false;
		window.dispatchEvent(new Event('focus'));
		try { await callback(); reports.push({ name, passed: true }); }
		catch (error) { reports.push({ name, passed: false, error: error.message }); }
	}
	async function run() {
		await test('cached POST token refreshed; callbacks and headers occur once', async () => {
			let success = 0, complete = 0, statusCode = 0, beforeSend = 0, globalSends = 0;
			$(document).on('ajaxSend.cacheTest', () => globalSends++);
			const result = await request(options({ action: 'directorist_instant_search', _nonce: 'cached-ajax_nonce' }, {
				success: () => success++, complete: () => complete++, statusCode: { 200: () => statusCode++ },
				beforeSend: () => beforeSend++, headers: { 'X-Test-Request': 'kept' },
			}));
			$(document).off('.cacheTest');
			await new Promise((resolve) => setTimeout(resolve, 0));
			assert(result.data._nonce === tokens.ajax_nonce, 'cached token escaped');
			assert(success === 1 && complete === 1 && statusCode === 1 && beforeSend === 1 && globalSends === 1, 'duplicate or missing jQuery lifecycle');
			assert(calls[1].headers['X-Test-Request'] === 'kept', 'header lost');
			assert($.ajax === originalAjax, 'jQuery AJAX was replaced');
		});
		await test('concurrent interactions coalesce token request', async () => {
			const results = await Promise.all([1, 2].map(() => request(options({ action: 'atbdp_public_add_remove_favorites', directorist_nonce: 'cached-directorist_nonce' }))));
			assert(calls.filter((call) => call.data.action === 'directorist_cache_interaction_tokens').length === 1, 'duplicate token fetch');
			assert(results.every((result) => result.data.directorist_nonce === tokens.directorist_nonce), 'stale concurrent token');
		});
		await test('serialized form, duplicate fields and unrelated values preserved', async () => {
			const result = await request(options('action=atbdp_public_send_contact_email&directorist_nonce=cached-directorist_nonce&message=cached-directorist_nonce&category%5B%5D=1&category%5B%5D=2'));
			assert(result.data.directorist_nonce === tokens.directorist_nonce, 'serialized token stale');
			assert(result.data.message === 'cached-directorist_nonce', 'non-token content rewritten');
			assert(calls[1].data['category[]'] === '2', 'serialized fields lost');
		});
		await test('FormData remains intact without changing caller-owned form', async () => {
			const data = new FormData();
			data.append('action', 'directorist_process_comment_form');
			data.append('directorist_comment_nonce', 'old-comment-form-token');
			data.append('review', 'Great');
			const result = await request(options(data, { processData: false, contentType: false }));
			assert(result.data.directorist_comment_nonce === tokens.comment_nonce, 'review form token stale');
			assert(result.data.review === 'Great' && data.get('directorist_comment_nonce') === 'old-comment-form-token', 'caller form mutated');
		});
		await test('GET review edit token embedded in URL is refreshed', async () => {
			const result = await request({ url: '/wp-admin/admin-ajax.php?action=directorist_get_comment_edit_form&nonce=old-edit-token', dataType: 'json' });
			assert(result.data.nonce === tokens.comment_edit_nonce, 'GET URL token stale');
		});
		await test('cached login security token uses its own action', async () => {
			const result = await request(options({ action: 'ajaxlogin', security: 'old-login-token', password: 'untouched' }));
			assert(result.data.security === tokens.login_nonce && result.data.password === 'untouched', 'login token/action mismatch');
		});
		await test('failed refresh does not dispatch protected interaction', async () => {
			tokenFailure = true;
			let failed = false;
			try { await request(options({ action: 'directorist_instant_search', _nonce: 'cached-ajax_nonce' })); } catch (error) { failed = true; }
			assert(failed && calls.length === 1, 'protected request sent after failed refresh');
		});
		await test('aborted pending request stays aborted; sibling survives', async () => {
			tokenDelay = 30;
			const aborted = $.ajax(options({ action: 'directorist_instant_search', _nonce: 'cached-ajax_nonce' }));
			let status;
			aborted.fail((xhr, reason) => { status = reason; });
			const sibling = request(options({ action: 'directorist_taxonomy_pagination', nonce: 'cached-directorist_nonce' }));
			aborted.abort();
			await sibling;
			assert(status === 'abort' && calls.length === 2, 'aborted request dispatched or sibling canceled');
		});
		await test('timeout includes token waiting period', async () => {
			tokenDelay = 30;
			let status;
			await new Promise((resolve) => $.ajax(options({ action: 'directorist_instant_search', _nonce: 'cached-ajax_nonce' }, { timeout: 5 })).fail((xhr, reason) => { status = reason; resolve(); }));
			await new Promise((resolve) => setTimeout(resolve, 40));
			assert(status === 'timeout' && calls.length === 1, 'timed-out request dispatched');
		});
		await test('unrelated and cross-origin AJAX are untouched', async () => {
			await request(options({ action: 'other_plugin_action', nonce: 'unrelated-token' }));
			await request(options({ action: 'directorist_instant_search', _nonce: 'cached-ajax_nonce' }, { url: 'https://external.invalid/wp-admin/admin-ajax.php' }));
			assert(calls.length === 2 && calls[1].data._nonce === 'cached-ajax_nonce', 'unrelated request intercepted');
		});
		await test('REST request nonce header refreshed only for Directorist routes', async () => {
			await request({ url: '/wp-json/directorist/v1/listings', dataType: 'json', headers: { 'X-WP-Nonce': 'cached-rest_nonce' } });
			assert(calls[1].headers['X-WP-Nonce'] === tokens.rest_nonce, 'REST token stale');
		});
		await test('REST headers supplied by beforeSend are also refreshed', async () => {
			await request({ url: '/wp-json/directorist/v1/listings', dataType: 'json', beforeSend(xhr) { xhr.setRequestHeader('X-WP-Nonce', 'cached-rest_nonce'); } });
			assert(calls.length === 2 && calls[1].headers['X-WP-Nonce'] === tokens.rest_nonce, 'late REST header stale');
		});
		await test('ordinary WordPress REST nonce is not rewritten', async () => {
			await request({ url: '/wp-json/wp/v2/posts', dataType: 'json', headers: { 'X-WP-Nonce': 'other-rest-token' } });
			assert(calls.length === 1 && calls[0].headers['X-WP-Nonce'] === 'other-rest-token', 'unrelated REST header changed');
		});
		await test('beforeSend cancellation does not fetch tokens', async () => {
			const xhr = $.ajax(options({ action: 'directorist_instant_search', _nonce: 'cached-ajax_nonce' }, { beforeSend: () => false }));
			assert(xhr.state() === 'rejected' && calls.length === 0, 'canceled request fetched tokens');
		});
		await test('malformed unrelated URL does not throw from the Directorist transport', async () => {
			let thrown = false;
			try { $.ajax({ url: 'http://[', dataType: 'json', data: { action: 'unrelated' } }); } catch (error) { thrown = true; }
			assert(!thrown, 'unrelated URL parsing escaped the transport');
		});
		await test('disabled cache leaves original transport untouched', async () => {
			window.directorist.cache_interaction_tokens.enabled = false;
			await request(options({ action: 'directorist_instant_search', _nonce: 'cached-ajax_nonce' }));
			window.directorist.cache_interaction_tokens.enabled = true;
			assert(calls.length === 1 && calls[0].data._nonce === 'cached-ajax_nonce', 'disabled bootstrap intercepted');
		});
		window.directoristCacheTest = { passed: reports.every((report) => report.passed), reports };
		document.getElementById('result').textContent = JSON.stringify(window.directoristCacheTest, null, 2);
	}
	const script = document.createElement('script');
	const build = new URLSearchParams(location.search).get('build');
	script.src = build ? `/assets/build/js/global/cache-interactions${build === 'production' ? '.min' : ''}.js` : '/assets/src/js/global/cache-interactions.js';
	script.onload = run;
	script.onerror = () => { window.directoristCacheTest = { passed: false, error: 'Missing interaction bootstrap' }; };
	document.head.appendChild(script);
})(window.jQuery);
