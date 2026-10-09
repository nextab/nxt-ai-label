(function () {
	document.addEventListener('change', function (event) {
		var target = event.target;
		if (!target || target.id !== 'nxt-ai-label-select-all') {
			return;
		}
		document.querySelectorAll('.nxt-ai-label-pending').forEach(function (box) {
			box.checked = target.checked;
		});
	});

	bootTools();

	if (!window.nxtAiLabel) {
		return;
	}

	var cfg = window.nxtAiLabel;

	function bootTools() {
		var tools = window.nxtAiLabelTools;
		if (!tools) {
			return;
		}
		var box = document.getElementById('nxt-ai-label-job');
		var text = document.getElementById('nxt-ai-label-job-text');
		var bar = document.querySelector('.nxt-ai-label-job__bar');
		var track = document.querySelector('.nxt-ai-label-job__track');
		var scanButton = document.getElementById('nxt-ai-label-scan-start');
		var applyButton = document.getElementById('nxt-ai-label-apply-all');
		var scanForm = document.getElementById('nxt-ai-label-scan');
		if (!box || !scanButton && !applyButton) {
			return;
		}

		function fill(template, values) {
			var line = String(template || '');
			values.forEach(function (value, index) {
				line = line.replace('%' + (index + 1) + '$d', String(value));
			});
			return line;
		}

		function setBusy(on) {
			if (scanButton) {
				scanButton.disabled = on;
			}
			if (applyButton) {
				applyButton.disabled = on;
			}
		}

		function setProgress(percent, message) {
			var width = Math.max(0, Math.min(100, percent));
			box.hidden = false;
			if (text) {
				text.textContent = message;
			}
			if (bar) {
				bar.style.width = width + '%';
			}
			if (track) {
				track.setAttribute('aria-valuenow', String(Math.round(width)));
			}
			if (box.scrollIntoView) {
				box.scrollIntoView({ block: 'nearest' });
			}
		}

		function finish(notice, done, checked, skipped) {
			var url = new URL(tools.pageUrl, window.location.href);
			url.searchParams.set('nxt_ai_label_notice', notice);
			url.searchParams.set('nxt_ai_label_done', String(done));
			url.searchParams.set('nxt_ai_label_checked', String(checked));
			url.searchParams.set('nxt_ai_label_skipped', String(skipped));
			window.location.assign(url.toString());
		}

		function run(job) {
			var after = 0;
			var total = 0;
			var primary = 0;
			var secondary = 0;
			setBusy(true);
			setProgress(0, job.startText);

			function step() {
				var body = new FormData();
				body.append('action', job.action);
				body.append('nonce', job.nonce);
				body.append('after', String(after));
				body.append('total', String(total));
				if (job.rescan) {
					body.append('rescan', '1');
				}
				fetch(tools.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body,
				}).then(function (response) {
					return response.json();
				}).then(function (payload) {
					if (!payload || !payload.success) {
						setBusy(false);
						setProgress(0, (payload && payload.data && payload.data.message) || tools.i18n.failed);
						return;
					}
					var data = payload.data || {};
					if (data.total) {
						total = data.total;
					}
					primary += data[job.primary] || 0;
					secondary += data[job.secondary] || 0;
					var progressed = job.notice === 'applied' ? primary + secondary : primary;
					var percent = total > 0 ? (progressed / total) * 100 : 0;
					if (data.done) {
						percent = 100;
					}
					setProgress(percent, fill(job.template, [primary, total, secondary]));
					if (data.done || data.after <= after) {
						finish(job.notice, job.notice === 'applied' ? primary : secondary, job.notice === 'detected' ? primary : 0, job.notice === 'applied' ? secondary : 0);
						return;
					}
					after = data.after;
					step();
				}).catch(function () {
					setBusy(false);
					setProgress(percentSafe(), tools.i18n.failed);
				});
			}

			function percentSafe() {
				var progressed = job.notice === 'applied' ? primary + secondary : primary;
				return total > 0 ? (progressed / total) * 100 : 0;
			}

			step();
		}

		if (scanForm) {
			scanForm.addEventListener('submit', function (event) {
				event.preventDefault();
			});
		}
		if (scanButton) {
			scanButton.addEventListener('click', function () {
				var rescan = document.getElementById('nxt-ai-label-rescan');
				run({
					action: 'nxt_ai_label_scan_batch',
					nonce: tools.scanNonce,
					rescan: !!(rescan && rescan.checked),
					startText: tools.i18n.scanStart,
					template: tools.i18n.scanProgress,
					primary: 'checked',
					secondary: 'found',
					notice: 'detected',
				});
			});
		}
		if (applyButton) {
			applyButton.addEventListener('click', function () {
				run({
					action: 'nxt_ai_label_apply_batch',
					nonce: tools.applyNonce,
					rescan: false,
					startText: tools.i18n.applyStart,
					template: tools.i18n.applyProgress,
					primary: 'processed',
					secondary: 'skipped',
					notice: 'applied',
				});
			});
		}
	}

	function findImage() {
		var modal = document.querySelector('.media-modal');
		var root = modal || document;
		return root.querySelector('.attachment-media-view img, .wp_attachment_holder img, .wp_attachment_image img');
	}

	function overlayHost() {
		return document.querySelector('.media-modal') || document.body;
	}

	function showOverlay(text) {
		hideOverlay();
		var host = overlayHost();
		var el = document.createElement('div');
		el.className = 'nxt-ai-label-overlay';
		if (host === document.body) {
			el.classList.add('nxt-ai-label-overlay--screen');
		}
		var box = document.createElement('div');
		box.className = 'nxt-ai-label-overlay__box';
		var spin = document.createElement('span');
		spin.className = 'nxt-ai-label-overlay__spin';
		var msg = document.createElement('p');
		msg.textContent = text;
		box.appendChild(spin);
		box.appendChild(msg);
		el.appendChild(box);
		host.appendChild(el);
	}

	function hideOverlay() {
		document.querySelectorAll('.nxt-ai-label-overlay').forEach(function (node) {
			node.remove();
		});
	}

	function clearBadge() {
		document.querySelectorAll('.nxt-ai-label-badge').forEach(function (node) {
			node.remove();
		});
	}

	function readPanel(panel) {
		var slug = panel.querySelector('[data-field="slug"]');
		var position = panel.querySelector('[data-field="position"]');
		var scale = panel.querySelector('[data-field="scale"]');
		return {
			slug: slug ? slug.value : '',
			position: position ? position.value : 'bottom-right',
			scale: scale ? scale.value : 'medium',
		};
	}

	function drawPreview(panel) {
		clearBadge();
		if (panel.dataset.preview !== '1') {
			return;
		}
		var img = findImage();
		var choice = readPanel(panel);
		var label = cfg.labels[choice.slug];
		var targetH = cfg.heights[choice.scale] || 40;
		if (!img || !label || !img.clientWidth) {
			return;
		}

		var fullW = parseInt(panel.dataset.fullWidth, 10) || img.naturalWidth || img.clientWidth;
		var fullH = parseInt(panel.dataset.fullHeight, 10) || img.naturalHeight || img.clientHeight;
		if (!fullW || !fullH) {
			return;
		}

		var pad = Math.max(4, Math.round(Math.min(fullW, fullH) * 0.03));
		var maxW = Math.max(1, fullW - (2 * pad));
		var maxH = Math.max(1, fullH - (2 * pad));
		var labelH = targetH;
		var labelW = labelH * (label.width / label.height);
		if (labelW > maxW) {
			labelW = maxW;
			labelH = labelW * (label.height / label.width);
		}
		if (labelH > maxH) {
			labelH = maxH;
			labelW = labelH * (label.width / label.height);
		}

		var viewScale = img.clientWidth / fullW;
		var cssW = labelW * viewScale;
		var cssH = labelH * viewScale;
		var cssPad = pad * viewScale;
		var left = cssPad;
		var top = cssPad;
		if (choice.position === 'top-right' || choice.position === 'bottom-right') {
			left = img.clientWidth - cssW - cssPad;
		}
		if (choice.position === 'bottom-left' || choice.position === 'bottom-right') {
			top = img.clientHeight - cssH - cssPad;
		}

		var parent = img.parentElement;
		if (!parent) {
			return;
		}
		if (window.getComputedStyle(parent).position === 'static') {
			parent.style.position = 'relative';
		}

		var badge = document.createElement('img');
		badge.className = 'nxt-ai-label-badge';
		badge.alt = '';
		badge.src = label.url;
		badge.style.left = (img.offsetLeft + Math.max(0, left)) + 'px';
		badge.style.top = (img.offsetTop + Math.max(0, top)) + 'px';
		badge.style.width = Math.max(1, cssW) + 'px';
		badge.style.height = Math.max(1, cssH) + 'px';
		parent.appendChild(badge);
	}

	function setStatus(panel, state, message) {
		if (state !== 'set' && state !== 'off' && state !== 'none' && state !== 'suggest') {
			state = 'none';
		}
		panel.dataset.state = state;
		panel.dataset.enabled = state === 'set' ? '1' : '0';
		if (state === 'set' || state === 'off') {
			panel.dataset.preview = '0';
		}
		var status = panel.querySelector('.nxt-ai-label__status');
		if (status) {
			status.className = 'nxt-ai-label__status nxt-ai-label__status--' + state;
			status.textContent = message || '';
		}
		var note = panel.querySelector('.nxt-ai-label__preview');
		if (note) {
			note.hidden = panel.dataset.preview !== '1';
		}
		var applyBtn = panel.querySelector('[data-action="apply"]');
		if (applyBtn) {
			var i18n = cfg.i18n || {};
			applyBtn.textContent = state === 'set' ? (i18n.update || 'Update label') : (i18n.set || 'Set label');
		}
	}

	function setMessage(panel, message) {
		var msg = panel.querySelector('.nxt-ai-label__msg');
		if (msg) {
			msg.textContent = message || '';
		}
	}

	function patchCompatItem(html, panel) {
		var marker = 'class="nxt-ai-label';
		var start = html.indexOf(marker);
		if (start === -1) {
			return html;
		}
		var open = html.lastIndexOf('<div', start);
		if (open === -1) {
			return html;
		}
		var depth = 0;
		var end = -1;
		var re = /<\/?div\b[^>]*>/gi;
		var match;
		re.lastIndex = open;
		while ((match = re.exec(html))) {
			if (match[0].charAt(1) === '/') {
				depth -= 1;
				if (depth === 0) {
					end = match.index + match[0].length;
					break;
				}
			} else {
				depth += 1;
			}
		}
		if (end === -1) {
			return html;
		}
		var wrap = document.createElement('div');
		wrap.appendChild(panel.cloneNode(true));
		return html.slice(0, open) + wrap.innerHTML + html.slice(end);
	}

	function rememberPanel(panel) {
		if (!window.wp || !wp.media || typeof wp.media.attachment !== 'function') {
			return;
		}
		var id = parseInt(panel.dataset.id, 10);
		if (!id) {
			return;
		}
		var model = wp.media.attachment(id);
		if (!model || typeof model.get !== 'function' || typeof model.set !== 'function') {
			return;
		}
		var compat = model.get('compat');
		if (compat && typeof compat.item === 'string') {
			var next = patchCompatItem(compat.item, panel);
			if (next !== compat.item) {
				model.set('compat', {
					item: next,
					meta: compat.meta || ''
				});
			}
		}
		var detected = panel.dataset.detected;
		model.set('nxtAiLabel', {
			enabled: panel.dataset.state === 'set',
			detected: detected === 'generated' || detected === 'modified' ? detected : ''
		});
	}

	function refreshImage(url) {
		var img = findImage();
		if (!img) {
			return;
		}
		var next = url || img.currentSrc || img.src;
		var parsed = new URL(next, window.location.href);
		parsed.searchParams.set('nxt', String(Date.now()));
		img.src = parsed.toString();
	}

	function apply(panel, mode) {
		var choice = readPanel(panel);
		var buttons = panel.querySelectorAll('button');
		buttons.forEach(function (button) {
			button.disabled = true;
		});
		panel.dataset.applying = '1';
		var i18n = cfg.i18n || {};
		showOverlay(mode === 'remove' ? (i18n.removing || 'Removing label') : (i18n.writing || 'Writing label'));

		var body = new FormData();
		body.append('action', 'nxt_ai_label_apply');
		body.append('nonce', cfg.nonce);
		body.append('attachment_id', panel.dataset.id || '');
		body.append('mode', mode);
		body.append('slug', choice.slug);
		body.append('position', choice.position);
		body.append('scale', choice.scale);

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success) {
				var err = payload && payload.data && payload.data.message ? payload.data.message : ((cfg.i18n && cfg.i18n.failed) || 'Failed.');
				setMessage(panel, err);
				return;
			}
			setMessage(panel, '');
			setStatus(panel, payload.data.state || (payload.data.enabled ? 'set' : 'off'), payload.data.status || '');
			clearBadge();
			rememberPanel(panel);
			refreshImage(payload.data.url || '');
		}).catch(function () {
			setMessage(panel, (cfg.i18n && cfg.i18n.requestFailed) || 'Request failed.');
		}).finally(function () {
			hideOverlay();
			panel.dataset.applying = '0';
			buttons.forEach(function (button) {
				button.disabled = false;
			});
		});
	}

	function maybeDetect(panel) {
		if (panel.dataset.state === 'set' || panel.dataset.state === 'off') {
			return;
		}
		if (panel.dataset.detected === '0' || panel.dataset.detected === 'generated' || panel.dataset.detected === 'modified') {
			return;
		}
		if (panel.dataset.detecting === '1') {
			return;
		}
		panel.dataset.detecting = '1';
		var body = new FormData();
		body.append('action', 'nxt_ai_label_detect');
		body.append('nonce', cfg.nonce);
		body.append('attachment_id', panel.dataset.id || '');
		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success) {
				return;
			}
			if (panel.dataset.state === 'set' || panel.dataset.state === 'off' || panel.dataset.applying === '1') {
				return;
			}
			panel.dataset.detected = payload.data.detected ? payload.data.detected : '0';
			setStatus(panel, payload.data.state || 'none', payload.data.status || '');
			rememberPanel(panel);
		}).catch(function () {
			panel.dataset.detecting = '0';
		});
	}

	function decorateLibrary() {
		if (!window.wp || !wp.media || typeof wp.media.attachment !== 'function') {
			return;
		}
		var i18n = cfg.i18n || {};
		document.querySelectorAll('.attachment[data-id]').forEach(function (el) {
			var id = parseInt(el.getAttribute('data-id'), 10);
			if (!id) {
				return;
			}
			var model = wp.media.attachment(id);
			var info = model && model.get ? model.get('nxtAiLabel') : null;
			var flag = el.querySelector('.nxt-ai-label-flag');
			if (!info || (!info.enabled && !info.detected)) {
				if (flag) {
					flag.remove();
				}
				return;
			}
			var nextClass = info.enabled
				? 'nxt-ai-label-flag nxt-ai-label-flag--set'
				: 'nxt-ai-label-flag nxt-ai-label-flag--suggest';
			var nextText = info.enabled ? (i18n.flagSet || 'Label') : (i18n.flagAi || 'AI?');
			if (!flag) {
				flag = document.createElement('span');
				flag.className = nextClass;
				flag.textContent = nextText;
				el.appendChild(flag);
				return;
			}
			if (flag.className !== nextClass) {
				flag.className = nextClass;
			}
			if (flag.textContent !== nextText) {
				flag.textContent = nextText;
			}
		});
	}

	document.addEventListener('change', function (event) {
		var field = event.target.closest('.nxt-ai-label [data-field]');
		if (!field) {
			return;
		}
		var panel = field.closest('.nxt-ai-label');
		if (!panel) {
			return;
		}
		panel.dataset.preview = '1';
		var note = panel.querySelector('.nxt-ai-label__preview');
		if (note) {
			note.hidden = false;
		}
		var img = findImage();
		if (img && !img.complete) {
			img.addEventListener('load', function () {
				if (panel.dataset.preview === '1') {
					drawPreview(panel);
				}
			}, { once: true });
		}
		drawPreview(panel);
	});

	document.addEventListener('click', function (event) {
		var button = event.target.closest('.nxt-ai-label [data-action]');
		if (!button) {
			return;
		}
		event.preventDefault();
		var panel = button.closest('.nxt-ai-label');
		if (!panel) {
			return;
		}
		apply(panel, button.dataset.action === 'remove' ? 'remove' : 'apply');
	});

	function bootVisible() {
		var previewing = false;
		document.querySelectorAll('.nxt-ai-label').forEach(function (panel) {
			if (panel.dataset.preview === '1') {
				previewing = true;
			}
			maybeDetect(panel);
		});
		if (!previewing) {
			clearBadge();
		}
		decorateLibrary();
	}

	var timer = 0;
	var observer = new MutationObserver(function () {
		window.clearTimeout(timer);
		timer = window.setTimeout(bootVisible, 80);
	});
	observer.observe(document.body, { childList: true, subtree: true });
	bootVisible();
}());
