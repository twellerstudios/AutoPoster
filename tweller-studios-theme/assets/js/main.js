/**
 * Tweller Studios - Main JavaScript
 *
 * @package TwellerStudios
 */

(function () {
    'use strict';

    // ============================================================
    // Header scroll effect
    // ============================================================
    const header = document.getElementById('site-header');
    let lastScroll = 0;

    function handleHeaderScroll() {
        const scrollY = window.scrollY;
        if (scrollY > 50) {
            header.classList.add('tw-header--scrolled');
        } else {
            header.classList.remove('tw-header--scrolled');
        }
        lastScroll = scrollY;
    }

    window.addEventListener('scroll', handleHeaderScroll, { passive: true });
    handleHeaderScroll();

    // ============================================================
    // Mobile menu toggle
    // ============================================================
    const menuToggle = document.getElementById('menu-toggle');
    const siteNav = document.getElementById('site-nav');

    if (menuToggle && siteNav) {
        menuToggle.addEventListener('click', function () {
            const isOpen = siteNav.classList.toggle('tw-nav--open');
            menuToggle.classList.toggle('tw-menu-toggle--active');
            menuToggle.setAttribute('aria-expanded', isOpen);
            document.body.style.overflow = isOpen ? 'hidden' : '';
        });

        // Close menu when clicking a link
        siteNav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                siteNav.classList.remove('tw-nav--open');
                menuToggle.classList.remove('tw-menu-toggle--active');
                menuToggle.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = '';
            });
        });
    }

    // ============================================================
    // Scroll-triggered reveal animations
    // ============================================================
    const revealElements = document.querySelectorAll('.tw-reveal');

    if ('IntersectionObserver' in window) {
        const revealObserver = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('tw-reveal--visible');
                        revealObserver.unobserve(entry.target);
                    }
                });
            },
            {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px',
            }
        );

        revealElements.forEach(function (el) {
            revealObserver.observe(el);
        });
    } else {
        // Fallback: show everything
        revealElements.forEach(function (el) {
            el.classList.add('tw-reveal--visible');
        });
    }

    // ============================================================
    // Smooth scroll for anchor links
    // ============================================================
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
            var targetId = this.getAttribute('href');
            if (targetId === '#') return;

            var target = document.querySelector(targetId);
            if (target) {
                e.preventDefault();
                var headerHeight = header ? header.offsetHeight : 0;
                var targetPosition = target.getBoundingClientRect().top + window.scrollY - headerHeight;

                window.scrollTo({
                    top: targetPosition,
                    behavior: 'smooth',
                });
            }
        });
    });

    // ============================================================
    // Hero parallax (subtle)
    // ============================================================
    const heroBg = document.querySelector('.tw-hero__bg');

    if (heroBg) {
        window.addEventListener(
            'scroll',
            function () {
                var scrolled = window.scrollY;
                if (scrolled < window.innerHeight) {
                    heroBg.style.transform = 'translateY(' + scrolled * 0.3 + 'px) scale(1.05)';
                }
            },
            { passive: true }
        );
    }
})();
