(function () {
    tinymce.PluginManager.add('hs_mulligan', function (editor) {
        editor.addButton('hs_mulligan', {
            title: 'Муллиган',
            text: 'Муллиган',
            icon: false,
            type: 'menubutton',
            menu: [
                { text: 'Создать новый', onclick: function () { openModal(editor, null); } },
                { text: 'Редактировать существующий', onclick: function () { openSearch(editor); } }
            ]
        });
    });

    function openSearch(editor) {
        injectStyles();
        var modal = document.createElement('div');
        modal.className = 'hsm-modal-overlay';
        modal.innerHTML =
            '<div class="hsm-modal">' +
              '<div class="hsm-modal-head"><h2>Открыть существующий муллиган</h2>' +
              '<button type="button" class="hsm-close" aria-label="Закрыть">&times;</button></div>' +
              '<div class="hsm-modal-body">' +
                '<input type="text" class="hsm-search" placeholder="Поиск по названию (от 2 символов)">' +
                '<div class="hsm-search-results"></div>' +
              '</div>' +
              '<div class="hsm-modal-foot">' +
                '<button type="button" class="hsm-cancel">Отмена</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(modal);
        modal.querySelector('.hsm-close').onclick = close;
        modal.querySelector('.hsm-cancel').onclick = close;
        function close() { modal.parentNode && modal.parentNode.removeChild(modal); }

        var $search = modal.querySelector('.hsm-search');
        var $list   = modal.querySelector('.hsm-search-results');
        var t;
        function fetchList(q) {
            ajax('hs_mulligan_search', { query: q || '' }, function (resp) {
                if (!resp || !resp.success) return;
                var items = (resp.data && resp.data.items) || [];
                $list.innerHTML = items.length
                    ? items.map(function (it) {
                        return '<div class="hsm-search-item" data-id="' + it.id + '">' +
                                 '<span class="hsm-search-title">' + escapeHtml(it.title) + '</span>' +
                                 '<span class="hsm-search-id">#' + it.id + '</span>' +
                               '</div>';
                      }).join('')
                    : '<div class="hsm-search-empty">Ничего не найдено</div>';
                $list.querySelectorAll('.hsm-search-item').forEach(function (el) {
                    el.onclick = function () {
                        var id = parseInt(el.getAttribute('data-id'), 10);
                        ajax('hs_mulligan_load', { id: id }, function (r) {
                            if (!r || !r.success) { alert('Не удалось загрузить'); return; }
                            close();
                            openModal(editor, r.data);
                        });
                    };
                });
            });
        }
        $search.oninput = function () {
            clearTimeout(t);
            t = setTimeout(function () { fetchList($search.value.trim()); }, 250);
        };
        fetchList(''); // первичный список
    }

    var DEFAULT_PROFILES = [
        { id: 'aggro',    label: 'Против агро' },
        { id: 'midrange', label: 'Против мидрейнджа' },
        { id: 'control',  label: 'Против контроля' }
    ];

    function openModal(editor, preload) {
        injectStyles();
        var isEdit = !!(preload && preload.id);
        var modal = document.createElement('div');
        modal.className = 'hsm-modal-overlay';
        modal.innerHTML =
            '<div class="hsm-modal">' +
              '<div class="hsm-modal-head">' +
                '<h2>' + (isEdit ? 'Редактировать муллиган #' + preload.id : 'Создать муллиган-тренажёр') + '</h2>' +
                '<button type="button" class="hsm-close" aria-label="Закрыть">&times;</button>' +
              '</div>' +
              '<div class="hsm-modal-body">' +
                '<label class="hsm-label">Название</label>' +
                '<input type="text" class="hsm-title" placeholder="Например: Токен Паладин">' +
                '<label class="hsm-label">Код колоды</label>' +
                '<textarea class="hsm-code" rows="3" placeholder="AAECAa0GBp..."></textarea>' +
                '<button type="button" class="hsm-decode">Декодировать колоду</button>' +
                '<div class="hsm-deck-info"></div>' +
                '<div class="hsm-profiles" hidden>' +
                  '<div class="hsm-tabs"></div>' +
                  '<div class="hsm-tab-controls">' +
                    '<input type="text" class="hsm-rename" placeholder="Название профиля">' +
                    '<button type="button" class="hsm-rename-btn">Переименовать</button>' +
                    '<button type="button" class="hsm-add-tab">+ Добавить профиль</button>' +
                    '<button type="button" class="hsm-del-tab">Удалить профиль</button>' +
                  '</div>' +
                  '<div class="hsm-cards-list"></div>' +
                '</div>' +
              '</div>' +
              '<div class="hsm-modal-foot">' +
                '<button type="button" class="hsm-cancel">Отмена</button>' +
                '<button type="button" class="hsm-save" disabled>' + (isEdit ? 'Сохранить изменения' : 'Создать и вставить') + '</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(modal);

        var $title    = modal.querySelector('.hsm-title');
        var $code     = modal.querySelector('.hsm-code');
        var $decode   = modal.querySelector('.hsm-decode');
        var $save     = modal.querySelector('.hsm-save');
        var $info     = modal.querySelector('.hsm-deck-info');
        var $profiles = modal.querySelector('.hsm-profiles');
        var $tabs     = modal.querySelector('.hsm-tabs');
        var $rename   = modal.querySelector('.hsm-rename');
        var $cards    = modal.querySelector('.hsm-cards-list');

        var state = {
            code:      '',
            cards:     [],   // [{dbfId, name, cost, image, count}]
            sideboards:[],   // [{ownerName, cards: [...]}]
            profiles:  [],   // [{id, label, cards: { dbfId: {keep, note} } }]
            active:    0,
            existingId: isEdit ? preload.id : 0
        };

        // Преднаполнение для режима редактирования
        if (isEdit) {
            $title.value = preload.title || '';
            $code.value  = preload.code || '';
        }

        function close() { modal.parentNode && modal.parentNode.removeChild(modal); }
        modal.querySelector('.hsm-close').onclick = close;
        modal.querySelector('.hsm-cancel').onclick = close;

        $decode.onclick = function () {
            var code = ($code.value || '').trim();
            if (!code) { alert('Введите код колоды'); return; }
            $info.textContent = 'Декодирование...';
            $cards.innerHTML = '';
            $save.disabled = true;
            $profiles.hidden = true;

            ajax('hs_mulligan_decode', { code: code }, function (resp) {
                if (!resp || !resp.success) {
                    $info.innerHTML = '<span class="hsm-err">' + (resp && resp.data && resp.data.message || 'Ошибка') + '</span>';
                    return;
                }
                state.code = code;
                state.cards = resp.data.cards || [];
                state.sideboards = resp.data.sideboards || [];

                if (isEdit && preload.profiles && preload.profiles.length) {
                    // Восстанавливаем сохранённые профили из preload (PHP load_profiles).
                    state.profiles = preload.profiles.map(function (p) {
                        var cards = {};
                        Object.keys(p.cards || {}).forEach(function (k) {
                            var v = p.cards[k] || {};
                            cards[k] = { keep: (v.keep | 0), note: v.note || '' };
                        });
                        return { id: p.id, label: p.label, cards: cards };
                    });
                } else {
                    state.profiles = DEFAULT_PROFILES.map(function (p) {
                        return { id: p.id, label: p.label, cards: {} };
                    });
                }
                state.active = 0;

                var sideStr = state.sideboards.length
                    ? ' &nbsp;·&nbsp; Sideboard: ' + state.sideboards.reduce(function (a, s) { return a + s.cards.length; }, 0) + ' карт'
                    : '';
                $info.innerHTML = '<strong>Класс:</strong> ' + escapeHtml(resp.data.hero) +
                                  ' &nbsp;·&nbsp; Карт: ' + state.cards.length + sideStr;
                $profiles.hidden = false;
                renderTabs();
                renderCards();
                $save.disabled = false;
            });
        };

        modal.querySelector('.hsm-add-tab').onclick = function () {
            var label = prompt('Название профиля (например: «Против Дх» или «Против контроль-варлока»):', '');
            if (!label) return;
            label = label.trim();
            if (!label) return;
            var id = 'p' + Date.now();
            state.profiles.push({ id: id, label: label, cards: {} });
            state.active = state.profiles.length - 1;
            renderTabs(); renderCards();
        };

        modal.querySelector('.hsm-del-tab').onclick = function () {
            if (state.profiles.length <= 1) { alert('Должен остаться хотя бы один профиль.'); return; }
            if (!confirm('Удалить профиль «' + state.profiles[state.active].label + '»?')) return;
            state.profiles.splice(state.active, 1);
            if (state.active >= state.profiles.length) state.active = state.profiles.length - 1;
            renderTabs(); renderCards();
        };

        modal.querySelector('.hsm-rename-btn').onclick = function () {
            var v = ($rename.value || '').trim();
            if (!v) return;
            state.profiles[state.active].label = v;
            renderTabs();
        };

        function renderTabs() {
            $tabs.innerHTML = state.profiles.map(function (p, i) {
                return '<button type="button" class="hsm-tab' + (i === state.active ? ' is-active' : '') +
                       '" data-i="' + i + '">' + escapeHtml(p.label) + '</button>';
            }).join('');
            $tabs.querySelectorAll('.hsm-tab').forEach(function (el) {
                el.onclick = function () {
                    state.active = parseInt(el.getAttribute('data-i'), 10);
                    renderTabs();
                    renderCards();
                };
            });
            $rename.value = state.profiles[state.active] ? state.profiles[state.active].label : '';
        }

        function renderCards() {
            var prof = state.profiles[state.active];
            if (!prof) { $cards.innerHTML = ''; return; }
            var html = '<p class="hsm-hint">Профиль <strong>«' + escapeHtml(prof.label) + '»</strong>: ' +
                       'клик по карте циклически переключает: <strong>не оставлять → оставлять 1 копию → оставлять обе копии</strong>. ' +
                       'Для карт, которых в колоде только 1, выбирайте 1. К каждой карте можно дописать пояснение.</p>';
            html += '<div class="hsm-grid">';
            state.cards.forEach(function (c) {
                var entry = prof.cards[c.dbfId] || {};
                var keep  = (entry.keep | 0);
                if (keep > c.count) keep = c.count; // нельзя оставлять больше, чем есть в колоде
                var note  = entry.note || '';
                var cls = keep === 0 ? '' : (keep === 2 ? ' is-keep is-keep-2' : ' is-keep');
                html += '<div class="hsm-card-block">';
                html +=   '<div class="hsm-card' + cls + '" data-dbf="' + c.dbfId + '" data-max="' + c.count + '">';
                if (c.image) html += '<img src="' + escapeAttr(c.image) + '" alt="" loading="lazy">';
                html +=     '<div class="hsm-card-meta"><span class="hsm-cost">' + (c.cost == null ? '–' : c.cost) +
                            '</span><span class="hsm-name">' + escapeHtml(c.name) + (c.count > 1 ? ' ×' + c.count : '') + '</span></div>';
                html +=     '<div class="hsm-keep-badge">' + (keep === 2 ? 'обе ×2' : '★ 1') + '</div>';
                html +=   '</div>';
                html +=   '<textarea class="hsm-note" data-dbf="' + c.dbfId + '" rows="2" ' +
                          'placeholder="Пояснение: почему берём / почему не берём">' + escapeHtml(note) + '</textarea>';
                html += '</div>';
            });
            html += '</div>';

            // Sideboard — информационно
            if (state.sideboards && state.sideboards.length) {
                html += '<div class="hsm-sideboards">';
                html += '<h4 class="hsm-sideboards-title">Sideboard <span>(не входит в стартовую руку, отображается информационно)</span></h4>';
                state.sideboards.forEach(function (sb) {
                    html += '<div class="hsm-sideboard-block">';
                    html += '<div class="hsm-sideboard-owner">' + (sb.ownerImage ? '<img src="' + escapeAttr(sb.ownerImage) + '" alt="">' : '') +
                            '<span>' + escapeHtml(sb.ownerName) + '</span></div>';
                    html += '<div class="hsm-sideboard-cards">';
                    sb.cards.forEach(function (c) {
                        html += '<div class="hsm-side-card">' +
                                  (c.image ? '<img src="' + escapeAttr(c.image) + '" alt="" loading="lazy">' : '') +
                                  '<div class="hsm-side-meta">' +
                                    '<span class="hsm-cost">' + (c.cost == null ? '–' : c.cost) + '</span>' +
                                    '<span>' + escapeHtml(c.name) + (c.count > 1 ? ' ×' + c.count : '') + '</span>' +
                                  '</div>' +
                                '</div>';
                    });
                    html += '</div>';
                    html += '</div>';
                });
                html += '</div>';
            }

            $cards.innerHTML = html;

            $cards.querySelectorAll('.hsm-card').forEach(function (el) {
                el.onclick = function () {
                    var dbf = parseInt(el.getAttribute('data-dbf'), 10);
                    var max = parseInt(el.getAttribute('data-max'), 10) || 1;
                    var p = state.profiles[state.active];
                    var e = p.cards[dbf] || { keep: 0, note: '' };
                    var next = ((e.keep | 0) + 1);
                    if (next > Math.min(2, max)) next = 0;
                    e.keep = next;
                    p.cards[dbf] = e;
                    el.classList.toggle('is-keep', next > 0);
                    el.classList.toggle('is-keep-2', next === 2);
                    var badge = el.querySelector('.hsm-keep-badge');
                    if (badge) badge.textContent = next === 2 ? 'обе ×2' : '★ 1';
                };
            });
            $cards.querySelectorAll('.hsm-note').forEach(function (el) {
                el.oninput = function () {
                    var dbf = parseInt(el.getAttribute('data-dbf'), 10);
                    var p = state.profiles[state.active];
                    var e = p.cards[dbf] || { keep: 0, note: '' };
                    e.note = el.value;
                    p.cards[dbf] = e;
                };
            });
        }

        $save.onclick = function () {
            var title = ($title.value || '').trim();
            if (!title) { alert('Введите название'); return; }
            // Чистим пустые записи
            var profiles = state.profiles.map(function (p) {
                var clean = {};
                Object.keys(p.cards).forEach(function (k) {
                    var e = p.cards[k];
                    if (!e) return;
                    var keep = (e.keep | 0);
                    var note = (e.note || '').trim();
                    if (keep === 0 && note === '') return;
                    clean[k] = { keep: keep, note: note };
                });
                return { id: p.id, label: p.label, cards: clean };
            });

            $save.disabled = true;
            var payload = {
                title: title,
                code:  state.code,
                profiles: JSON.stringify(profiles)
            };
            if (state.existingId) payload.post_id = state.existingId;

            ajax('hs_mulligan_create', payload, function (resp) {
                if (!resp || !resp.success) {
                    alert((resp && resp.data && resp.data.message) || 'Ошибка создания');
                    $save.disabled = false;
                    return;
                }
                if (resp.data.updated) {
                    alert('Изменения сохранены. Шорткод [hs_mulligan id="' + resp.data.id + '"] не изменился.');
                } else {
                    editor.insertContent('[hs_mulligan id="' + resp.data.id + '"]');
                }
                close();
            });
        };
    }

    function ajax(action, data, cb) {
        var cfg = window.hsMulliganAjax || {};
        var payload = 'action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(cfg.nonce || '');
        Object.keys(data).forEach(function (k) {
            payload += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
        });
        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.ajaxurl || '/wp-admin/admin-ajax.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                try { cb(JSON.parse(xhr.responseText)); }
                catch (e) { cb({ success: false, data: { message: 'Ошибка ответа сервера' } }); }
            }
        };
        xhr.send(payload);
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c];
        });
    }
    function escapeAttr(s) { return escapeHtml(s).replace(/'/g, '&#39;'); }

    function injectStyles() {
        if (document.getElementById('hsm-modal-styles')) return;
        var fontUrl = (window.hsMulliganAjax && hsMulliganAjax.fontUrl) || '';
        var css =
            (fontUrl ? "@font-face{font-family:'HSMulligan';src:url('" + fontUrl + "') format('opentype');font-weight:400 900;font-style:normal;font-display:swap;}" : '') +
            '.hsm-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:160000;display:flex;align-items:center;justify-content:center;font-family:\'HSMulligan\',Georgia,"Times New Roman",serif;}' +
            '.hsm-modal{background:#fff;width:min(960px,95vw);max-height:92vh;display:flex;flex-direction:column;border-radius:8px;overflow:hidden;font-family:inherit;}' +
            '.hsm-modal-head,.hsm-modal-foot{padding:12px 16px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;}' +
            '.hsm-modal-foot{border-top:1px solid #eee;border-bottom:none;gap:8px;justify-content:flex-end;}' +
            '.hsm-modal-head h2{margin:0;font-size:18px;}' +
            '.hsm-close{background:none;border:0;font-size:24px;cursor:pointer;line-height:1;}' +
            '.hsm-modal-body{padding:16px;overflow:auto;}' +
            '.hsm-label{display:block;font-weight:600;margin:8px 0 4px;}' +
            '.hsm-modal-body input[type=text],.hsm-modal-body textarea{width:100%;padding:8px;border:1px solid #ccd0d4;border-radius:4px;font-family:inherit;box-sizing:border-box;}' +
            '.hsm-modal-body textarea{font-family:inherit;}' +
            '.hsm-modal-body .hsm-code{font-family:monospace;}' +
            '.hsm-decode{margin-top:8px;padding:8px 14px;background:#2271b1;color:#fff;border:0;border-radius:4px;cursor:pointer;}' +
            '.hsm-deck-info{margin:12px 0;color:#444;}' +
            '.hsm-err{color:#b32d2e;}' +
            '.hsm-tabs{display:flex;flex-wrap:wrap;gap:4px;margin:8px 0 6px;border-bottom:2px solid #ccd0d4;}' +
            '.hsm-tab{background:#f0f0f1;border:1px solid #ccd0d4;border-bottom:0;border-radius:6px 6px 0 0;padding:6px 12px;cursor:pointer;font-weight:600;}' +
            '.hsm-tab.is-active{background:#2271b1;color:#fff;border-color:#2271b1;}' +
            '.hsm-tab-controls{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:6px 0 12px;}' +
            '.hsm-tab-controls input.hsm-rename{flex:1;min-width:160px;}' +
            '.hsm-tab-controls button{padding:6px 12px;border:1px solid #ccd0d4;background:#f6f7f7;border-radius:4px;cursor:pointer;}' +
            '.hsm-tab-controls .hsm-add-tab{background:#46b450;color:#fff;border-color:#46b450;}' +
            '.hsm-tab-controls .hsm-del-tab{background:#fdecea;color:#b32d2e;border-color:#f5b9b6;}' +
            '.hsm-hint{color:#555;font-size:13px;margin:6px 0 10px;}' +
            '.hsm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;}' +
            '.hsm-card-block{display:flex;flex-direction:column;gap:6px;}' +
            '.hsm-card{position:relative;border:2px solid transparent;border-radius:6px;padding:4px;cursor:pointer;background:#f6f7f7;transition:.15s;}' +
            '.hsm-card:hover{background:#eef0f1;}' +
            '.hsm-card.is-keep{border-color:#46b450;background:#eaf7eb;}' +
            '.hsm-card.is-keep.is-keep-2{border-color:#d39d20;background:#fff4d6;}' +
            '.hsm-card.is-keep.is-keep-2 .hsm-keep-badge{background:#d39d20;}' +
            '.hsm-card img{width:100%;height:auto;display:block;border-radius:4px;}' +
            '.hsm-card-meta{display:flex;gap:6px;align-items:center;margin-top:4px;font-size:12px;}' +
            '.hsm-cost{background:#2271b1;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;font-weight:700;}' +
            '.hsm-name{flex:1;line-height:1.2;}' +
            '.hsm-keep-badge{position:absolute;top:6px;right:6px;background:#46b450;color:#fff;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;display:none;}' +
            '.hsm-card.is-keep .hsm-keep-badge{display:block;}' +
            '.hsm-note{font-size:12px;min-height:48px;}' +
            '.hsm-cancel,.hsm-save{padding:8px 14px;border-radius:4px;border:0;cursor:pointer;}' +
            '.hsm-cancel{background:#f0f0f1;}' +
            '.hsm-save{background:#46b450;color:#fff;}' +
            '.hsm-save[disabled]{opacity:.5;cursor:not-allowed;}' +
            // Поиск существующих муллиганов
            '.hsm-search{width:100%;padding:10px;font-size:14px;border:1px solid #ccd0d4;border-radius:4px;margin-bottom:10px;box-sizing:border-box;}' +
            '.hsm-search-results{max-height:300px;overflow:auto;border:1px solid #eee;border-radius:4px;}' +
            '.hsm-search-item{padding:10px 12px;border-bottom:1px solid #eee;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:10px;}' +
            '.hsm-search-item:hover{background:#f0f7ff;}' +
            '.hsm-search-item:last-child{border-bottom:0;}' +
            '.hsm-search-title{font-weight:600;}' +
            '.hsm-search-id{color:#888;font-size:12px;font-family:monospace;}' +
            '.hsm-search-empty{padding:20px;text-align:center;color:#888;}' +
            // Sideboard блок
            '.hsm-sideboards{margin-top:18px;padding-top:14px;border-top:2px dashed #ccd0d4;}' +
            '.hsm-sideboards-title{margin:0 0 10px;font-size:15px;font-weight:600;}' +
            '.hsm-sideboards-title span{font-weight:400;color:#888;font-size:12px;}' +
            '.hsm-sideboard-block{margin-bottom:14px;padding:10px;background:#fafafa;border:1px solid #e6e7e8;border-radius:6px;}' +
            '.hsm-sideboard-owner{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-weight:600;}' +
            '.hsm-sideboard-owner img{width:32px;height:32px;object-fit:contain;}' +
            '.hsm-sideboard-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:6px;}' +
            '.hsm-side-card{padding:4px;border:1px solid #e6e7e8;border-radius:4px;background:#fff;}' +
            '.hsm-side-card img{width:100%;display:block;border-radius:3px;opacity:.85;}' +
            '.hsm-side-meta{display:flex;gap:6px;align-items:center;margin-top:4px;font-size:11px;}';
        var s = document.createElement('style');
        s.id = 'hsm-modal-styles';
        s.textContent = css;
        document.head.appendChild(s);
    }
})();
