/* Mobile navigation revision: 2026-07-27b. */
(function () {
    'use strict';

    var footerScrolled = false;

    function createMobileBrand(offcanvas) {
        var actions = offcanvas.querySelector('.ct-panel-actions');
        if (!actions || actions.querySelector('.kh-mobile-menu-brand')) {
            return;
        }

        var sourceLink = document.querySelector('[data-id="logo"] .site-logo-container');
        var sourceLogo = sourceLink ? sourceLink.querySelector('img') : null;
        var brand = document.createElement('a');
        var copy = document.createElement('span');
        var title = document.createElement('strong');
        var subtitle = document.createElement('small');

        brand.className = 'kh-mobile-menu-brand';
        brand.href = sourceLink ? sourceLink.href : '/';
        brand.setAttribute('aria-label', 'На главную Koloda Hearthstone');

        if (sourceLogo) {
            var logo = document.createElement('img');
            var loadedSource = sourceLogo.currentSrc && sourceLogo.currentSrc.indexOf('data:image') !== 0
                ? sourceLogo.currentSrc
                : (sourceLogo.dataset.src || sourceLogo.src);

            logo.src = loadedSource;
            logo.alt = '';
            logo.width = 44;
            logo.height = 44;
            logo.decoding = 'async';
            logo.fetchPriority = 'low';
            logo.setAttribute('aria-hidden', 'true');
            brand.append(logo);
        } else {
            brand.classList.add('kh-mobile-menu-brand--text-only');
        }

        title.textContent = 'Меню';
        subtitle.textContent = 'Koloda Hearthstone';
        copy.append(title, subtitle);
        brand.append(copy);
        actions.prepend(brand);
    }

    function enhanceMobileItem(menu, selector, className, label) {
        var link = menu.querySelector(selector);
        var item = link ? link.closest('li') : null;

        if (!item) {
            return null;
        }

        item.classList.add('kh-menu-button', className);
        if (label) {
            link.textContent = label;
        }

        return item;
    }

    function enhanceMobileMenu() {
        var offcanvas = document.getElementById('offcanvas');
        var menu = offcanvas ? offcanvas.querySelector('.mobile-menu') : null;

        if (!offcanvas || !menu || menu.dataset.khEnhanced === 'true') {
            return;
        }

        menu.dataset.khEnhanced = 'true';
        offcanvas.setAttribute('aria-label', 'Навигация по сайту');
        createMobileBrand(offcanvas);

        enhanceMobileItem(
            menu,
            'a[href*="/category/besplatnyj-material/"]',
            'kh-menu-icon--free',
            'Бесплатные материалы'
        );
        var profileLink = menu.querySelector('a[href="#mtp_login"]');
        var profileItem = profileLink ? profileLink.closest('li') : null;
        if (profileItem) {
            profileItem.remove();
        }

        var boostyItem = menu.querySelector('.kh-menu-icon--boosty');
        var list = menu.querySelector(':scope > ul');
        if (boostyItem && list && boostyItem !== list.lastElementChild) {
            list.append(boostyItem);
        }
    }

    function scrollToFooterFromHash() {
        if (footerScrolled || window.location.hash !== '#kh-site-footer') {
            return;
        }

        var footer = document.getElementById('kh-site-footer');
        if (footer) {
            footerScrolled = true;
            footer.scrollIntoView({ block: 'start' });
        }
    }

    function init() {
        enhanceMobileMenu();
        scrollToFooterFromHash();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
