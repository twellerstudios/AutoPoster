/* BEAZER - menu, search, video lightbox. No dependencies. */
(function () {
	'use strict';

	var doc = document.documentElement;
	var body = document.body;
	var isStatic = doc.hasAttribute('data-static');

	// Mobile menu
	var menuBtn = document.querySelector('.tool-menu');
	if (menuBtn) {
		menuBtn.addEventListener('click', function () {
			var open = body.classList.toggle('menu-open');
			menuBtn.setAttribute('aria-expanded', String(open));
		});
	}

	// Search panel
	var searchBtn = document.querySelector('.tool-search');
	var panel = document.getElementById('search-panel');
	function closeSearch() {
		if (panel && !panel.hidden) {
			panel.hidden = true;
			searchBtn.setAttribute('aria-expanded', 'false');
		}
	}
	if (searchBtn && panel) {
		searchBtn.addEventListener('click', function () {
			panel.hidden = !panel.hidden;
			searchBtn.setAttribute('aria-expanded', String(!panel.hidden));
			if (!panel.hidden) {
				var input = panel.querySelector('input[type="search"]');
				if (input) input.focus();
			}
		});
	}
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') return;
		closeSearch();
		if (body.classList.contains('menu-open')) {
			body.classList.remove('menu-open');
			menuBtn.setAttribute('aria-expanded', 'false');
			menuBtn.focus();
		}
	});

	// Featured video lightbox (YouTube loads only after the visitor presses play)
	document.querySelectorAll('.video-play[data-video]').forEach(function (btn) {
		var dialog = btn.closest('section, article, .inner-content, body').querySelector('.video-dialog');
		if (!dialog || typeof dialog.showModal !== 'function') {
			btn.addEventListener('click', function () {
				window.open('https://www.youtube.com/watch?v=' + btn.dataset.video, '_blank', 'noopener');
			});
			return;
		}
		var embed = dialog.querySelector('.video-embed');
		btn.addEventListener('click', function () {
			var iframe = document.createElement('iframe');
			iframe.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(btn.dataset.video) + '?autoplay=1&rel=0';
			iframe.title = btn.getAttribute('aria-label') || 'Video';
			iframe.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
			iframe.allowFullscreen = true;
			embed.replaceChildren(iframe);
			dialog.showModal();
		});
		dialog.querySelector('.video-close').addEventListener('click', function () { dialog.close(); });
		dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });
		dialog.addEventListener('close', function () { embed.replaceChildren(); btn.focus(); });
	});

	// Static staging preview: forms and search need the live WordPress site.
	if (isStatic) {
		document.querySelectorAll('form').forEach(function (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var note = form.querySelector('.form-status') || document.createElement('p');
				note.className = 'form-status';
				note.setAttribute('role', 'status');
				note.textContent = form.classList.contains('search-form')
					? 'Search is available on the live site.'
					: 'Preview only: this form goes live when the site launches on WordPress.';
				form.prepend(note);
			});
		});
	}
})();
