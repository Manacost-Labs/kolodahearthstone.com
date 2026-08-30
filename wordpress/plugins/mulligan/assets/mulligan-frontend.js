(function () {
    var io = ('IntersectionObserver' in window) ? new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (e.isIntersecting) {
                io.unobserve(e.target);
                processOne(e.target);
            }
        });
    }, { rootMargin: '200px 0px' }) : null;

    function init() {
        document.querySelectorAll('.hs-mulligan-app').forEach(function (root) {
            if (root.dataset.ready === '1') return;
            if (io) {
                io.observe(root);
            } else {
                processOne(root);
            }
        });
    }

    function processOne(root) {
        if (root.dataset.ready === '1') return;
        root.dataset.ready = '1';

        var payload;
        try { payload = JSON.parse(root.getAttribute('data-payload') || '{}'); }
        catch (e) { return; }

        var cardsDict = payload.cards || {};
        var pool = [];
        (payload.pool || []).forEach(function (pair) {
            var dbf = pair[0], count = pair[1];
            var info = cardsDict[dbf] || {};
            var entry = {
                dbfId: dbf,
                name:  info.n || ('dbfId ' + dbf),
                cost:  info.c == null ? 0 : info.c,
                image: info.i || '',
                imageBig: info.b || '',
                text:  info.t || '',
                rarity: info.r || '',
                type:  info.y || ''
            };
            for (var i = 0; i < count; i++) pool.push(entry);
        });
        var profiles = (payload.profiles || []).map(function (p) {
            var cards = {};
            Object.keys(p.cards || {}).forEach(function (k) {
                var v = p.cards[k];
                cards[k] = { keep: (v && v[0]) | 0, note: (v && v[1]) || '' };
            });
            return { id: p.id, label: p.label, cards: cards };
        });
        var crossUrl = root.getAttribute('data-cross') || '';
        var postId   = parseInt(root.getAttribute('data-id'), 10) || 0;

        var cardsEl  = root.querySelector('.hs-mulligan-cards');
        var resultEl = root.querySelector('.hs-mulligan-result');
        var checkBtn = root.querySelector('.hs-mulligan-check');
        var resetBtn = root.querySelector('.hs-mulligan-reset');
        var oppBar   = root.querySelector('.hs-mulligan-opponent-bar');
        var oppChips = root.querySelector('.hs-mulligan-opponent-chips');
        var oppNow   = root.querySelector('.hs-mulligan-vs-name');
        var coinChipsEl = root.querySelector('.hs-mulligan-coin-chips');
        var coinNow  = root.querySelector('.hs-mulligan-coin-now');
        var tooltip  = root.querySelector('.hs-mulligan-tooltip');
        var selected = '__random__';
        var coinMode = 'random';
        var currentCoin = 'second';

        if (pool.length < 3) {
            cardsEl.innerHTML = '<p class="hs-mulligan-error">В колоде меньше 3 карт.</p>';
            return;
        }
        if (!profiles.length) {
            cardsEl.innerHTML = '<p class="hs-mulligan-error">Для этого тренажёра не настроен ни один профиль муллигана.</p>';
            if (oppBar) oppBar.style.display = 'none';
            checkBtn.disabled = true; resetBtn.disabled = true;
            return;
        }

        // ---- chips ----
        function buildChips() {
            var html = '';
            if (profiles.length > 1) {
                html += '<button type="button" class="hs-mulligan-chip is-random' +
                        (selected === '__random__' ? ' is-active' : '') +
                        '" data-id="__random__"><span class="hs-mulligan-chip-icon">🎲</span>Случайный</button>';
            }
            profiles.forEach(function (p) {
                html += '<button type="button" class="hs-mulligan-chip' +
                        (selected === p.id ? ' is-active' : '') +
                        '" data-id="' + esc(p.id) + '">' + esc(p.label) + '</button>';
            });
            oppChips.innerHTML = html;
            oppChips.querySelectorAll('.hs-mulligan-chip').forEach(function (b) {
                b.onclick = function () {
                    selected = b.getAttribute('data-id');
                    oppChips.querySelectorAll('.hs-mulligan-chip').forEach(function (x) {
                        x.classList.toggle('is-active', x === b);
                    });
                    deal();
                };
            });
            if (profiles.length === 1) selected = profiles[0].id;
        }
        if (coinChipsEl) {
            coinChipsEl.querySelectorAll('.hs-mulligan-coin-chip').forEach(function (b) {
                b.onclick = function () {
                    coinMode = b.getAttribute('data-coin');
                    coinChipsEl.querySelectorAll('.hs-mulligan-coin-chip').forEach(function (x) {
                        x.classList.toggle('is-active', x === b);
                    });
                    deal();
                };
            });
        }
        buildChips();

        // ---- pickProfile + coin ----
        var hand = [], replaced = [], activeProfile = null;

        function pickCoin() {
            currentCoin = (coinMode === 'random')
                ? (Math.random() < 0.5 ? 'first' : 'second')
                : coinMode;
            if (coinNow) {
                coinNow.textContent = currentCoin === 'first'
                    ? '· Иду первым (3 карты)'
                    : '· Иду вторым (4 карты + монета)';
            }
        }
        function pickProfile() {
            if (selected === '__random__') {
                activeProfile = profiles[Math.floor(Math.random() * profiles.length)];
            } else {
                activeProfile = profiles.filter(function (p) { return p.id === selected; })[0] || profiles[0];
            }
            oppNow.textContent = activeProfile.label;
        }

        function deal() {
            pickProfile();
            pickCoin();
            var size = currentCoin === 'first' ? 3 : 4;
            if (pool.length < size) size = pool.length;
            hand = drawHand(pool, size);
            replaced = hand.map(function () { return false; });
            resultEl.hidden = true;
            resultEl.innerHTML = '';
            checkBtn.disabled = false;
            render();
        }

        function render() {
            clearTimeout(tooltipTimer);
            hideTooltip();
            cardsEl.className = 'hs-mulligan-cards is-size-' + hand.length;
            cardsEl.innerHTML = hand.map(function (c, i) {
                var isReplaced = replaced[i];
                var imgAttrs = 'class="no-lightbox nolightbox skip-lightbox" ' +
                               'data-no-lightbox="1" data-fancybox="false" data-amp-lightbox="false" ' +
                               'loading="lazy" alt=""';
                var crossImg = crossUrl
                    ? '<img class="hs-mulligan-cross-img no-lightbox nolightbox" data-no-lightbox="1" data-fancybox="false" alt="" src="' + esc(crossUrl) + '">'
                    : '<span class="hs-mulligan-cross-fallback">✕</span>';
                return '<div class="hs-mulligan-card' + (isReplaced ? ' is-replaced' : '') + '" data-i="' + i + '">' +
                    (c.image
                        ? '<div class="hs-mulligan-img-wrap"><img ' + imgAttrs + ' src="' + esc(c.image) + '"></div>'
                        : '<div class="hs-mulligan-noimg">' + esc(c.name) + '</div>') +
                    '<div class="hs-mulligan-cross">' + crossImg + '</div>' +
                    '<div class="hs-mulligan-replaced-label">ЗАМЕНИТЬ</div>' +
                    '</div>';
            }).join('');
            cardsEl.querySelectorAll('.hs-mulligan-card').forEach(function (el) {
                var i = parseInt(el.getAttribute('data-i'), 10);
                el.onclick = function () {
                    if (checkBtn.disabled) return;
                    replaced[i] = !replaced[i];
                    el.classList.toggle('is-replaced', replaced[i]);
                };
            });
        }

        // ---- tooltip ----
        // Переносим тултип в body, чтобы overflow:hidden у .hs-mulligan-app его не обрезал.
        if (tooltip && tooltip.parentNode !== document.body) {
            document.body.appendChild(tooltip);
        }
        var tooltipTimer;
        function showTooltip(card, anchor) {
            if (!tooltip) return;
            if (!card.imageBig && !card.text) return;
            var html = '';
            if (card.imageBig) {
                html += '<img class="hs-mulligan-tt-img no-lightbox nolightbox" data-no-lightbox="1" data-fancybox="false" alt="" src="' + esc(card.imageBig) + '">';
            }
            if (card.text || card.type) {
                var typeStr = (card.type || '').replace(/^./, function (c) { return c.toUpperCase(); }).toLowerCase();
                html += '<div class="hs-mulligan-tt-info">';
                if (card.type)   html += '<span class="hs-mulligan-tt-type">' + esc(typeStr) + '</span>';
                if (card.rarity) html += '<span class="hs-mulligan-tt-rarity">' + esc(card.rarity.toLowerCase()) + '</span>';
                if (card.text)   html += '<div class="hs-mulligan-tt-text">' + sanitizeCardText(card.text) + '</div>';
                html += '</div>';
            }
            tooltip.innerHTML = html;
            tooltip.hidden = false;

            var rect = anchor.getBoundingClientRect();
            var ttWidth = 320;
            var scrollX = window.pageXOffset || document.documentElement.scrollLeft;
            var scrollY = window.pageYOffset || document.documentElement.scrollTop;
            // Прижимаем тултип к выбранной карте; если справа не помещается — слева.
            var left = rect.right + scrollX + 12;
            if (rect.right + 12 + ttWidth > window.innerWidth - 8) {
                left = rect.left + scrollX - ttWidth - 12;
            }
            if (left < scrollX + 8) left = scrollX + 8;
            var top = rect.top + scrollY;
            // Поднимаем тултип, если он выходит за нижнюю кромку viewport.
            tooltip.style.left = left + 'px';
            tooltip.style.top  = Math.max(scrollY + 8, top) + 'px';
        }
        function hideTooltip() {
            if (!tooltip) return;
            tooltip.hidden = true;
            tooltip.innerHTML = '';
        }
        function attachTooltip(el, card) {
            el.addEventListener('mouseenter', function () {
                clearTimeout(tooltipTimer);
                tooltipTimer = setTimeout(function () { showTooltip(card, el); }, 220);
            });
            el.addEventListener('mouseleave', function () {
                clearTimeout(tooltipTimer);
                hideTooltip();
            });
            // Long press для тача
            var pressTimer;
            el.addEventListener('touchstart', function () {
                pressTimer = setTimeout(function () { showTooltip(card, el); }, 450);
            }, { passive: true });
            el.addEventListener('touchend',   function () { clearTimeout(pressTimer); });
            el.addEventListener('touchmove',  function () { clearTimeout(pressTimer); hideTooltip(); }, { passive: true });
        }

        // ---- check + analytics ----
        checkBtn.onclick = function () {
            checkBtn.disabled = true;
            var profCards = (activeProfile && activeProfile.cards) || {};
            var correct = 0, wrongKeep = 0, missed = 0;
            var lines = [];

            var perDbf = {};
            var order  = [];
            hand.forEach(function (c, i) {
                var d = c.dbfId;
                if (!perDbf[d]) {
                    perDbf[d] = { card: c, copies: 0, kept: 0 };
                    order.push(d);
                }
                perDbf[d].copies++;
                if (!replaced[i]) perDbf[d].kept++;
            });

            order.forEach(function (d) {
                var info  = perDbf[d];
                var entry = profCards[d] || {};
                var max   = (entry.keep | 0);
                var note  = entry.note || '';
                var recommended = Math.min(info.copies, max);
                var kept = info.kept;
                var cls, label;
                var copyTag = info.copies > 1 ? ' <span class="hs-mulligan-copies">в руке ×' + info.copies + '</span>' : '';

                if (kept === recommended) {
                    cls = 'ok';
                    if (info.copies === 1) {
                        label = recommended === 1 ? '✓ верно оставлена' : '✓ верно заменена';
                    } else {
                        label = kept === 0 ? '✓ обе копии верно заменены'
                              : kept === info.copies ? '✓ обе копии верно оставлены'
                              : '✓ верно: оставлена 1 из ' + info.copies;
                    }
                    correct += info.copies;
                } else if (kept > recommended) {
                    var over = kept - recommended;
                    cls = 'bad';
                    label = '✗ оставлено ' + kept + ', нужно ' + recommended;
                    wrongKeep += over;
                    correct += info.copies - over;
                } else {
                    var miss = recommended - kept;
                    cls = 'missed';
                    label = '↻ оставлено ' + kept + ', стоило ' + recommended;
                    missed += miss;
                    correct += info.copies - miss;
                }

                var noteHtml = note ? '<div class="hs-mulligan-note">' + esc(note) + '</div>' : '';
                lines.push(
                    '<li class="hs-mulligan-line is-' + cls + '">' +
                      '<div class="hs-mulligan-line-head"><strong>' + esc(info.card.name) + '</strong>' + copyTag + ' — ' + label + '</div>' +
                      noteHtml +
                    '</li>'
                );
            });

            var total = hand.length;
            var score = Math.round(correct / total * 100);
            var verdict = score === 100 ? 'Идеальный муллиган!' :
                          score >= 75 ? 'Хорошо.' :
                          score >= 50 ? 'Так себе.' : 'Плохо — пересмотрите план муллигана.';
            var coinLabel = currentCoin === 'first' ? 'Иду первым' : 'Иду вторым (с монетой)';
            resultEl.innerHTML =
                '<div class="hs-mulligan-score">' + esc(coinLabel) + ' &nbsp;·&nbsp; против <strong>' + esc(activeProfile.label) + '</strong> &nbsp;·&nbsp; ' +
                'Оценка: <strong>' + score + '%</strong> &nbsp;·&nbsp; ' + esc(verdict) + '</div>' +
                '<ul class="hs-mulligan-lines">' + lines.join('') + '</ul>' +
                '<div class="hs-mulligan-summary">Верно: ' + correct + ' &nbsp;·&nbsp; Зря оставлено: ' + wrongKeep +
                ' &nbsp;·&nbsp; Зря заменено: ' + missed + '</div>';
            resultEl.hidden = false;

            postTrack(postId, 'check', score);
        };

        resetBtn.onclick = deal;
        deal();
        postTrack(postId, 'view', -1);
    }

    function postTrack(postId, action, score) {
        if (!postId) return;
        var cfg = window.hsMulliganFront;
        if (!cfg || !cfg.ajaxurl || !cfg.nonce) return;
        var data = 'action=hs_mulligan_track&nonce=' + encodeURIComponent(cfg.nonce) +
                   '&id=' + encodeURIComponent(postId) +
                   '&t=' + encodeURIComponent(action);
        if (action === 'check' && typeof score === 'number' && score >= 0) data += '&score=' + score;
        try {
            if (navigator.sendBeacon) {
                var blob = new Blob([data], { type: 'application/x-www-form-urlencoded' });
                navigator.sendBeacon(cfg.ajaxurl, blob);
            } else {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', cfg.ajaxurl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.send(data);
            }
        } catch (e) {}
    }
    // Упрощение: в processOne делаю замыкание — переопределим track:
    // (объявим перед использованием)

    function drawHand(pool, n) {
        var copy = pool.slice();
        var hand = [];
        for (var i = 0; i < n && copy.length; i++) {
            var idx = Math.floor(Math.random() * copy.length);
            hand.push(copy.splice(idx, 1)[0]);
        }
        return hand;
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c];
        });
    }

    // HearthstoneJSON-текст содержит [x] для игнорируемого префикса, $/# для бафов и <b>/<i>.
    function sanitizeCardText(text) {
        var s = String(text);
        s = s.replace(/\[x\]/g, '');
        var SENT = '';
        var tokens = [];
        s = s.replace(/<\/?(b|i)>|<br\s*\/?>/gi, function (m) {
            var clean = m.toLowerCase().replace(/<br\s*\/?>/i, '<br>');
            tokens.push(clean);
            return SENT + (tokens.length - 1) + SENT;
        });
        s = s.replace(/[\$#](\d+)/g, function (_, n) {
            tokens.push('<b>'); var i1 = tokens.length - 1;
            tokens.push('</b>'); var i2 = tokens.length - 1;
            return SENT + i1 + SENT + n + SENT + i2 + SENT;
        });
        s = esc(s);
        s = s.replace(new RegExp(SENT + '(\\d+)' + SENT, 'g'), function (_, i) { return tokens[+i]; });
        s = s.replace(/\n/g, '<br>');
        return s;
    }


    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
