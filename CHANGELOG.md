# Changelog

All notable changes to ProcessFileRenamer are documented here.

## 1.3.1

* Reload the owning page without memory caches after saving and verify that the new basename is persisted and its source file exists before logging completion.
* Roll back the physical rename when the owning-field persistence check fails.
* Document that synchronized or deployed asset renames require the corresponding database change or a reconciliation migration in every environment.

## 1.3.0

* Added a live page-ID preview that shows the page title, ID, and whether it has assets available to rename.
* Hid empty and non-editable asset fields, and added a clear page-level warning when no assets are available.
* Kept the workflow indicator synchronized as the user scans references and proceeds to rename.
* Switched text-field discovery to ProcessWire's lazy-aware `Fields::findByType()` API.
* Moved module metadata to `ProcessFileRenamer.info.php` so ProcessWire can inspect version requirements before loading the module class.

## 1.2.0

* Raised the ProcessWire minimum to 3.0.262 because the module uses `Page::getUnformatted()`.
* Enforced page view and field edit checks on render, scan, rename, and each text-reference write.
* Made incomplete scans fail closed: text-field overflow, large/unreadable templates, and inaccessible references block the rename.
* Moved optional variation deletion after successful rename and reference updates, added rollback for an unsaved owner field, and logged partial failures.
* Moved JavaScript out of inline markup, added live scan status, keyboard focus handling, accessible asset previews, and theme-aware responsive styles.
* Updated user-facing file/image wording to the accurate umbrella term **asset**.

## 1.1.0

* Refactored asset scanning into an asynchronous, on-demand engine to fix performance issues on large databases.
* Replaced deep, nested loop analysis on initial render with a framework-free Vanilla JS background script via the native `fetch` API.
* Implemented an internal endpoint hook (`___executeScan()`) mapping unique elements safely using native `Pagefile` token hashes.
* Optimized database operations inside `scanTextReferences()` using ProcessWire's pipe operator selector (`|`), grouping individual string matches into single multi-match calls.
* Enforced defensive client-side blocking that disables the submit action button until the async workflow resolves completely with a `200 OK` structure.

## 1.0.9

* Added a clear white editable state and stronger focus highlight to the New filename base input.
* Removed the redundant File section heading from the left file information panel.
* Kept the rest of the per-file layout consistent with the white panel treatment from 1.0.8.

## 1.0.8

* Removed the repeated recommendation bullet list from the top intro card.
* Made the per-file card layout visually consistent by giving File, Reference scan, and Rename sections the same white panel treatment.
* Changed rename option groups to white backgrounds so controls no longer alternate between gray and white sections.
* Increased action button size for Find files, Open page editor, Open file, and Rename file.
* Kept styling in `ProcessFileRenamer.css` and continued using AdminThemeUikit/UIkit classes and ProcessWire design-system tokens.

## 1.0.7

* Reworked the file list from a compressed table row into per-file cards.
* Gave the rename panel full-width responsive space instead of forcing all controls into a narrow table column.
* Restyled radio choices as large clickable option cards with fixed-size radio controls, so they no longer appear squished in AdminThemeUikit.
* Kept styling in `ProcessFileRenamer.css` and continued using AdminThemeUikit/UIkit classes and ProcessWire design-system tokens.

## 1.0.6

* Fixed the runtime error caused by calling `$page->fieldEditable($fieldName)` directly in contexts where that method is not callable.
* Replaced the direct call with a helper that uses `$page->editable($fieldName)`, the public field-aware editability check documented through ProcessWire's PagePermissions editable hook.
* PHP lint passes.

## 1.0.5

* Moved admin styling into `ProcessFileRenamer.css` instead of returning inline CSS from the Process screen.
* Replaced the remaining template-warning checkbox with explicit radio choices.
* Added field-level editability checks before rendering rename controls and before processing POST.
* Kept the safer defaults from 1.0.4: update detected text URLs when needed, keep existing variations, and block rename until template warnings are explicitly reviewed.

## 1.0.4

* Improved admin UX with AdminThemeUikit/UIkit-style cards, alerts, tables, labels, buttons, and form controls.
* Replaced unclear text/variation checkboxes with explicit radio choices.
* Clarified that deleting image variations does not recreate them during rename.
* Kept **Keep existing variations** as the default and recommended image behavior.
* Updated warning copy for hardcoded text URLs, template-file references, and variation deletion.

## 1.0.3

* Removed `permissions` from the module metadata so ProcessWire does not treat `file-renamer` as unconditionally module-owned.
* Kept explicit `___install()` permission creation and guarded `___uninstall()` permission cleanup.
* Preserved `permission` metadata so the Process screen still requires `file-renamer` for execution.

## 1.0.2

* Added explicit `___install()` and `___uninstall()` methods.
* Install now preserves ProcessWire's Process-page auto-install behavior by calling `parent::___install()`.
* Uninstall now preserves ProcessWire's Process-page auto-uninstall behavior by calling `parent::___uninstall()`.
* Added guarded permission cleanup for the module-created `file-renamer` permission.

## 1.0.1

* Added scan support for existing image variation URLs and associated extra-file URLs when available.
* Added replacement mapping for hardcoded original, variation, and associated extra-file URLs in text fields.
* Changed **Remove image variations before rename** to unchecked by default.
* Blocks variation deletion when hardcoded variation URL references are found.

## 1.0.0

* Initial one-file-at-a-time admin renamer.
