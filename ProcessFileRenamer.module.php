<?php namespace ProcessWire;

/**
 * ProcessFileRenamer
 *
 * Admin utility for safely renaming ProcessWire Pagefile/Pageimage asset basenames.
 * Uses an on-demand, bounded reference scan and rechecks all safety conditions at submit time.
 */
class ProcessFileRenamer extends Process {

	const PERMISSION = 'file-renamer';
	const LOG_NAME = 'file-renamer';
	const TEXT_REFERENCE_LIMIT = 500;
	const TEMPLATE_REFERENCE_LIMIT = 500;
	const MAX_TEMPLATE_FILE_BYTES = 2097152; // 2 MB per file
	const CONFIG_CREATED_PERMISSION = 'created_permission';

	public function ___install() {
		$permissions = $this->wire()->permissions;
		$modules = $this->wire()->modules;
		$permissionExisted = $permissions->has(self::PERMISSION);

		parent::___install();

		$this->ensurePermissionExists();

		$config = $modules->getConfig(__CLASS__);
		if(!is_array($config)) $config = array();
		$config[self::CONFIG_CREATED_PERMISSION] = !$permissionExisted;
		$modules->saveConfig(__CLASS__, $config);
	}

	public function ___uninstall() {
		$config = $this->wire()->modules->getConfig(__CLASS__);
		$createdPermission = is_array($config) && !empty($config[self::CONFIG_CREATED_PERMISSION]);

		parent::___uninstall();

		if($createdPermission) {
			$this->removeModulePermission();
		}
	}

	protected function ensurePermissionExists() {
		$permissions = $this->wire()->permissions;
		if($permissions->has(self::PERMISSION)) return;

		$permission = $permissions->add(self::PERMISSION);
		if(!$permission || !$permission->id) {
			throw new WireException('Could not create required permission: ' . self::PERMISSION);
		}

		$permission->of(false);
		$permission->title = 'Rename ProcessWire uploaded asset filenames';
		$permission->save();
	}

	protected function removeModulePermission() {
		$wire = $this->wire();
		$permissions = $wire->permissions;
		if(!$permissions->has(self::PERMISSION)) return;

		$permission = $permissions->get(self::PERMISSION);
		if(!$permission || !$permission->id) return;

		foreach($wire->roles as $role) {
			if(!$role->hasPermission($permission)) continue;
			$role->of(false);
			$role->removePermission($permission);
			$role->save();
		}
		$permissions->delete($permission);
	}

	/**
	 * Default Process entry screen handler.
	 */
	public function ___execute() {
		$this->requireAccess();
		$this->loadAdminAssets();

		$input = $this->wire()->input;
		$action = (string) $input->post('file_renamer_action', 'text', '');

		if($action === 'rename') {
			try {
				$this->processRename();
			} catch(\Throwable $e) {
				$this->error($e->getMessage());
			}
		}

		return $this->renderScreen();
	}

	/**
	 * AJAX Scan Endpoint - Routed natively by ProcessWire via URL segment: setup/file-renamer/scan/
	 */
	public function ___executeScan() {
		$this->requireAccess();

		$input = $this->wire()->input;
		$pageId = (int) $input->get('page_id');
		$fieldName = (string) $input->get('field_name', 'fieldName');
		$basename = (string) $input->get('basename', 'text');

		$payload = array(
			'success' => false,
			'message' => '',
			'text_count' => 0,
			'template_count' => 0,
			'can_rename' => false,
			'html_summary' => '',
			'html_options' => ''
		);

		try {
			$page = $this->wire()->pages->get($pageId);
			if(!$page || !$page->id || !$this->canViewPage($page)) {
				throw new WirePermissionException('The requested asset page is not available to your role.');
			}

			$field = $this->wire()->fields->get($fieldName);
			if(!$field || !($field->type instanceof FieldtypeFile)) throw new \Exception('Invalid asset field configuration.');
			if(!$page->template->fieldgroup->hasField($field) || !$this->canEditPageField($page, $fieldName)) {
				throw new WirePermissionException('You do not have permission to rename assets in this field.');
			}

			$pagefiles = $page->getUnformatted($fieldName);
			if(!($pagefiles instanceof Pagefiles)) {
				throw new WireException('Asset field data could not be loaded.');
			}
			$file = $this->findPagefileByBasename($pagefiles, $basename);
			if(!$file) throw new \Exception('Target asset file not found inside field instance.');

			$report = $this->buildReferenceReport($file);

			$payload['success'] = true;
			$payload['text_count'] = count($report['text']);
			$payload['template_count'] = count($report['templates']);
			$payload['can_rename'] = !$this->hasBlockingReferenceConditions($report);
			$payload['html_summary'] = $this->renderReferenceSummary($report);
			$payload['html_options'] = $this->renderDynamicFormOptions($report, $file);

		} catch(\Throwable $e) {
			$payload['message'] = $e->getMessage();
		}

		header('Content-Type: application/json');
		echo json_encode($payload);
		exit();
	}

	/**
	 * Read-only page-ID preview endpoint for the lookup form.
	 */
	public function ___executePreview() {
		$this->requireAccess();

		$pageId = (int) $this->wire()->input->get('page_id');
		$payload = array(
			'found' => false,
			'page_id' => 0,
			'title' => '',
			'asset_count' => 0
		);

		if($pageId > 0) {
			$page = $this->wire()->pages->get($pageId);
			if($page && $page->id && $this->canViewPage($page)) {
				$title = '';
				if($this->canViewPage($page, 'title')) {
					$value = $page->getUnformatted('title');
					if(is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
						$title = trim((string) $value);
					}
				}
				$payload['found'] = true;
				$payload['page_id'] = (int) $page->id;
				$payload['title'] = $title !== '' ? $title : (string) $page->name;
				$payload['asset_count'] = $this->countRenameableAssets($page);
			}
		}

		header('Content-Type: application/json');
		echo json_encode($payload);
		exit();
	}

	protected function requireAccess() {
		$user = $this->wire()->user;
		if(!$user->hasPermission(self::PERMISSION)) {
			throw new WirePermissionException('You do not have permission to rename assets.');
		}
	}

	protected function loadAdminAssets() {
		$config = $this->wire()->config;
		$config->styles->add($config->urls->siteModules . 'ProcessFileRenamer/ProcessFileRenamer.css');
		$config->scripts->add($config->urls->siteModules . 'ProcessFileRenamer/ProcessFileRenamer.js');
	}

	protected function renderScreen() {
		$input = $this->wire()->input;
		$pageId = (int) $input->get('page_id', 'int', 0);
		$page = null;

		if($pageId > 0) {
			$candidate = $this->wire()->pages->get($pageId);
			if($candidate && $candidate->id && $this->canViewPage($candidate)) {
				$page = $candidate;
			}
		}

		$out = '';
		$out .= $this->renderIntro((bool) $page);
		$out .= $this->renderPageLookupForm($pageId);

		if($pageId > 0) {
			if($page) {
				$out .= $this->renderPageFiles($page);
			} else {
				$this->warning('Page not found or not available to your role.');
			}
		}

		return $out;
	}

	protected function renderIntro($hasSelectedPage = false) {
		$currentStep = $hasSelectedPage ? 2 : 1;
		$out = '<div class="uk-card uk-card-default uk-card-small pfr-card">';
		$out .= '<div class="uk-card-body">';
		$out .= '<h2 class="uk-h3 uk-margin-remove-top">Rename uploaded assets safely</h2>';
		$out .= '<p class="uk-text-muted">' . ($hasSelectedPage
			? 'Choose an asset below. Its reference scan and rename controls will appear together.'
			: 'Start by finding the page that owns the asset you want to rename.') . '</p>';
		$out .= '<ol id="pfr-flow" class="pfr-flow" aria-label="Asset rename workflow">';
		$out .= $this->renderFlowStep(1, $currentStep, 'Find owner page');
		$out .= $this->renderFlowStep(2, $currentStep, 'Choose asset');
		$out .= $this->renderFlowStep(3, $currentStep, 'Scan references');
		$out .= $this->renderFlowStep(4, $currentStep, 'Rename asset');
		$out .= '</ol>';
		$out .= '</div></div>';
		return $out;
	}

	protected function renderFlowStep($step, $currentStep, $label) {
		$classes = 'pfr-flow-step';
		if($step < $currentStep) $classes .= ' is-complete';
		if($step === $currentStep) $classes .= ' is-current';
		$current = $step === $currentStep ? ' aria-current="step"' : '';
		return '<li class="' . $classes . '" data-pfr-step="' . (int) $step . '"' . $current . '><span class="pfr-flow-number">' . (int) $step . '</span><span>' . $this->wire()->sanitizer->entities($label) . '</span></li>';
	}

	protected function renderPageLookupForm($pageId) {
		$modules = $this->wire()->modules;

		/** @var InputfieldForm $form */
		$form = $modules->get('InputfieldForm');
		$form->method = 'get';
		$form->action = './';
		$form->attr('id', 'file-renamer-page-lookup');
		$form->attr('class', trim($form->attr('class') . ' uk-form-stacked'));

		/** @var InputfieldInteger $field */
		$field = $modules->get('InputfieldInteger');
		$field->attr('name', 'page_id');
		$field->attr('id', 'pfr-page-id');
		$field->attr('value', $pageId > 0 ? $pageId : '');
		$field->attr('class', trim($field->attr('class') . ' uk-input uk-form-width-medium'));
		$field->attr('inputmode', 'numeric');
		$field->attr('aria-describedby', 'pfr-page-preview');
		$field->label = 'Step 1: Find the owner page';
		$field->description = 'Enter or paste the ID of the page that owns the asset. Its title appears automatically when available.';
		$field->required = true;
		$form->add($field);

		/** @var InputfieldSubmit $submit */
		$submit = $modules->get('InputfieldSubmit');
		$submit->attr('name', 'find_page');
		$submit->attr('value', 'Find assets');
		$submit->attr('class', trim($submit->attr('class') . ' uk-button uk-button-primary pfr-action-button'));
		$form->add($submit);

		return '<div class="uk-card uk-card-default uk-card-small pfr-card"><div class="uk-card-body">' . $form->render() . '<div id="pfr-page-preview" class="pfr-page-preview" role="status" aria-live="polite" aria-atomic="true" hidden></div></div></div>';
	}

	protected function renderPageFiles(Page $page) {
		$sanitizer = $this->wire()->sanitizer;
		$out = '<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-top"><div>';
		$out .= '<div id="pfr-stage-eyebrow" class="pfr-stage-eyebrow" aria-live="polite">Step 2 of 4 · Choose an asset</div>';
		$out .= '<h2 class="uk-h3 uk-margin-remove-bottom">' . $sanitizer->entities($page->title ?: $page->name) . ' <span class="uk-text-muted">#' . (int) $page->id . '</span></h2>';
		$out .= '<div class="uk-text-meta">Template: <code>' . $sanitizer->entities($page->template->name) . '</code></div>';
		$out .= '</div>';
		if($this->canEditPage($page)) {
			$out .= '<a class="uk-button uk-button-default pfr-action-button" href="' . $sanitizer->entities($page->editUrl()) . '">Open page editor</a>';
		}
		$out .= '</div>';

		$fields = $this->getRenameableAssetFields($page);
		if(!count($fields)) {
			return $out . '<div class="uk-alert uk-alert-warning pfr-no-assets"><strong>No assets are available to rename on this page.</strong> Try another page ID, or check whether your role can edit its asset fields.</div>';
		}

		foreach($fields as $field) {
			$out .= $this->renderFieldFiles($page, $field);
		}

		return $out;
	}

	protected function getPageFileFields(Page $page) {
		$result = array();
		foreach($page->template->fieldgroup as $field) {
			if($field->type instanceof FieldtypeFile && $this->canViewPage($page, $field->name)) {
				$result[] = $field;
			}
		}
		return $result;
	}

	protected function getRenameableAssetFields(Page $page) {
		$result = array();
		foreach($this->getPageFileFields($page) as $field) {
			if(!$this->canEditPageField($page, $field->name)) continue;
			$files = $page->getUnformatted($field->name);
			if($files instanceof Pagefiles && count($files)) $result[] = $field;
		}
		return $result;
	}

	protected function countRenameableAssets(Page $page) {
		$count = 0;
		foreach($this->getRenameableAssetFields($page) as $field) {
			$files = $page->getUnformatted($field->name);
			$count += count($files);
		}
		return $count;
	}

	protected function canViewPage(Page $page, $fieldName = '') {
		try {
			return (bool) $page->viewable((string) $fieldName);
		} catch(\Throwable $e) {
			return false;
		}
	}

	protected function canEditPage(Page $page) {
		try {
			return (bool) $page->editable();
		} catch(\Throwable $e) {
			return false;
		}
	}

	protected function canEditPageField(Page $page, $fieldName) {
		if(!$this->canViewPage($page, $fieldName)) return false;
		try {
			return (bool) $page->editable((string) $fieldName);
		} catch(\Throwable $e) {
			// Fallback cascade.
		}
		try {
			return (bool) $page->editable();
		} catch(\Throwable $e) {
			return false;
		}
	}

	protected function renderFieldFiles(Page $page, Field $field) {
		$sanitizer = $this->wire()->sanitizer;
		$files = $page->getUnformatted($field->name);
		if(!($files instanceof Pagefiles) || !count($files)) return '';

		$out = '<h3 class="uk-h4 uk-heading-bullet uk-margin-top">Asset field: <code>' . $sanitizer->entities($field->name) . '</code></h3>';
		if(!$this->canEditPageField($page, $field->name)) {
			return $out . '<div class="uk-alert uk-alert-warning">You can view this field, but your role cannot edit it. Rename is disabled for this field.</div>';
		}

		$out .= '<div class="pfr-file-list">';
		foreach($files as $file) {
			$out .= $this->renderFileCard($page, $field, $file);
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * Optimized File Card Rendering (Zero calculations on initial layout output)
	 */
	protected function renderFileCard(Page $page, Field $field, Pagefile $file) {
		$sanitizer = $this->wire()->sanitizer;
		$basename = $file->basename;
		$isImage = $file instanceof Pageimage;
		$fileHash = $sanitizer->entities($file->hash);
		$assetLabel = $sanitizer->entities($basename);

		$preview = $isImage
			? '<a href="' . $sanitizer->entities($file->url) . '" target="_blank" rel="noopener" aria-label="Open full-size asset ' . $assetLabel . ' in a new tab"><img class="pfr-preview" src="' . $sanitizer->entities($file->url) . '" alt="Preview of ' . $assetLabel . '" /></a>'
			: '<a class="uk-button uk-button-default pfr-action-button" href="' . $sanitizer->entities($file->url) . '" target="_blank" rel="noopener">Open asset</a>';

		$out = '<div class="uk-card uk-card-default uk-card-small pfr-file-card" id="pfr-card-' . $fileHash . '">';
		$out .= '<div class="uk-card-body">';
		$out .= '<div class="pfr-file-card-grid">';
		
		// Panel 1: Asset details
		$out .= '<div class="pfr-section-card pfr-file-main">';
		$out .= '<div class="pfr-panel-label">Asset</div>';
		$out .= '<div class="pfr-preview-wrap">' . $preview . '</div>';
		$out .= '<div class="pfr-file-title uk-text-bold uk-text-break"><code>' . $sanitizer->entities($basename) . '</code></div>';
		$out .= '<div class="uk-text-meta uk-text-break">' . $sanitizer->entities($file->url) . '</div>';
		$out .= '</div>';
		
		// Panel 2: On-demand scan output target placeholder
		$out .= '<div class="pfr-section-card pfr-scan-panel" id="pfr-scan-panel-' . $fileHash . '" role="status" aria-live="polite" aria-atomic="true">';
		$out .= '<div class="pfr-panel-label">Step 3: Scan references</div>';
		$out .= '<div class="pfr-scan-status"></div>';
		$out .= '<div class="uk-text-center uk-padding-small pfr-scan-button-wrap">';
		$out .= '<p class="uk-text-muted uk-text-small uk-margin-small-bottom">Check this asset for hardcoded references before continuing.</p>';
		$out .= '<button type="button" class="uk-button uk-button-default uk-button-small pfr-scan-button" data-hash="' . $fileHash . '" data-page="' . (int) $page->id . '" data-field="' . $sanitizer->entities($field->name) . '" data-basename="' . $sanitizer->entities($basename) . '">';
		$out .= '<i class="fa fa-search"></i> Scan for References';
		$out .= '</button>';
		$out .= '</div>';
		$out .= '</div>';
		
		// Panel 3: Conditional configuration shell
		$out .= '<div class="pfr-section-card pfr-rename-panel">';
		$out .= '<div class="pfr-panel-label">Step 4: Rename asset</div>';
		$out .= $this->renderRenameForm($page, $field, $file);
		$out .= '</div>';
		
		$out .= '</div>';
		$out .= '</div>';
		$out .= '</div>';

		return $out;
	}

	protected function renderRenameForm(Page $page, Field $field, Pagefile $file) {
		$sanitizer = $this->wire()->sanitizer;
		$csrf = $this->wire()->session->CSRF;
		$suggested = pathinfo($file->basename, PATHINFO_FILENAME);
		$fileHash = $sanitizer->entities($file->hash);

		$out = '<form class="uk-form-stacked pfr-rename-form" method="post" action="./?page_id=' . (int) $page->id . '">';
		$out .= $csrf->renderInput();
		$out .= '<input type="hidden" name="file_renamer_action" value="rename" />';
		$out .= '<input type="hidden" name="page_id" value="' . (int) $page->id . '" />';
		$out .= '<input type="hidden" name="field_name" value="' . $sanitizer->entities($field->name) . '" />';
		$out .= '<input type="hidden" name="old_basename" value="' . $sanitizer->entities($file->basename) . '" />';

		$out .= '<div class="pfr-option-group">';
		$out .= '<label class="uk-form-label" for="pfr-new-' . $fileHash . '">New asset filename base</label>';
		$out .= '<input id="pfr-new-' . $fileHash . '" class="uk-input uk-form-small pfr-new-basename-input" type="text" name="new_basename" value="' . $sanitizer->entities($suggested) . '" required aria-describedby="pfr-extension-' . $fileHash . '" />';
		$out .= '<div id="pfr-extension-' . $fileHash . '" class="uk-text-meta">The current extension <code>.' . $sanitizer->entities($file->ext()) . '</code> is preserved.</div>';
		$out .= '</div>';

		// Dynamic Container Area injected over the wire post-scan
		$out .= '<div class="pfr-dynamic-options" id="pfr-options-' . $fileHash . '">';
		$out .= '<div class="uk-alert uk-alert-muted uk-margin-small uk-text-small">Complete the reference scan to unlock safe rename options.</div>';
		$out .= '</div>';

		$out .= '<div class="uk-margin-small-top">';
		$out .= '<button type="submit" id="pfr-submit-' . $fileHash . '" class="uk-button uk-button-primary pfr-action-button">Rename asset</button>';
		$out .= '</div>';
		$out .= '</form>';

		return $out;
	}

	/**
	 * Build dynamic layout control sets targeting custom adjustments discovered over AJAX
	 */
	protected function renderDynamicFormOptions(array $referenceReport, Pagefile $file) {
		$textCount = count($referenceReport['text']);
		$templateCount = count($referenceReport['templates']);
		$isImage = $file instanceof Pageimage;
		$out = '';

		$out .= '<p class="uk-text-meta uk-text-small pfr-scan-scope">The scan covers supported text fields and PHP, INC, HTML, HTM, Twig, Latte, and TPL files up to 2 MB. It does not cover every storage location.</p>';

		if($this->hasBlockingReferenceConditions($referenceReport)) {
			$out .= '<div class="uk-alert uk-alert-danger uk-margin-small"><strong>Rename unavailable.</strong> ' . $this->wire()->sanitizer->entities($this->getBlockingReferenceMessage($referenceReport)) . '</div>';
			$out .= '<input type="hidden" name="update_text_refs" value="0" />';
			$out .= '<input type="hidden" name="remove_variations" value="0" />';
			$out .= '<input type="hidden" name="ignore_template_warnings" value="0" />';
			return $out;
		}

		$out .= '<fieldset class="pfr-option-group">';
		$out .= '<legend class="uk-form-label">Text-field URL handling</legend>';
		if($textCount) {
			$out .= '<div class="uk-alert uk-alert-warning uk-margin-small"><strong>' . (int) $textCount . ' writable text-field URL match(es) found.</strong> Choose how to handle them:</div>';
			$out .= $this->renderRadioOption('update_text_refs', '1', true, 'Update matched text URLs after rename', 'Recommended. Updates exact hardcoded asset URLs in fields you may edit.');
			$out .= $this->renderRadioOption('update_text_refs', '0', false, 'Do not update text fields', 'The rename remains blocked until you update those URLs manually.');
		} else {
			$out .= '<input type="hidden" name="update_text_refs" value="0" />';
			$out .= '<div class="uk-alert uk-alert-primary uk-margin-small">No matches were found in supported text fields.</div>';
		}
		$out .= '</fieldset>';

		if($isImage) {
			$out .= '<fieldset class="pfr-option-group">';
			$out .= '<legend class="uk-form-label">Image variation handling</legend>';
			$out .= $this->renderRadioOption('remove_variations', '0', true, 'Keep existing variations', 'Recommended. ProcessWire renames existing variation filenames with the image.');
			$out .= $this->renderRadioOption('remove_variations', '1', false, 'Delete existing variations after a successful rename', 'Advanced cleanup. Deletes generated crops and renders; they are recreated only when requested later.');
			$out .= '</fieldset>';
		} else {
			$out .= '<input type="hidden" name="remove_variations" value="0" />';
		}

		if($templateCount) {
			$out .= '<fieldset class="pfr-option-group">';
			$out .= '<legend class="uk-form-label">Template-file warning handling</legend>';
			$out .= '<div class="uk-alert uk-alert-danger uk-margin-small"><strong>' . (int) $templateCount . ' template reference(s) found.</strong> The module does not edit template files.</div>';
			$out .= $this->renderRadioOption('ignore_template_warnings', '0', true, 'Stop until template files are reviewed', 'Recommended. Correct each hardcoded asset URL before renaming.');
			$out .= $this->renderRadioOption('ignore_template_warnings', '1', false, 'I reviewed the template-file warnings', 'I understand that template references must be corrected manually.');
			$out .= '</fieldset>';
		} else {
			$out .= '<input type="hidden" name="ignore_template_warnings" value="0" />';
		}

		return $out;
	}

	protected function hasBlockingReferenceConditions(array $referenceReport) {
		return count($referenceReport['blocked_text'])
			|| !empty($referenceReport['restricted_text_count'])
			|| count($referenceReport['text_overflow_fields'])
			|| !empty($referenceReport['template_limit_reached'])
			|| !empty($referenceReport['template_skipped_large_count'])
			|| !empty($referenceReport['template_skipped_unreadable_count']);
	}

	protected function getBlockingReferenceMessage(array $referenceReport) {
		$reasons = array();
		if(count($referenceReport['blocked_text'])) {
			$reasons[] = 'Some matching text fields are visible but not editable by your role.';
		}
		if(!empty($referenceReport['restricted_text_count'])) {
			$reasons[] = 'Some matching text fields are outside your access scope.';
		}
		if(count($referenceReport['text_overflow_fields'])) {
			$reasons[] = 'At least one text-field search exceeded the 500-page safety limit.';
		}
		if(!empty($referenceReport['template_limit_reached'])) {
			$reasons[] = 'The template-reference result limit was reached.';
		}
		if(!empty($referenceReport['template_skipped_large_count'])) {
			$reasons[] = 'At least one supported template file exceeded the 2 MB scan limit.';
		}
		if(!empty($referenceReport['template_skipped_unreadable_count'])) {
			$reasons[] = 'At least one supported template file could not be read.';
		}
		return implode(' ', $reasons);
	}

	protected function renderRadioOption($name, $value, $checked, $label, $description) {
		$sanitizer = $this->wire()->sanitizer;
		$out = '<label class="pfr-radio-option">';
		$out .= '<span class="pfr-radio-control"><input class="uk-radio" type="radio" name="' . $sanitizer->entities($name) . '" value="' . $sanitizer->entities($value) . '"' . ($checked ? ' checked' : '') . ' /></span>';
		$out .= '<span class="pfr-radio-content"><span class="pfr-radio-title">' . $sanitizer->entities($label) . '</span><span class="pfr-radio-description">' . $sanitizer->entities($description) . '</span></span>';
		$out .= '</label>';
		return $out;
	}

	protected function processRename() {
		$this->requireAccess();

		$wire = $this->wire();
		$input = $wire->input;
		$sanitizer = $wire->sanitizer;
		$session = $wire->session;
		$pages = $wire->pages;
		$fields = $wire->fields;
		$log = $wire->log;

		$session->CSRF->validate();

		$pageId = (int) $input->post('page_id', 'int', 0);
		$fieldName = (string) $input->post('field_name', 'fieldName', '');
		$oldBasename = (string) $input->post('old_basename', 'text', '');
		$newInput = (string) $input->post('new_basename', 'text', '');
		$updateTextRefs = (bool) $input->post('update_text_refs', 'int', 0);
		$removeVariations = (bool) $input->post('remove_variations', 'int', 0);
		$ignoreTemplateWarnings = (bool) $input->post('ignore_template_warnings', 'int', 0);

		if(!$pageId || !$fieldName || !$oldBasename || !$newInput) {
			throw new WireException('Missing required rename input parameters.');
		}

		$page = $pages->get($pageId);
		if(!$page || !$page->id || !$this->canViewPage($page)) {
			throw new WirePermissionException('The requested asset page is not available to your role.');
		}

		$field = $fields->get($fieldName);
		if(!$field || !$field->id || !($field->type instanceof FieldtypeFile)) {
			throw new WireException('Field is not a valid uploaded asset field.');
		}

		if(!$page->template->fieldgroup->hasField($field)) {
			throw new WireException('The selected page context does not share this field.');
		}

		if(!$this->canEditPageField($page, $fieldName)) {
			throw new WirePermissionException('You do not have active write clearance on this field context.');
		}

		$outputFormatting = $page->of();
		if($outputFormatting) $page->of(false);

		try {
			$pagefiles = $page->getUnformatted($fieldName);
			if(!($pagefiles instanceof Pagefiles)) {
				throw new WireException('Asset field data could not be loaded.');
			}

			$file = $this->findPagefileByBasename($pagefiles, $oldBasename);
			if(!$file) throw new WireException('The original asset no longer exists in this field.');

			$newBasename = $this->buildNewBasename($newInput, $file);
			if($newBasename === $file->basename) {
				throw new WireException('The new asset filename is the same as the current filename.');
			}

			if($this->findPagefileByBasename($pagefiles, $newBasename)) {
				throw new WireException('An asset with the requested filename already exists in this field.');
			}

			// Re-scan at submit time so client-side state can never bypass safety checks.
			$referenceReport = $this->buildReferenceReport($file);
			if($this->hasBlockingReferenceConditions($referenceReport)) {
				throw new WireException($this->getBlockingReferenceMessage($referenceReport));
			}
			$replacementMap = $this->buildUrlReplacementMap($file, $newBasename);

			if(count($referenceReport['text']) && !$updateTextRefs) {
				throw new WireException('Matching text-field URLs were found. Update them or correct them manually before renaming.');
			}

			if(count($referenceReport['templates']) && !$ignoreTemplateWarnings) {
				throw new WireException('Template references were found. Review and correct them before renaming, or explicitly acknowledge the warning.');
			}

			if($removeVariations && $this->hasVariationReferences($referenceReport, $file)) {
				throw new WireException('Variation URLs are still referenced. Keep the existing variations or correct those URLs first.');
			}

			$renamed = false;
			$ownerSaved = false;
			$updatedRefs = array();
			$removedVariations = array();

			try {
				$result = $file->rename($newBasename);
				if(!$result) throw new WireException('ProcessWire rejected the asset rename.');
				$renamed = true;
				if($result !== $newBasename) {
					throw new WireException('The requested asset filename became unavailable during the rename.');
				}

				if(!$page->save($fieldName)) {
					throw new WireException('The asset was renamed, but its owning field could not be saved.');
				}
				$ownerSaved = true;

				if($updateTextRefs && count($referenceReport['text'])) {
					$this->updateTextReferences($referenceReport['text'], $replacementMap, $updatedRefs);
				}

				// Deletion is intentionally last: a rename/update failure never removes variations first.
				if($removeVariations && $file instanceof Pageimage) {
					$removedVariations = $file->removeVariations(array('getFiles' => true));
					if(!is_array($removedVariations)) $removedVariations = array();
				}
			} catch(\Throwable $e) {
				if($renamed && !$ownerSaved) {
					$rolledBack = false;
					try {
						$rolledBack = (bool) $file->rename($oldBasename) && (bool) $page->save($fieldName);
					} catch(\Throwable $rollbackError) {
						$log->save(self::LOG_NAME, 'status=rollback_failed page=' . (int) $page->id . ' field=' . $fieldName . ' old=' . $oldBasename . ' new=' . $newBasename . ' error=' . $rollbackError->getMessage());
					}
					if($rolledBack) {
						throw new WireException('The rename could not be saved and was rolled back. No asset changes were kept.');
					}
				}

				if($ownerSaved) {
					$log->save(self::LOG_NAME, sprintf(
						'status=partial user=%s page=%d field=%s old=%s new=%s updated_text_refs=%d error=%s',
						$wire->user->name,
						$page->id,
						$fieldName,
						$oldBasename,
						$newBasename,
						count($updatedRefs),
						$e->getMessage()
					));
					throw new WireException('The asset was renamed, but later work was incomplete after ' . count($updatedRefs) . ' text-field update(s). Review the file-renamer log before retrying.');
				}

				throw $e;
			}

			$log->save(self::LOG_NAME, sprintf(
				'status=complete user=%s page=%d field=%s old=%s new=%s updated_text_refs=%d removed_variations=%d',
				$wire->user->name,
				$page->id,
				$fieldName,
				$oldBasename,
				$newBasename,
				count($updatedRefs),
				count($removedVariations)
			));

			$this->message(sprintf(
				'Renamed asset %s to %s. Updated %d text field(s). Removed %d image variation(s).',
				$sanitizer->entities($oldBasename),
				$sanitizer->entities($newBasename),
				count($updatedRefs),
				count($removedVariations)
			));
		} finally {
			if($page->of() !== $outputFormatting) $page->of($outputFormatting);
		}
	}

	protected function findPagefileByBasename(Pagefiles $files, $basename) {
		foreach($files as $file) {
			if($file->basename === $basename) return $file;
		}
		return null;
	}

	protected function buildNewBasename($newInput, Pagefile $file) {
		$sanitizer = $this->wire()->sanitizer;
		$newInput = trim((string) $newInput);
		$newInput = str_replace(array('\\', '/'), '-', $newInput);
		$newInput = pathinfo($newInput, PATHINFO_FILENAME);
		$newInput = $sanitizer->pageName($newInput, true);

		if($newInput === '') throw new WireException('Filename construction components parsed blank post sanitization.');

		$ext = $file->ext();
		if($ext === '') throw new WireException('Unable to accurately determine core file container extension parameters.');

		// Match Pagefile::rename() normalization before collision detection and URL replacement.
		return $file->pagefiles->cleanBasename($newInput . '.' . $ext, false);
	}

	protected function buildReferenceReport(Pagefile $file) {
		$needles = $this->getReferenceNeedles($file);
		$textReport = $this->scanTextReferences($needles);
		$templateReport = $this->scanTemplateReferences($needles);
		return array(
			'text' => $textReport['matches'],
			'blocked_text' => $textReport['blocked'],
			'restricted_text_count' => $textReport['restricted_count'],
			'text_overflow_fields' => $textReport['overflow_fields'],
			'templates' => $templateReport['matches'],
			'template_limit_reached' => $templateReport['limit_reached'],
			'template_skipped_large_count' => $templateReport['skipped_large_count'],
			'template_skipped_unreadable_count' => $templateReport['skipped_unreadable_count']
		);
	}

	protected function getReferenceNeedles(Pagefile $file) {
		$needles = array();
		$this->addPagefileUrlNeedles($file, $needles);

		if($file instanceof Pageimage) {
			foreach($file->getVariations() as $variation) {
				if($variation instanceof Pagefile) {
					$this->addPagefileUrlNeedles($variation, $needles);
				}
			}
		}

		return array_values(array_unique(array_filter($needles)));
	}

	protected function addPagefileUrlNeedles(Pagefile $file, array &$needles) {
		$relativeUrl = $file->url;
		$httpUrl = $this->getHttpUrl($file);
		$needles[] = $relativeUrl;
		if($httpUrl) $needles[] = $httpUrl;

		if(!method_exists($file, 'getFiles')) return;

		$relativeBaseUrl = substr($relativeUrl, 0, -1 * strlen($file->basename));
		$httpBaseUrl = $httpUrl ? substr($httpUrl, 0, -1 * strlen($file->basename)) : '';

		foreach($file->getFiles() as $filename) {
			$extraBasename = basename($filename);
			if(!$extraBasename || $extraBasename === $file->basename) continue;
			$needles[] = $relativeBaseUrl . $extraBasename;
			if($httpBaseUrl) $needles[] = $httpBaseUrl . $extraBasename;
		}
	}

	protected function getHttpUrl(Pagefile $file) {
		try {
			$url = $file->httpUrl;
			return is_string($url) ? $url : '';
		} catch(\Throwable $e) {
			return '';
		}
	}

	/**
	 * Bundles all needles into one OR-value selector per field and fails closed when a field exceeds the scan limit.
	 */
	protected function scanTextReferences(array $needles) {
		$pages = $this->wire()->pages;
		$sanitizer = $this->wire()->sanitizer;
		$report = array(
			'matches' => array(),
			'blocked' => array(),
			'restricted_count' => 0,
			'overflow_fields' => array()
		);
		$seen = array();

		$selectorValues = array();
		foreach($needles as $needle) {
			if($needle === '') continue;
			$selectorValues[] = $sanitizer->selectorValue($needle);
		}

		if(!count($selectorValues)) return $report;
		$selectorString = implode('|', $selectorValues);

		foreach($this->getTextFields() as $field) {
			// include=all lets us fail closed without exposing inaccessible page details.
			$foundPages = $pages->find("include=all, {$field->name}%={$selectorString}, limit=" . (self::TEXT_REFERENCE_LIMIT + 1));
			if($foundPages->getTotal() > self::TEXT_REFERENCE_LIMIT) {
				$report['overflow_fields'][] = $field->name;
				continue;
			}

			foreach($foundPages as $page) {
				if(!$page->template->fieldgroup->hasField($field)) continue;
				$value = $page->getUnformatted($field->name);
				if(!is_string($value)) continue;

				// Verify actual matching coordinates in memory securely
				foreach($needles as $needle) {
					if($needle === '' || strpos($value, $needle) === false) continue;

					$key = $page->id . ':' . $field->name . ':' . md5($needle);
					if(isset($seen[$key])) continue;
					$seen[$key] = true;

					if(!$this->canViewPage($page, $field->name)) {
						$report['restricted_count']++;
						continue;
					}
					if(!$this->canEditPageField($page, $field->name)) {
						$report['blocked'][] = array(
							'page_id' => (int) $page->id,
							'field' => (string) $field->name
						);
						continue;
					}

					$report['matches'][] = array(
						'page_id' => (int) $page->id,
						'page_title' => (string) ($page->title ?: $page->name),
						'field' => (string) $field->name,
						'needle' => (string) $needle,
						'edit_url' => (string) $page->editUrl()
					);
				}
			}
		}

		return $report;
	}

	protected function getTextFields() {
		return $this->wire()->fields->findByType('FieldtypeText');
	}

	protected function scanTemplateReferences(array $needles) {
		$config = $this->wire()->config;
		$root = $config->paths->templates;
		$report = array(
			'matches' => array(),
			'limit_reached' => false,
			'skipped_large_count' => 0,
			'skipped_unreadable_count' => 0
		);
		$allowedExtensions = array('php', 'inc', 'html', 'htm', 'twig', 'latte', 'tpl');

		if(!is_dir($root)) return $report;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
		);

		foreach($iterator as $fileInfo) {
			/** @var \SplFileInfo $fileInfo */
			if(!$fileInfo->isFile()) continue;
			$ext = strtolower($fileInfo->getExtension());
			if(!in_array($ext, $allowedExtensions, true)) continue;
			if(!$fileInfo->isReadable()) {
				$report['skipped_unreadable_count']++;
				continue;
			}
			if($fileInfo->getSize() > self::MAX_TEMPLATE_FILE_BYTES) {
				$report['skipped_large_count']++;
				continue;
			}

			$contents = file_get_contents($fileInfo->getPathname());
			if(!is_string($contents)) {
				$report['skipped_unreadable_count']++;
				continue;
			}

			foreach($needles as $needle) {
				if($needle === '' || strpos($contents, $needle) === false) continue;
				if(count($report['matches']) >= self::TEMPLATE_REFERENCE_LIMIT) {
					$report['limit_reached'] = true;
					break 2;
				}
				$report['matches'][] = array(
					'file' => str_replace($root, '', $fileInfo->getPathname()),
					'needle' => $needle
				);
			}
		}

		return $report;
	}

	protected function hasVariationReferences(array $referenceReport, Pagefile $file) {
		if(!($file instanceof Pageimage)) return false;

		foreach(array('text', 'templates') as $group) {
			foreach($referenceReport[$group] as $reference) {
				$needle = isset($reference['needle']) ? (string) $reference['needle'] : '';
				$path = parse_url($needle, PHP_URL_PATH);
				if(!$path) $path = $needle;
				if(basename($path) !== $file->basename) return true;
			}
		}

		return false;
	}

	protected function buildUrlReplacementMap(Pagefile $file, $newBasename) {
		$oldBasename = $file->basename;
		$oldExt = $file->ext();
		$oldName = basename($oldBasename, '.' . $oldExt);
		$newName = basename($newBasename, '.' . $oldExt);
		$map = array();

		foreach($this->getReferenceNeedles($file) as $oldUrl) {
			$newUrl = $this->replaceUrlFilenamePrefix($oldUrl, $oldBasename, $newBasename, $oldName, $newName, $oldExt);
			if($newUrl && $newUrl !== $oldUrl) $map[$oldUrl] = $newUrl;
		}

		return $map;
	}

	protected function replaceUrlFilenamePrefix($url, $oldBasename, $newBasename, $oldName, $newName, $oldExt) {
		$parts = parse_url($url);
		$path = isset($parts['path']) ? $parts['path'] : $url;
		$filename = basename($path);
		$newFilename = '';

		if($filename === $oldBasename) {
			$newFilename = $newBasename;
		} else if(strpos($filename, $oldName) === 0) {
			$newFilename = $newName . substr($filename, strlen($oldName));
		}

		if($newFilename === '') return '';

		$newPath = substr($path, 0, -1 * strlen($filename)) . $newFilename;
		$rebuilt = '';

		if(isset($parts['host'])) {
			if(isset($parts['scheme'])) $rebuilt .= $parts['scheme'] . '://';
			if(isset($parts['user'])) {
				$rebuilt .= $parts['user'];
				if(isset($parts['pass'])) $rebuilt .= ':' . $parts['pass'];
				$rebuilt .= '@';
			}
			$rebuilt .= $parts['host'];
			if(isset($parts['port'])) $rebuilt .= ':' . $parts['port'];
		}

		$rebuilt .= $newPath;
		if(isset($parts['query'])) $rebuilt .= '?' . $parts['query'];
		if(isset($parts['fragment'])) $rebuilt .= '#' . $parts['fragment'];

		return $rebuilt;
	}

	protected function updateTextReferences(array $references, array $replacementMap, array &$updated) {
		$pages = $this->wire()->pages;
		$fields = $this->wire()->fields;

		foreach($references as $reference) {
			$page = $pages->get((int) $reference['page_id']);
			$field = $fields->get((string) $reference['field']);
			if(!$page || !$page->id || !$field || !$field->id || !$page->template->fieldgroup->hasField($field)) {
				throw new WireException('A matched text-field reference changed before it could be updated.');
			}
			if(!$this->canEditPageField($page, $field->name)) {
				throw new WirePermissionException('Your permission to update a matched text field changed before the rename completed.');
			}

			$outputFormatting = $page->of();
			if($outputFormatting) $page->of(false);
			try {
				$value = $page->getUnformatted($field->name);
				if(!is_string($value)) continue;

				$newValue = strtr($value, $replacementMap);
				if($newValue === $value) continue;

				$page->set($field->name, $newValue);
				if(!$page->save($field->name)) {
					throw new WireException('A matched text field could not be saved.');
				}
				$updated[] = array(
					'page_id' => (int) $page->id,
					'field' => (string) $field->name
				);
			} finally {
				if($page->of() !== $outputFormatting) $page->of($outputFormatting);
			}
		}

		return $updated;
	}

	protected function renderReferenceSummary(array $referenceReport) {
		$sanitizer = $this->wire()->sanitizer;
		$text = $referenceReport['text'];
		$templates = $referenceReport['templates'];
		$out = '<p class="uk-text-meta uk-text-small pfr-scan-scope">Supported scan scope: text fields and PHP, INC, HTML, HTM, Twig, Latte, and TPL files up to 2 MB.</p>';

		if($this->hasBlockingReferenceConditions($referenceReport)) {
			$out .= '<div class="uk-alert uk-alert-danger uk-margin-small"><strong>Rename blocked.</strong> ' . $sanitizer->entities($this->getBlockingReferenceMessage($referenceReport)) . '</div>';
		}

		if(!count($text) && !count($templates) && $this->hasBlockingReferenceConditions($referenceReport)) {
			return $out . '<span class="uk-label uk-label-danger">Scan coverage is incomplete</span>';
		}

		if(!count($text) && !count($templates)) {
			return $out . '<span class="uk-label uk-label-success">No matches in the supported scan scope</span><div class="uk-text-meta pfr-scan-clean-meta">API-generated asset URLs continue to follow the renamed asset.</div>';
		}

		if(count($text)) {
			$out .= '<div><span class="uk-label uk-label-warning">Text URLs: ' . count($text) . '</span></div>';
			$out .= '<ul class="uk-list uk-list-divider uk-text-small uk-margin-small-top">';
			foreach(array_slice($text, 0, 8) as $item) {
				$out .= '<li>Page ' . $sanitizer->entities($item['page_title']) . ' #' . (int) $item['page_id'] . ', field <code>' . $sanitizer->entities($item['field']) . '</code> <a href="' . $sanitizer->entities($item['edit_url']) . '">Open editor</a><br><span class="uk-text-meta uk-text-break">' . $sanitizer->entities($item['needle']) . '</span></li>';
			}
			if(count($text) > 8) $out .= '<li class="uk-text-muted">More references omitted...</li>';
			$out .= '</ul>';
		}

		if(count($templates)) {
			$out .= '<div class="uk-margin-small-top"><span class="uk-label uk-label-danger">Template files: ' . count($templates) . '</span></div>';
			$out .= '<ul class="uk-list uk-list-divider uk-text-small uk-margin-small-top">';
			foreach(array_slice($templates, 0, 8) as $item) {
				$out .= '<li><code>' . $sanitizer->entities($item['file']) . '</code><br><span class="uk-text-meta uk-text-break">' . $sanitizer->entities($item['needle']) . '</span></li>';
			}
			if(count($templates) > 8) $out .= '<li class="uk-text-muted">More references omitted...</li>';
			$out .= '</ul>';
		}

		return $out;
	}

	protected function renderNotice($text) {
		$sanitizer = $this->wire()->sanitizer;
		return '<div class="uk-alert uk-alert-primary">' . $sanitizer->entities($text) . '</div>';
	}

}
