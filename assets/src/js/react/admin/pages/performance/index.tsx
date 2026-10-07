import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import App from './app';
import { rootId } from './api';

domReady(() => {
	const element = document.getElementById(rootId);

	if (element) {
		createRoot(element).render(<App />);
	}
});
