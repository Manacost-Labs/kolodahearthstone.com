(function () {
    'use strict';

    var config = window.KH_HOME_REDESIGN || {};
    var cards = config.cards || {};
    var articleSelector = '.entries article.entry-card';
    var searchDelay = 180;

    function postId(article) {
        var match = String(article.className || '').match(/(?:^|\s)post-(\d+)(?:\s|$)/);
        return match ? match[1] : '';
    }

    function enhanceCard(article) {
        if (article.dataset.khEnhanced === 'true') {
            return;
        }

        var id = postId(article);
        var data = cards[id] || {};
        var title = article.querySelector('.entry-title');
        var titleLink = title ? title.querySelector('a') : null;
        var media = article.querySelector('.ct-media-container');

        if (!title || !titleLink || !media) {
            return;
        }

        article.dataset.khEnhanced = 'true';
        article.classList.add('kh-article-card');

        if (data.label) {
            var badge = document.createElement('span');
            badge.className = 'kh-card-badge';
            badge.textContent = data.label;
            media.appendChild(badge);
        }

        var meta = document.createElement('div');
        meta.className = 'kh-card-meta';

        if (data.date) {
            var date = document.createElement('time');
            date.className = 'kh-card-date';
            date.textContent = data.date;
            if (data.dateIso) {
                date.dateTime = data.dateIso;
            }
            meta.appendChild(date);
        }

        var cta = document.createElement('a');
        cta.className = 'kh-card-cta';
        cta.href = titleLink.href;
        cta.textContent = data.vip
            ? (config.readVipLabel || 'Читать')
            : (config.readLabel || 'Читать');
        cta.setAttribute('aria-label', cta.textContent + ': ' + titleLink.textContent.trim());
        meta.appendChild(cta);

        article.appendChild(meta);
        window.requestAnimationFrame(function () {
            article.classList.add('is-ready');
        });
    }

    function init() {
        document.querySelectorAll(articleSelector).forEach(enhanceCard);
        initLiveSearch();
    }

    function initLiveSearch() {
        var form = document.querySelector('.kh-home-search');
        var input = document.getElementById('kh-home-search-input');
        var results = document.getElementById('kh-live-search-results');
        var timer = 0;
        var controller = null;

        if (!form || !input || !results || !config.searchEndpoint) {
            return;
        }

        function hideResults() {
            results.hidden = true;
            input.setAttribute('aria-expanded', 'false');
        }

        function showStatus(message, state) {
            results.replaceChildren();

            var status = document.createElement('p');
            status.className = 'kh-live-search__status';
            status.dataset.state = state || 'status';
            status.textContent = message;
            results.appendChild(status);
            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function renderResults(items) {
            results.replaceChildren();

            if (!items.length) {
                showStatus(config.searchEmptyLabel || 'Статьи не найдены.', 'empty');
                return;
            }

            var list = document.createElement('ul');
            list.className = 'kh-live-search__list';

            items.forEach(function (item) {
                var row = document.createElement('li');
                var link = document.createElement('a');
                var marker = document.createElement('span');
                var title = document.createElement('span');
                var arrow = document.createElement('span');

                link.href = item.url;
                link.setAttribute('role', 'option');
                marker.className = 'kh-live-search__marker';
                marker.textContent = 'Статья';
                title.className = 'kh-live-search__title';
                title.textContent = item.title;
                arrow.className = 'kh-live-search__arrow';
                arrow.setAttribute('aria-hidden', 'true');
                arrow.textContent = '→';

                link.append(marker, title, arrow);
                row.appendChild(link);
                list.appendChild(row);
            });

            results.appendChild(list);
            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function runSearch(query) {
            if (controller) {
                controller.abort();
            }

            controller = new AbortController();
            showStatus(config.searchLoadingLabel || 'Ищем статьи…', 'loading');

            var endpoint = new URL(config.searchEndpoint, window.location.origin);

            window.fetch(endpoint.toString(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ search: query }),
                signal: controller.signal
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Search request failed');
                    }
                    return response.json();
                })
                .then(function (data) {
                    renderResults(
                        data && Array.isArray(data.items)
                            ? data.items
                            : []
                    );
                })
                .catch(function (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    showStatus(
                        config.searchErrorLabel || 'Поиск временно недоступен. Нажмите «Найти».',
                        'error'
                    );
                });
        }

        input.addEventListener('input', function () {
            var query = input.value.trim();
            window.clearTimeout(timer);

            if (!query) {
                if (controller) {
                    controller.abort();
                }
                hideResults();
                return;
            }

            timer = window.setTimeout(function () {
                runSearch(query);
            }, searchDelay);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                hideResults();
                return;
            }

            if (event.key === 'ArrowDown' && !results.hidden) {
                var firstResult = results.querySelector('a');
                if (firstResult) {
                    event.preventDefault();
                    firstResult.focus();
                }
            }
        });

        results.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                hideResults();
                input.focus();
            }
        });

        document.addEventListener('click', function (event) {
            if (!form.contains(event.target)) {
                hideResults();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
