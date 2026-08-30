(function () {
    'use strict';

    var config = window.KH_HOME_REDESIGN || {};
    var activeTagId = 0;
    var controller = null;

    function createCard(item) {
        var article = document.createElement('article');
        var media = document.createElement('a');
        var title = document.createElement('h2');
        var titleLink = document.createElement('a');
        var meta = document.createElement('div');
        var date = document.createElement('time');
        var read = document.createElement('a');

        article.className = 'entry-card kh-article-card is-ready post-' + item.id;
        article.dataset.khEnhanced = 'true';

        media.className = 'ct-media-container';
        media.href = item.url;
        media.setAttribute('aria-label', item.title);

        if (item.image) {
            var image = document.createElement('img');
            image.src = item.image;
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            media.appendChild(image);
        }

        if (item.label) {
            var badge = document.createElement('span');
            badge.className = 'kh-card-badge';
            badge.textContent = item.label;
            media.appendChild(badge);
        }

        title.className = 'entry-title';
        titleLink.href = item.url;
        titleLink.textContent = item.title;
        title.appendChild(titleLink);

        meta.className = 'kh-card-meta';
        date.className = 'kh-card-date';
        date.dateTime = item.dateIso || '';
        date.textContent = item.date || '';
        read.className = 'kh-card-cta';
        read.href = item.url;
        read.textContent = config.readLabel || 'Читать';
        read.setAttribute('aria-label', (config.readLabel || 'Читать') + ': ' + item.title);
        meta.append(date, read);

        article.append(media, title, meta);
        return article;
    }

    function pageTokens(totalPages, currentPage) {
        var candidates = [1, currentPage - 1, currentPage, currentPage + 1, totalPages];
        var pages = candidates
            .filter(function (page) {
                return page >= 1 && page <= totalPages;
            })
            .filter(function (page, index, values) {
                return values.indexOf(page) === index;
            })
            .sort(function (a, b) {
                return a - b;
            });
        var tokens = [];

        pages.forEach(function (page, index) {
            if (index > 0 && page - pages[index - 1] > 1) {
                tokens.push('ellipsis-' + page);
            }
            tokens.push(page);
        });

        return tokens;
    }

    function renderPagination(pagination, totalPages, currentPage) {
        pagination.replaceChildren();
        pagination.setAttribute('data-pagination', 'kh-tags');
        pagination.hidden = totalPages <= 1;
        pagination.classList.add('kh-tag-pagination');

        if (totalPages <= 1) {
            return;
        }

        pageTokens(totalPages, currentPage).forEach(function (token) {
            if (typeof token === 'string') {
                var ellipsis = document.createElement('span');
                ellipsis.className = 'page-numbers dots';
                ellipsis.textContent = '…';
                pagination.appendChild(ellipsis);
                return;
            }

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'page-numbers' + (token === currentPage ? ' current' : '');
            button.dataset.khPage = String(token);
            button.textContent = String(token);
            button.setAttribute('aria-label', 'Страница ' + token);
            if (token === currentPage) {
                button.setAttribute('aria-current', 'page');
            }
            pagination.appendChild(button);
        });
    }

    function setActiveTag(filters, tagId) {
        filters.forEach(function (filter) {
            var isActive = Number(filter.dataset.tagId || 0) === tagId;
            filter.classList.toggle('is-active', isActive);
            if (isActive) {
                filter.setAttribute('aria-current', 'page');
            } else {
                filter.removeAttribute('aria-current');
            }
        });
    }

    function updateArticles(entries, items) {
        entries.querySelectorAll(':scope > article.entry-card').forEach(function (article) {
            article.remove();
        });
        items.forEach(function (item) {
            entries.appendChild(createCard(item));
        });
    }

    function init() {
        var entries = document.querySelector('.entries');
        var filtersContainer = document.querySelector('.kh-home-filters');
        var filters = Array.from(document.querySelectorAll('.kh-home-filter[data-tag-id]'));
        var pagination = document.querySelector('.ct-pagination');
        var status = document.getElementById('kh-tag-filter-status');
        var tools = document.querySelector('.kh-home-tools');

        if (!entries || !filtersContainer || !filters.length || !pagination || !status || !config.tagEndpoint) {
            return;
        }

        function setStatus(message, state) {
            status.textContent = message || '';
            status.dataset.state = state || '';
            status.hidden = !message;
        }

        function loadPage(page, shouldScroll) {
            if (controller) {
                controller.abort();
            }
            var requestController = new AbortController();
            controller = requestController;
            entries.setAttribute('aria-busy', 'true');
            entries.classList.add('is-kh-filter-loading');
            setStatus(config.tagLoadingLabel || 'Обновляем статьи…', 'loading');

            var endpointBase = String(config.tagEndpoint).replace(/\/+$/, '');
            var endpoint = new URL(
                endpointBase + '/' + activeTagId + '/' + page,
                window.location.origin
            );

            window.fetch(endpoint.toString(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: requestController.signal
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Tag filter request failed');
                    }
                    return response.json();
                })
                .then(function (data) {
                    updateArticles(entries, data.items || []);
                    renderPagination(pagination, Number(data.totalPages || 1), Number(data.page || 1));
                    setStatus('', '');

                    if (shouldScroll && tools) {
                        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                        tools.scrollIntoView({
                            behavior: reducedMotion ? 'auto' : 'smooth',
                            block: 'start'
                        });
                    }
                })
                .catch(function (error) {
                    if (error.name !== 'AbortError') {
                        setStatus(
                            config.tagErrorLabel || 'Не удалось обновить статьи. Попробуйте ещё раз.',
                            'error'
                        );
                    }
                })
                .finally(function () {
                    if (controller !== requestController) {
                        return;
                    }
                    controller = null;
                    entries.removeAttribute('aria-busy');
                    entries.classList.remove('is-kh-filter-loading');
                });
        }

        filtersContainer.addEventListener('click', function (event) {
            var filter = event.target.closest('.kh-home-filter[data-tag-id]');
            if (!filter) {
                return;
            }

            event.preventDefault();
            activeTagId = Number(filter.dataset.tagId || 0);
            setActiveTag(filters, activeTagId);

            var details = filter.closest('details');
            if (details) {
                details.removeAttribute('open');
            }

            loadPage(1, false);
        });

        pagination.addEventListener('click', function (event) {
            var button = event.target.closest('button[data-kh-page]');
            if (!button) {
                return;
            }
            loadPage(Number(button.dataset.khPage || 1), true);
        });

        renderPagination(pagination, Number(config.initialTotalPages || 1), 1);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
