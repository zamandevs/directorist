const $ = jQuery;

function get_dom_data(selector, parent) {
	selector = '.directorist-dom-data-' + selector;
	if (!parent) {
		parent = document;
	}

	const el = parent.querySelector(selector);
	if (!el || !el.dataset.value) {
		return {};
	}

	const IS_SCRIPT_DEBUGGING =
		directorist &&
		directorist.script_debugging &&
		directorist.script_debugging == '1';

	try {
		let value = atob(el.dataset.value);
		return JSON.parse(value);
	} catch (error) {
		if (IS_SCRIPT_DEBUGGING) {
			console.log(el, error);
		}

		return {};
	}
}

function sanitizeClassList(classList) {
	return String(classList || '')
		.replace(/[^a-zA-Z0-9_\- ]/g, '')
		.replace(/\s+/g, ' ')
		.trim();
}

function resolveDirectoristIconURL(iconReference) {
	const reference = String(iconReference || '').trim();

	if (!reference) {
		return '';
	}

	if (/^(?:[a-z]+:)?\/\//i.test(reference) || reference[0] === '/') {
		return reference;
	}

	if (typeof directorist === 'undefined' || !directorist.assets_url) {
		return reference;
	}

	return directorist.assets_url + reference.replace(/^\//, '');
}

function renderDirectoristIcon(iconClass, extraClass = '', iconReference = '') {
	const classes = sanitizeClassList([iconClass, extraClass].join(' '));
	const fallbackClasses = sanitizeClassList(extraClass);
	const classMode =
		typeof directorist === 'undefined' ||
		directorist.icon_render_mode !== 'legacy_mask';

	if (
		classMode &&
		classes &&
		typeof directorist !== 'undefined' &&
		directorist.icon_class_markup
	) {
		return directorist.icon_class_markup
			.replace('##CLASS##', classes)
			.replace('##URL##', '');
	}

	const iconURL = resolveDirectoristIconURL(iconReference);

	if (iconURL && typeof directorist !== 'undefined') {
		const iconURLMarkup =
			directorist.icon_url_markup || directorist.icon_markup;

		if (iconURLMarkup) {
			return iconURLMarkup
				.replace('##URL##', iconURL)
				.replace('##CLASS##', fallbackClasses);
		}
	}

	if (iconURL) {
		return `<i class="directorist-icon-mask ${fallbackClasses}" aria-hidden="true" style="--directorist-icon: url(${iconURL})"></i>`;
	}

	if (classes) {
		return `<i class="directorist-icon-mask directorist-icon--font ${classes}" aria-hidden="true"></i>`;
	}

	return '';
}

function convertToSelect2(selector) {
	const $selector = $(selector);

	const args = {
		allowClear: true,
		width: '100%',
		templateResult: function (data) {
			if (!data.id) {
				return data.text;
			}

			var iconClass =
				$(data.element).data('iconClass') ||
				$(data.element).attr('data-icon-class');
			var iconURI = $(data.element).data('icon');
			var iconElm = renderDirectoristIcon(iconClass, '', iconURI);

			let originalText = data.text;
			let modifiedText = originalText.replace(/^(\s*)/, '$1' + iconElm);

			var $state = $(
				`<div class="directorist-select2-contents">${iconElm ? modifiedText : originalText}</div>`
			);

			return $state;
		},
	};

	const options = $selector.find('option');

	if (options.length && options[0].textContent.length) {
		args.placeholder = options[0].textContent;
	}

	$selector.length && $selector.select2(args);
}

export {
	convertToSelect2,
	get_dom_data,
	renderDirectoristIcon,
	resolveDirectoristIconURL,
};
