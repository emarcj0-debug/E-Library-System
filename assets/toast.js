/* global window, document */
(function () {
	'use strict';

	function ensureContainer() {
		var c = document.querySelector('.toast-container');
		if (c) return c;
		c = document.createElement('div');
		c.className = 'toast-container';
		document.body.appendChild(c);
		return c;
	}

	function toTitle(type) {
		switch (type) {
			case 'success': return 'Success';
			case 'error': return 'Error';
			case 'warning': return 'Warning';
			default: return 'Info';
		}
	}

	function toast(message, opts) {
		opts = opts || {};
		var type = opts.type || 'info';
		var title = opts.title || toTitle(type);
		var duration = typeof opts.duration === 'number' ? opts.duration : 3500;
		var redirect = opts.redirect || null;
		var allowClose = opts.allowClose !== false;

		var container = ensureContainer();

		var el = document.createElement('div');
		el.className = 'toast toast--' + type;
		el.setAttribute('role', 'status');
		el.setAttribute('aria-live', 'polite');

		var bar = document.createElement('div');
		bar.className = 'toast__bar';

		var body = document.createElement('div');
		body.className = 'toast__body';
		var h = document.createElement('p');
		h.className = 'toast__title';
		h.textContent = title;
		var p = document.createElement('p');
		p.className = 'toast__msg';
		p.textContent = String(message || '');
		body.appendChild(h);
		body.appendChild(p);

		var close = document.createElement('button');
		close.className = 'toast__close';
		close.type = 'button';
		close.innerHTML = '&times;';
		close.setAttribute('aria-label', 'Close');
		if (!allowClose) close.style.display = 'none';

		var progress = document.createElement('div');
		progress.className = 'toast__progress';
		var progInner = document.createElement('div');
		progress.appendChild(progInner);

		el.appendChild(bar);
		el.appendChild(body);
		el.appendChild(close);
		el.appendChild(progress);
		container.appendChild(el);

		var closed = false;
		function removeNow() {
			if (closed) return;
			closed = true;
			el.style.animation = 'toast-out 160ms ease-in forwards';
			window.setTimeout(function () {
				if (el && el.parentNode) el.parentNode.removeChild(el);
				if (redirect) window.location.href = redirect;
			}, 170);
		}

		close.addEventListener('click', removeNow);

		// progress animation
		if (duration > 0) {
			progInner.animate([
				{ transform: 'scaleX(1)' },
				{ transform: 'scaleX(0)' }
			], { duration: duration, easing: 'linear', fill: 'forwards' });

			window.setTimeout(removeNow, duration);
		}

		return { close: removeNow, el: el };
	}

	// Simple, accessible confirm modal
	function ensureConfirmModal() {
		var overlay = document.getElementById('toastConfirmOverlay');
		if (overlay) return overlay;

		overlay = document.createElement('div');
		overlay.id = 'toastConfirmOverlay';
		overlay.className = 'toast-modal-overlay';
		overlay.innerHTML =
			'<div class="toast-modal" role="dialog" aria-modal="true" aria-labelledby="toastConfirmTitle">' +
			'  <div class="toast-modal__header">' +
			'    <p class="toast-modal__title" id="toastConfirmTitle">Confirm</p>' +
			'  </div>' +
			'  <div class="toast-modal__body" id="toastConfirmMessage"></div>' +
			'  <div class="toast-modal__footer">' +
			'    <button type="button" class="toast-btn toast-btn--ghost" id="toastConfirmCancel">Cancel</button>' +
			'    <button type="button" class="toast-btn toast-btn--primary" id="toastConfirmOk">OK</button>' +
			'  </div>' +
			'</div>';

		document.body.appendChild(overlay);
		return overlay;
	}

	function confirmModal(message, opts) {
		opts = opts || {};
		var title = opts.title || 'Confirm';
		var okText = opts.okText || 'OK';
		var cancelText = opts.cancelText || 'Cancel';
		var danger = !!opts.danger;

		var overlay = ensureConfirmModal();
		var modal = overlay.querySelector('.toast-modal');
		var titleEl = overlay.querySelector('#toastConfirmTitle');
		var msgEl = overlay.querySelector('#toastConfirmMessage');
		var okBtn = overlay.querySelector('#toastConfirmOk');
		var cancelBtn = overlay.querySelector('#toastConfirmCancel');

		titleEl.textContent = title;
		msgEl.textContent = String(message || '');
		okBtn.textContent = okText;
		cancelBtn.textContent = cancelText;
		okBtn.className = 'toast-btn ' + (danger ? 'toast-btn--danger' : 'toast-btn--primary');

		function open() {
			overlay.classList.add('is-open');
			// delay focus until animation begins
			window.setTimeout(function () {
				okBtn.focus();
			}, 50);
		}

		function close() {
			overlay.classList.remove('is-open');
		}

		return new Promise(function (resolve) {
			function cleanup(result) {
				okBtn.removeEventListener('click', onOk);
				cancelBtn.removeEventListener('click', onCancel);
				overlay.removeEventListener('click', onOverlay);
				document.removeEventListener('keydown', onKey);
				close();
				resolve(result);
			}

			function onOk() { cleanup(true); }
			function onCancel() { cleanup(false); }
			function onOverlay(e) { if (e.target === overlay) cleanup(false); }
			function onKey(e) { if (e.key === 'Escape') cleanup(false); }

			okBtn.addEventListener('click', onOk);
			cancelBtn.addEventListener('click', onCancel);
			overlay.addEventListener('click', onOverlay);
			document.addEventListener('keydown', onKey);

			open();
		});
	}

	function init() {
		// Override native blocking dialogs
		window.alert = function (msg) {
			toast(msg, { type: 'info', title: 'Notice' });
		};

		window.confirm = function (msg) {
			// Non-blocking: return false and expose a helper for forms/buttons.
			toast('This action needs confirmation (dialog shown).', { type: 'info', duration: 1500 });
			confirmModal(msg, { title: 'Confirm' }).then(function () { /* noop */ });
			return false;
		};

		// Data-driven auto hookup for forms/buttons/links
		document.addEventListener('click', function (e) {
			var el = e.target;
			// Walk up to closest actionable element
			while (el && el !== document.body) {
				if (el.hasAttribute && el.hasAttribute('data-confirm')) break;
				el = el.parentNode;
			}
			if (!el || el === document.body || !el.getAttribute) return;

			var msg = el.getAttribute('data-confirm');
			if (!msg) return;

			// Anchor
			if (el.tagName === 'A' && el.href) {
				e.preventDefault();
				var href = el.href;
				confirmModal(msg, {
					title: el.getAttribute('data-confirm-title') || 'Confirm',
					okText: el.getAttribute('data-confirm-ok') || 'Yes',
					cancelText: el.getAttribute('data-confirm-cancel') || 'No',
					danger: el.getAttribute('data-confirm-danger') === '1'
				}).then(function (ok) {
					if (ok) window.location.href = href;
				});
				return;
			}

			// Button inside form OR submit input/button
			var form = el.form || (el.closest ? el.closest('form') : null);
			if (form) {
				e.preventDefault();
				confirmModal(msg, {
					title: el.getAttribute('data-confirm-title') || 'Confirm',
					okText: el.getAttribute('data-confirm-ok') || 'Yes',
					cancelText: el.getAttribute('data-confirm-cancel') || 'No',
					danger: el.getAttribute('data-confirm-danger') === '1'
				}).then(function (ok) {
					if (ok) form.submit();
				});
			}
		});

		// Convert onsubmit="return confirm(...)" into data-confirm at runtime (best-effort)
		document.addEventListener('submit', function (e) {
			var form = e.target;
			if (!form || !form.getAttribute) return;
			var ons = form.getAttribute('onsubmit') || '';
			if (!/confirm\(/i.test(ons)) return;
			// Cancel native submit and show dialog
			e.preventDefault();
			var msgMatch = ons.match(/confirm\(['\"]([\s\S]*?)['\"]\)/i);
			var msg = msgMatch ? msgMatch[1] : 'Are you sure?';
			confirmModal(msg, { title: 'Confirm' }).then(function (ok) {
				if (ok) form.submit();
			});
		});
	}

	window.ELibraryToast = {
		toast: toast,
		confirm: confirmModal,
		init: init
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
