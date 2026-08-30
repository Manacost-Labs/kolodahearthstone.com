(function () {
    var tooltipEl = null;
    var tooltipTimer = null;

    function ensureTooltip() {
        if (tooltipEl) return tooltipEl;
        tooltipEl = document.createElement('div');
        tooltipEl.className = 'hs-decklist-tooltip';
        tooltipEl.hidden = true;
        document.body.appendChild(tooltipEl);
        return tooltipEl;
    }

    function showTooltip(card) {
        var src = card.getAttribute('data-img-big') || '';
        if (!src) return;
        var tt = ensureTooltip();
        tt.innerHTML = '<img alt="" class="no-lightbox nolightbox" data-no-lightbox="1" data-fancybox="false" src="' + escAttr(src) + '">';
        tt.hidden = false;
        position(tt, card);
    }
    function hideTooltip() {
        if (!tooltipEl) return;
        tooltipEl.hidden = true;
        tooltipEl.innerHTML = '';
    }
    function position(tt, anchor) {
        var rect = anchor.getBoundingClientRect();
        var sx = window.pageXOffset || document.documentElement.scrollLeft;
        var sy = window.pageYOffset || document.documentElement.scrollTop;
        var W = 280, H = 380;
        // Справа от строки если влезает, иначе слева
        var left = rect.right + sx + 12;
        if (rect.right + 12 + W > window.innerWidth - 8) {
            left = rect.left + sx - W - 12;
        }
        if (left < sx + 8) left = sx + 8;
        // Центрируем по вертикали относительно строки, но чтобы тултип не вышел за viewport
        var top = rect.top + sy + (rect.height / 2) - (H / 2);
        var minTop = sy + 8;
        var maxTop = sy + window.innerHeight - H - 8;
        if (top < minTop) top = minTop;
        if (top > maxTop) top = maxTop;
        tt.style.left = left + 'px';
        tt.style.top  = top  + 'px';
        tt.style.width = W + 'px';
    }

    function bindCard(card) {
        if (card.dataset.bound === '1') return;
        card.dataset.bound = '1';
        card.addEventListener('mouseenter', function () {
            clearTimeout(tooltipTimer);
            tooltipTimer = setTimeout(function () { showTooltip(card); }, 180);
        });
        card.addEventListener('mouseleave', function () {
            clearTimeout(tooltipTimer);
            hideTooltip();
        });
        // Long-press для тач-устройств
        var pressTimer;
        card.addEventListener('touchstart', function () {
            pressTimer = setTimeout(function () { showTooltip(card); }, 420);
        }, { passive: true });
        card.addEventListener('touchend',  function () { clearTimeout(pressTimer); });
        card.addEventListener('touchmove', function () { clearTimeout(pressTimer); hideTooltip(); }, { passive: true });
    }

    function bindCopy(btn) {
        if (btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', function () {
            var code = btn.getAttribute('data-code') || '';
            if (!code) return;
            doCopy(code).then(function (ok) {
                if (!ok) return;
                btn.classList.add('is-copied');
                var text = btn.querySelector('.hs-decklist-copy-text');
                var prev = text ? text.textContent : '';
                if (text) text.textContent = 'Скопировано';
                setTimeout(function () {
                    btn.classList.remove('is-copied');
                    if (text) text.textContent = prev || 'Копировать код колоды';
                }, 1800);
            });
        });
    }

    function doCopy(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).then(
                function () { return true; },
                function () { return fallback(text); }
            );
        }
        return Promise.resolve(fallback(text));
    }
    function fallback(text) {
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (e) { return false; }
    }

    function escAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function bindRating(box) {
        if (box.dataset.bound === '1') return;
        box.dataset.bound = '1';
        var stars = box.querySelectorAll('.hs-decklist-star');
        var avgEl = box.querySelector('.hs-decklist-rate-avg');
        var nEl   = box.querySelector('.hs-decklist-rate-n');
        var summary = box.querySelector('.hs-decklist-rate-summary');
        var rated = false;

        function paint(n) {
            stars.forEach(function (s, i) {
                s.classList.toggle('is-hover', i < n);
            });
        }
        function unpaint() {
            stars.forEach(function (s) { s.classList.remove('is-hover'); });
        }

        stars.forEach(function (s) {
            s.addEventListener('mouseenter', function () { paint(parseInt(s.getAttribute('data-value'), 10)); });
            s.addEventListener('mouseleave', unpaint);
            s.addEventListener('click', function () {
                if (rated) return;
                var rating = parseInt(s.getAttribute('data-value'), 10);
                var hash = box.getAttribute('data-hash');
                var cfg = window.hsDecklistFront || {};
                if (!cfg.ajaxurl || !cfg.rateNonce) {
                    console.error('[hs-decklist] Не удаётся отправить оценку — отсутствует hsDecklistFront. Проверьте порядок wp_register_script/wp_localize_script.');
                    return;
                }

                rated = true;
                var data = 'action=hs_decklist_rate&nonce=' + encodeURIComponent(cfg.rateNonce) +
                           '&hash=' + encodeURIComponent(hash) +
                           '&rating=' + rating;
                var xhr = new XMLHttpRequest();
                xhr.open('POST', cfg.ajaxurl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState !== 4) return;
                    var resp;
                    try { resp = JSON.parse(xhr.responseText); } catch (e) { resp = null; }
                    if (resp && (resp.success || (resp.data && typeof resp.data.avg !== 'undefined'))) {
                        var d = resp.data || {};
                        if (typeof d.avg !== 'undefined' && avgEl) avgEl.textContent = parseFloat(d.avg).toFixed(1);
                        if (typeof d.count !== 'undefined' && nEl) nEl.textContent = d.count;
                        // Подсветим выбранное на корне
                        stars.forEach(function (st, i) { st.classList.toggle('is-on', i < rating); });
                        if (resp.success) {
                            box.classList.add('is-thanks');
                            if (summary) summary.title = 'Спасибо за оценку!';
                        } else if (resp.data && resp.data.message) {
                            box.classList.add('is-already');
                            if (summary) summary.title = resp.data.message;
                        }
                    } else {
                        rated = false; // ошибка сети — позволим повторить
                    }
                };
                xhr.send(data);
            });
        });
    }

    function init() {
        document.querySelectorAll('.hs-decklist-card').forEach(bindCard);
        document.querySelectorAll('.hs-decklist-copy').forEach(bindCopy);
        document.querySelectorAll('.hs-decklist-rating').forEach(bindRating);
        // Скролл/ресайз — прячем тултип чтобы он не «отрывался»
        window.addEventListener('scroll',  hideTooltip, { passive: true });
        window.addEventListener('resize',  hideTooltip);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
