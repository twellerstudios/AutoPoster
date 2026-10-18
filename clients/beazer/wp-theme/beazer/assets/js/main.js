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

	// Platform links not supplied yet: say so instead of sending fans somewhere wrong.
	var toast;
	document.addEventListener('click', function (e) {
		var link = e.target.closest('[data-soon]');
		if (!link) return;
		e.preventDefault();
		if (!toast) {
			toast = document.createElement('div');
			toast.className = 'soon-toast';
			toast.setAttribute('role', 'status');
			document.body.appendChild(toast);
		}
		toast.innerHTML = '';
		toast.append('The official BEAZER ' + link.dataset.soon + ' profile is coming soon. ');
		var join = document.createElement('a');
		join.href = link.href;
		join.textContent = 'Join the fan club to hear first.';
		toast.append(join);
		toast.classList.add('is-on');
		clearTimeout(toast.timer);
		toast.timer = setTimeout(function () { toast.classList.remove('is-on'); }, 6000);
	});

	// Show dates: label past shows, count down to upcoming ones.
	document.querySelectorAll('[data-show-date]').forEach(function (el) {
		var show = new Date(el.dataset.showDate + 'T23:59:59');
		var days = Math.ceil((show - new Date()) / 864e5);
		if (days < 0) {
			el.textContent = 'Past show';
			el.classList.add('is-past');
		} else if (days === 0) {
			el.textContent = 'Tonight';
		} else {
			el.textContent = 'In ' + days + (days === 1 ? ' day' : ' days');
		}
	});

	// Photo gallery lightbox.
	var gallery = document.querySelectorAll('.bz-gallery-item');
	if (gallery.length && typeof HTMLDialogElement === 'function') {
		var box = document.createElement('dialog');
		box.className = 'lightbox';
		box.innerHTML = '<button class="video-close" type="button" aria-label="Close photo"><svg class="icon" aria-hidden="true"><use href="#i-close"></use></svg></button><img alt=""><p></p>';
		document.body.appendChild(box);
		gallery.forEach(function (item) {
			item.addEventListener('click', function (e) {
				e.preventDefault();
				var img = item.querySelector('img');
				box.querySelector('img').src = item.href;
				box.querySelector('img').alt = img ? img.alt : '';
				box.querySelector('p').textContent = item.dataset.caption || '';
				box.showModal();
			});
		});
		box.querySelector('.video-close').addEventListener('click', function () { box.close(); });
		box.addEventListener('click', function (e) { if (e.target === box) box.close(); });
	}

	// Static build: forms post to form.php, which redirects back with ?beazer=status.
	if (isStatic) {
		var messages = {
			joined: 'You are in. Welcome to the BEAZER fan club!',
			sent: 'Thanks. Your message has been sent.',
			error: 'Please check the form and try again.',
			mailfail: 'Sorry, your message could not be sent right now. Please try again later.'
		};
		var status = new URLSearchParams(location.search).get('beazer');
		var form = document.querySelector('.beazer-form');
		if (status && messages[status] && form) {
			var note = document.createElement('p');
			note.className = 'form-status form-status--' + status;
			note.setAttribute('role', 'status');
			note.textContent = messages[status];
			form.prepend(note);
		}
		document.querySelectorAll('.search-form').forEach(function (f) {
			f.addEventListener('submit', function (e) {
				e.preventDefault();
				if (!f.querySelector('.form-status')) {
					var n = document.createElement('p');
					n.className = 'form-status';
					n.textContent = 'Search is available on the full WordPress site.';
					f.after(n);
				}
			});
		});
	}
})();
