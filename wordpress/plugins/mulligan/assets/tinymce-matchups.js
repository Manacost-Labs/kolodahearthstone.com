(function () {
    tinymce.PluginManager.add('hs_matchups', function (editor) {
        editor.addButton('hs_matchups', {
            title: 'Вставить матчапы',
            text: 'Матчапы',
            icon: false,
            onclick: function () { openModal(editor); }
        });
    });

    function openModal(editor) {
        injectStyles();
        var classes = (window.hsMulliganAjax && hsMulliganAjax.classes) || [];

        var modal = document.createElement('div');
        modal.className = 'hsmt-modal-overlay';
        modal.innerHTML =
            '<div class="hsmt-modal">' +
              '<div class="hsmt-modal-head">' +
                '<h2>Матчапы и винрейты</h2>' +
                '<button type="button" class="hsmt-close" aria-label="Закрыть">&times;</button>' +
              '</div>' +
              '<div class="hsmt-modal-body">' +
                '<label class="hsmt-label">Название блока</label>' +
                '<input type="text" class="hsmt-title" placeholder="Например: Матчапы СПД-Мага">' +
                '<p class="hsmt-hint">Заполните винрейт против каждого класса (0–100%). Незаполненные строки в инфографику не попадут. Можно добавить кастомные матчапы против конкретных колод.</p>' +
                '<label class="hsmt-label">Вступительный текст (вставится перед инфографикой, можно редактировать в самой статье)</label>' +
                '<textarea class="hsmt-intro" rows="3" placeholder="Например: «Текущая мета богата на агро-колоды. Ниже — наши винрейты против ключевых классов с пояснениями.»"></textarea>' +
                '<div class="hsmt-rows"></div>' +
                '<button type="button" class="hsmt-add-custom">+ Добавить матчап против конкретной колоды</button>' +
                '<label class="hsmt-label">Заключительный текст (вставится после инфографики)</label>' +
                '<textarea class="hsmt-outro" rows="3" placeholder="Например: «Если вы постоянно встречаете контроль-колоды — рекомендую брать SMORC-тулзы вместо тех-агрессии.»"></textarea>' +
              '</div>' +
              '<div class="hsmt-modal-foot">' +
                '<button type="button" class="hsmt-cancel">Отмена</button>' +
                '<button type="button" class="hsmt-save">Создать и вставить</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(modal);

        var $title = modal.querySelector('.hsmt-title');
        var $intro = modal.querySelector('.hsmt-intro');
        var $outro = modal.querySelector('.hsmt-outro');
        var $rows  = modal.querySelector('.hsmt-rows');
        var customCounter = 0;

        modal.querySelector('.hsmt-close').onclick = close;
        modal.querySelector('.hsmt-cancel').onclick = close;
        function close() { modal.parentNode && modal.parentNode.removeChild(modal); }

        function buildClassRow(cls) {
            return rowHtml({
                type: 'class',
                slug: cls.slug,
                icon: cls.icon,
                label: cls.label,
                editableLabel: false
            });
        }
        function buildCustomRow() {
            customCounter++;
            return rowHtml({
                type: 'custom',
                slug: '',
                icon: '',
                label: 'Кастомный матчап #' + customCounter,
                editableLabel: true
            });
        }
        function rowHtml(opts) {
            var iconHtml = opts.icon
                ? '<img class="hsmt-row-icon" src="' + escapeAttr(opts.icon) + '" alt="">'
                : '<span class="hsmt-row-icon hsmt-row-icon-empty">★</span>';
            var labelHtml = opts.editableLabel
                ? '<input type="text" class="hsmt-label-input" data-field="label" value="' + escapeAttr(opts.label) + '">'
                : '<span class="hsmt-row-label">' + escapeHtml(opts.label) + '</span>';
            var classSelect = '';
            if (opts.type === 'custom') {
                classSelect = '<select class="hsmt-class-select" data-field="class"><option value="">— иконка —</option>';
                classes.forEach(function (c) {
                    classSelect += '<option value="' + escapeAttr(c.slug) + '">' + escapeHtml(c.label) + '</option>';
                });
                classSelect += '</select>';
            }
            var removeBtn = opts.editableLabel
                ? '<button type="button" class="hsmt-remove" title="Удалить">×</button>'
                : '';
            return '<div class="hsmt-row" data-type="' + opts.type + '" data-slug="' + escapeAttr(opts.slug) + '">' +
                     iconHtml +
                     '<div class="hsmt-row-main">' +
                       '<div class="hsmt-row-top">' + labelHtml + classSelect + removeBtn + '</div>' +
                       '<div class="hsmt-row-bot">' +
                         '<input type="number" class="hsmt-wr" data-field="winrate" min="0" max="100" placeholder="Винрейт %">' +
                         '<input type="text" class="hsmt-comment" data-field="comment" placeholder="Краткий комментарий (необязательно)">' +
                       '</div>' +
                     '</div>' +
                   '</div>';
        }

        // Рендер всех 11 классов
        var html = classes.map(buildClassRow).join('');
        $rows.innerHTML = html;
        bindRows();

        modal.querySelector('.hsmt-add-custom').onclick = function () {
            var div = document.createElement('div');
            div.innerHTML = buildCustomRow();
            var newRow = div.firstChild;
            $rows.appendChild(newRow);
            bindRow(newRow);
            newRow.querySelector('.hsmt-label-input').focus();
        };

        function bindRows() {
            $rows.querySelectorAll('.hsmt-row').forEach(bindRow);
        }
        function bindRow(row) {
            var rm = row.querySelector('.hsmt-remove');
            if (rm) rm.onclick = function () { row.parentNode.removeChild(row); };

            var sel = row.querySelector('.hsmt-class-select');
            if (sel) {
                sel.onchange = function () {
                    row.setAttribute('data-slug', sel.value);
                    var icon = row.querySelector('.hsmt-row-icon');
                    var c = classes.filter(function (x) { return x.slug === sel.value; })[0];
                    if (c) {
                        var img = document.createElement('img');
                        img.className = 'hsmt-row-icon';
                        img.src = c.icon;
                        img.alt = '';
                        icon.replaceWith(img);
                    }
                };
            }
        }

        modal.querySelector('.hsmt-save').onclick = function () {
            var title = ($title.value || '').trim();
            if (!title) { alert('Введите название блока'); return; }

            var inner = '';
            $rows.querySelectorAll('.hsmt-row').forEach(function (row) {
                var type = row.getAttribute('data-type');
                var slug = row.getAttribute('data-slug') || '';
                var wr   = row.querySelector('[data-field="winrate"]').value.trim();
                if (wr === '') return;
                var winrate = parseInt(wr, 10);
                if (isNaN(winrate)) return;
                var labelEl = row.querySelector('[data-field="label"]') || row.querySelector('.hsmt-row-label');
                var label = labelEl
                    ? (labelEl.value !== undefined ? labelEl.value : labelEl.textContent).trim()
                    : '';
                var comment = row.querySelector('[data-field="comment"]').value.trim();

                var atts = ' wr="' + winrate + '"';
                if (slug)             atts += ' class="' + escapeAttr(slug) + '"';
                if (label)            atts += ' label="' + escapeAttr(label) + '"';
                if (type === 'custom') atts += ' type="custom"';
                // Комментарий не экранируем для HTML — PHP-рендер сделает esc_html на выводе.
                // Удаляем лишь символы, ломающие закрытие шорткода.
                var safeComment = comment.replace(/\[\s*\/?\s*hs_matchup/gi, '');
                inner += '\n[hs_matchup' + atts + ']' + safeComment + '[/hs_matchup]';
            });

            if (!inner) { alert('Заполните винрейт хотя бы для одного матчапа'); return; }

            var heading = '<h3>' + escapeHtml(title) + '</h3>';
            var intro   = ($intro.value || '').trim();
            var outro   = ($outro.value || '').trim();
            var introHtml = intro ? '<p>' + escapeHtml(intro).replace(/\n/g, '<br>') + '</p>' : '';
            var outroHtml = outro ? '<p>' + escapeHtml(outro).replace(/\n/g, '<br>') + '</p>' : '';

            var wrapped = '[hs_matchups]' + inner + '\n[/hs_matchups]';
            editor.insertContent(heading + introHtml + wrapped + outroHtml);
            close();
        };
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c];
        });
    }
    function escapeAttr(s) { return escapeHtml(s).replace(/'/g, '&#39;'); }

    function injectStyles() {
        if (document.getElementById('hsmt-modal-styles')) return;
        var fontUrl = (window.hsMulliganAjax && hsMulliganAjax.fontUrl) || '';
        var css =
            (fontUrl ? "@font-face{font-family:'HSMulligan';src:url('" + fontUrl + "') format('opentype');font-weight:400 900;font-style:normal;font-display:swap;}" : '') +
            '.hsmt-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:160000;display:flex;align-items:center;justify-content:center;font-family:\'HSMulligan\',Georgia,"Times New Roman",serif;}' +
            '.hsmt-modal{background:#fff;width:min(900px,95vw);max-height:92vh;display:flex;flex-direction:column;border-radius:8px;overflow:hidden;font-family:inherit;}' +
            '.hsmt-modal-head,.hsmt-modal-foot{padding:12px 16px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;}' +
            '.hsmt-modal-foot{border-top:1px solid #eee;border-bottom:none;gap:8px;justify-content:flex-end;}' +
            '.hsmt-modal-head h2{margin:0;font-size:18px;}' +
            '.hsmt-close{background:none;border:0;font-size:24px;cursor:pointer;line-height:1;}' +
            '.hsmt-modal-body{padding:16px;overflow:auto;}' +
            '.hsmt-label{display:block;font-weight:600;margin:8px 0 4px;}' +
            '.hsmt-modal-body input[type=text],.hsmt-modal-body input[type=number]{padding:8px;border:1px solid #ccd0d4;border-radius:4px;font-family:inherit;box-sizing:border-box;}' +
            '.hsmt-title{width:100%;}' +
            '.hsmt-hint{color:#666;font-size:13px;margin:6px 0 12px;}' +
            '.hsmt-rows{display:flex;flex-direction:column;gap:8px;margin-bottom:10px;}' +
            '.hsmt-row{display:flex;gap:10px;align-items:center;padding:8px;border:1px solid #e6e7e8;border-radius:6px;background:#fafafa;}' +
            '.hsmt-row-icon{width:36px;height:36px;flex:0 0 36px;object-fit:contain;}' +
            '.hsmt-row-icon-empty{display:inline-flex;align-items:center;justify-content:center;background:#e0e0e0;border-radius:50%;color:#777;font-size:18px;}' +
            '.hsmt-row-main{flex:1;display:flex;flex-direction:column;gap:6px;min-width:0;}' +
            '.hsmt-row-top{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}' +
            '.hsmt-row-label{font-weight:700;flex:1;min-width:120px;}' +
            '.hsmt-label-input{flex:1;min-width:120px;}' +
            '.hsmt-class-select{padding:6px;border:1px solid #ccd0d4;border-radius:4px;font-family:inherit;}' +
            '.hsmt-remove{background:#fdecea;color:#b32d2e;border:1px solid #f5b9b6;border-radius:4px;width:28px;height:28px;cursor:pointer;font-size:18px;line-height:1;}' +
            '.hsmt-row-bot{display:flex;gap:6px;align-items:center;}' +
            '.hsmt-wr{width:120px;flex:0 0 120px;}' +
            '.hsmt-comment{flex:1;}' +
            '.hsmt-add-custom{padding:8px 14px;background:#46b450;color:#fff;border:0;border-radius:4px;cursor:pointer;font-weight:600;}' +
            '.hsmt-cancel,.hsmt-save{padding:8px 14px;border-radius:4px;border:0;cursor:pointer;}' +
            '.hsmt-cancel{background:#f0f0f1;}' +
            '.hsmt-save{background:#2271b1;color:#fff;font-weight:600;}' +
            '.hsmt-save[disabled]{opacity:.5;cursor:not-allowed;}' +
            '@media(max-width:600px){' +
              '.hsmt-row-bot{flex-direction:column;align-items:stretch;}' +
              '.hsmt-wr{width:100%;}' +
            '}';
        var s = document.createElement('style');
        s.id = 'hsmt-modal-styles';
        s.textContent = css;
        document.head.appendChild(s);
    }
})();
