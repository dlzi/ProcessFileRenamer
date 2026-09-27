(() => {
	'use strict';

	const labelMarkup = '<div class="pfr-panel-label">Step 3: Scan references</div>';

	const setStatus = (panel, message, kind) => {
		const status = panel.querySelector('.pfr-scan-status');
		if(!status) return;
		status.replaceChildren();
		if(!message) return;

		const alert = document.createElement('div');
		alert.className = `uk-alert uk-alert-${kind}`;
		alert.textContent = message;
		status.append(alert);
	};

	const resetButton = (button) => {
		button.disabled = false;
		button.innerHTML = '<i class="fa fa-refresh"></i> Retry scan';
	};

	const setFlowStep = (currentStep) => {
		document.querySelectorAll('#pfr-flow [data-pfr-step]').forEach((step) => {
			const stepNumber = Number(step.dataset.pfrStep);
			step.classList.toggle('is-complete', stepNumber < currentStep);
			step.classList.toggle('is-current', stepNumber === currentStep);
			if(stepNumber === currentStep) {
				step.setAttribute('aria-current', 'step');
			} else {
				step.removeAttribute('aria-current');
			}
		});

		const currentItem = document.querySelector(`#pfr-flow [data-pfr-step="${currentStep}"]`);
		const eyebrow = document.getElementById('pfr-stage-eyebrow');
		if(currentItem && currentItem.lastElementChild && eyebrow) {
			eyebrow.textContent = `Step ${currentStep} of 4 · ${currentItem.lastElementChild.textContent}`;
		}
	};

	const setPagePreview = (container, message, kind) => {
		container.replaceChildren();
		if(!message) {
			container.hidden = true;
			return;
		}

		const preview = document.createElement('div');
		preview.className = `uk-alert uk-alert-${kind} uk-margin-small-top uk-text-small`;
		preview.textContent = message;
		container.append(preview);
		container.hidden = false;
	};

	const setFoundPagePreview = (container, title, pageId, assetCount) => {
		container.replaceChildren();
		const preview = document.createElement('div');
		preview.className = `uk-alert ${assetCount > 0 ? 'uk-alert-primary' : 'uk-alert-warning'} uk-margin-small-top uk-text-small`;

		const titleElement = document.createElement('strong');
		titleElement.className = 'pfr-page-preview-title';
		titleElement.textContent = title;

		const idElement = document.createElement('span');
		idElement.className = 'pfr-page-preview-id';
		idElement.textContent = `Page ID: ${pageId}`;

		preview.append(titleElement, idElement);
		if(assetCount < 1) {
			const warningElement = document.createElement('strong');
			warningElement.className = 'pfr-page-preview-warning';
			warningElement.textContent = 'No assets are available to rename on this page.';
			preview.append(warningElement);
		}
		container.append(preview);
		container.hidden = false;
	};

	const bindPagePreview = () => {
		const input = document.getElementById('pfr-page-id');
		const container = document.getElementById('pfr-page-preview');
		const findButton = document.querySelector('#file-renamer-page-lookup [name="find_page"]');
		if(!input || !container) return;

		let timerId;
		let controller;
		let requestId = 0;

		const requestPreview = async (pageId) => {
			if(controller) controller.abort();
			controller = typeof AbortController === 'function' ? new AbortController() : null;
			const thisRequestId = ++requestId;
			const options = {
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			};
			if(controller) options.signal = controller.signal;

			try {
				const response = await fetch(`./preview/?page_id=${encodeURIComponent(pageId)}`, options);
				if(!response.ok) throw new Error('The server did not complete the page lookup.');
				const data = await response.json();
				if(thisRequestId !== requestId) return;

				if(data.found) {
					const assetCount = Number(data.asset_count) || 0;
					setFoundPagePreview(container, data.title, data.page_id, assetCount);
					if(findButton) findButton.disabled = assetCount < 1;
				} else {
					setPagePreview(container, 'No available page was found with this ID.', 'warning');
					if(findButton) findButton.disabled = false;
				}
			} catch(error) {
				if(error.name === 'AbortError' || thisRequestId !== requestId) return;
				setPagePreview(container, 'The page could not be checked right now. You can still use Find assets.', 'warning');
				if(findButton) findButton.disabled = false;
			}
		};

		const getPageId = () => input.value.trim();
		const isPageId = (value) => /^[1-9]\d*$/.test(value);

		input.addEventListener('input', () => {
			clearTimeout(timerId);
			requestId++;
			if(controller) controller.abort();
			if(findButton) findButton.disabled = false;
			const pageId = getPageId();
			if(!isPageId(pageId)) {
				setPagePreview(container, '', '');
				return;
			}
			setPagePreview(container, 'Looking up page ID…', 'muted');
			timerId = setTimeout(() => requestPreview(pageId), 200);
		});

		input.addEventListener('blur', () => {
			clearTimeout(timerId);
			const pageId = getPageId();
			if(!isPageId(pageId)) return;
			requestPreview(pageId);
		});
	};

	const executeScan = async (button) => {
		const hash = button.dataset.hash;
		const pageId = button.dataset.page;
		const fieldName = button.dataset.field;
		const basename = button.dataset.basename;
		const scanPanel = document.getElementById(`pfr-scan-panel-${hash}`);
		const optionsPanel = document.getElementById(`pfr-options-${hash}`);
		const submitButton = document.getElementById(`pfr-submit-${hash}`);

		if(!scanPanel || !optionsPanel || !submitButton) return;

		button.disabled = true;
		button.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Scanning…';
		scanPanel.setAttribute('aria-busy', 'true');
		setFlowStep(3);
		setStatus(scanPanel, 'Scanning supported locations for hardcoded asset URLs.', 'primary');

		const query = new URLSearchParams({
			page_id: pageId,
			field_name: fieldName,
			basename
		});

		try {
			const response = await fetch(`./scan/?${query.toString()}`, {
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			});
			if(!response.ok) throw new Error('The server did not complete the scan.');

			const data = await response.json();
			if(!data.success) {
				setStatus(scanPanel, `Scan failed: ${data.message || 'Unknown error.'}`, 'danger');
				resetButton(button);
				setFlowStep(2);
				return;
			}

			scanPanel.innerHTML = labelMarkup + data.html_summary;
			optionsPanel.innerHTML = data.html_options;
			submitButton.disabled = !data.can_rename;
			setFlowStep(data.can_rename ? 4 : 3);
			scanPanel.setAttribute('tabindex', '-1');
			scanPanel.focus();
		} catch(error) {
			setStatus(scanPanel, 'Network error while scanning. Check the connection and try again.', 'danger');
			resetButton(button);
			setFlowStep(2);
		} finally {
			scanPanel.removeAttribute('aria-busy');
		}
	};

	const bind = () => {
		bindPagePreview();
		document.querySelectorAll('.pfr-rename-form .pfr-action-button[type="submit"]').forEach((button) => {
			button.disabled = true;
		});
		document.querySelectorAll('.pfr-scan-button').forEach((button) => {
			button.addEventListener('click', () => executeScan(button));
		});
	};

	if(document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind, { once: true });
	} else {
		bind();
	}
})();
