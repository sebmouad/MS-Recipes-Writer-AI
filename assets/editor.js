/**
 * The judge's verdict, in the editor people actually use.
 *
 * A classic meta box still exists for the classic editor, but the block editor
 * folds those away behind a collapsed drawer — present in the page and
 * `display:none` until somebody thinks to open it. So an editor opening a
 * generated draft never met the objections this plugin exists to put in front
 * of them. The same verdict is registered twice here: in the post sidebar,
 * where it can always be found, and in the pre-publish check, which is the
 * moment the question is being asked.
 *
 * No build step: this is the same plain script the rest of assets/ is, reading
 * the globals WordPress already provides.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.plugins || !wp.element || typeof MSRWA_VERDICT === 'undefined') { return; }

	var data = MSRWA_VERDICT;
	var text = data.text || {};
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;

	// PluginDocumentSettingPanel and PluginPrePublishPanel moved from wp.editPost
	// to wp.editor; both are exported from wp.editPost on older releases.
	var editor = wp.editor || {};
	var editPost = wp.editPost || {};
	var DocumentPanel = editor.PluginDocumentSettingPanel || editPost.PluginDocumentSettingPanel;
	var PrePublishPanel = editor.PluginPrePublishPanel || editPost.PluginPrePublishPanel;
	if (!DocumentPanel && !PrePublishPanel) { return; }

	function headline() {
		if (!data.judged) { return { status: 'info', message: text.unjudged }; }
		if (data.blocking) { return { status: 'error', message: text.blocking }; }
		if (data.findings.length) { return { status: 'warning', message: text.minor }; }
		return { status: 'success', message: text.clean };
	}

	function finding(item, index) {
		var lines = [
			el('strong', null, item.blocking ? text.blockingLabel : text.minorLabel),
			' — ' + item.target + (item.reason ? ' : ' + item.reason : '')
		];
		if (item.quote) { lines.push(el('em', { key: 'q' }, ' « ' + item.quote + ' »')); }
		if (item.fix) { lines.push(el('span', { key: 'f' }, ' ' + text.fix + ' ' + item.fix)); }
		return el('li', { key: 'f' + index }, lines);
	}

	function body() {
		var note = headline();
		var children = [
			el('p', { key: 'lead', className: 'msrwa-verdict-lead' }, text.lead),
			el(wp.components.Notice, { key: 'note', status: note.status, isDismissible: false }, note.message)
		];

		if (data.findings.length) {
			children.push(el('ul', { key: 'list', className: 'msrwa-verdict-list' }, data.findings.map(finding)));
		}
		data.uncertainties.forEach(function (uncertainty, index) {
			children.push(el('p', { key: 'u' + index, className: 'msrwa-verdict-muted' }, text.uncertain + ' ' + uncertainty));
		});

		var seo = Object.keys(data.seo || {});
		if (seo.length) {
			children.push(el('details', { key: 'seo', className: 'msrwa-verdict-seo' }, [
				el('summary', { key: 's' }, text.seo),
				el('dl', { key: 'd' }, seo.map(function (label, index) {
					return el(Fragment, { key: 'r' + index }, [
						el('dt', { key: 't' }, label),
						el('dd', { key: 'v' }, data.seo[label])
					]);
				}))
			]));
		}

		if (data.runUrl) {
			children.push(el('p', { key: 'run' }, el('a', { href: data.runUrl }, text.run)));
		}

		return el('div', { className: 'msrwa-verdict' }, children);
	}

	wp.plugins.registerPlugin('msrwa-verdict', {
		render: function () {
			var panels = [];
			if (DocumentPanel) {
				panels.push(el(DocumentPanel, {
					key: 'document',
					name: 'msrwa-verdict',
					title: text.title,
					className: 'msrwa-verdict-panel'
				}, body()));
			}
			// Opened by default only when something blocks publication: an
			// editor clicking Publish on a clean article should not have to
			// close a panel to reach the button.
			if (PrePublishPanel) {
				panels.push(el(PrePublishPanel, {
					key: 'prepublish',
					title: text.title,
					initialOpen: !!data.blocking
				}, body()));
			}
			return el(Fragment, null, panels);
		}
	});
}(window.wp));
