(function () {
    tinymce.PluginManager.add('hs_decklist', function (editor) {
        editor.addButton('hs_decklist', {
            title: 'Вставить список колоды',
            text: 'Список колоды',
            icon: false,
            onclick: function () { openModal(editor); }
        });
    });

    function openModal(editor) {
        injectStyles();
        var modal = document.createElement('div');
        modal.className = 'hsdl-modal-overlay';
        modal.innerHTML =
            '<div class="hsdl-modal">' +
              '<div class="hsdl-modal-head">' +
                '<h2>Список колоды</h2>' +
                '<button type="button" class="hsdl-close" aria-label="Закрыть">&times;</button>' +
              '</div>' +
              '<div class="hsdl-modal-body">' +
                '<label class="hsdl-label">Код колоды <span class="hsdl-req">*</span></label>' +
                '<textarea class="hsdl-code" rows="3" placeholder="AAECAa0GBp..."></textarea>' +

                '<label class="hsdl-label">Дата (необязательно)</label>' +
                '<input type="text" class="hsdl-date" placeholder="22 ноября 2026">' +

                '<label class="hsdl-label">Стоимость в пыли (необязательно)</label>' +
                '<input type="text" class="hsdl-dust" placeholder="8080">' +

                '<label class="hsdl-label">Название (необязательно)</label>' +
                '<input type="text" class="hsdl-title-in" placeholder="Например: Драгон Паладин">' +

                '<p class="hsdl-hint">После вставки шорткод можно редактировать прямо в редакторе — поменять дату, пыль, название.</p>' +
              '</div>' +
              '<div class="hsdl-modal-foot">' +
                '<button type="button" class="hsdl-cancel">Отмена</button>' +
                '<button type="button" class="hsdl-save">Вставить</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(modal);

        var $code  = modal.querySelector('.hsdl-code');
        var $date  = modal.querySelector('.hsdl-date');
        var $dust  = modal.querySelector('.hsdl-dust');
        var $title = modal.querySelector('.hsdl-title-in');

        function close() { modal.parentNode && modal.parentNode.removeChild(modal); }
        modal.querySelector('.hsdl-close').onclick = close;
        modal.querySelector('.hsdl-cancel').onclick = close;

        // Автоподстановка сегодняшней даты в человеческом формате
        try {
            var now = new Date();
            var months = ['января','февраля','марта','апреля','мая','июня',
                          'июля','августа','сентября','октября','ноября','декабря'];
            $date.placeholder = now.getDate() + ' ' + months[now.getMonth()] + ' ' + now.getFullYear();
        } catch (e) {}

        modal.querySelector('.hsdl-save').onclick = function () {
            var code  = ($code.value  || '').trim();
            var date  = ($date.value  || '').trim();
            var dust  = ($dust.value  || '').trim();
            var title = ($title.value || '').trim();
            if (!code) { alert('Введите код колоды'); return; }

            var atts = ' code="' + escAttr(code) + '"';
            if (date)  atts += ' date="' + escAttr(date) + '"';
            if (dust)  atts += ' dust="' + escAttr(dust) + '"';
            if (title) atts += ' title="' + escAttr(title) + '"';

            editor.insertContent('[hs_decklist' + atts + ']');
            close();
        };
    }

    function escAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\]/g, '&#93;');
    }

    function injectStyles() {
        if (document.getElementById('hsdl-modal-styles')) return;
        var fontUrl = (window.hsMulliganAjax && hsMulliganAjax.fontUrl) || '';
        var css =
            (fontUrl ? "@font-face{font-family:'HSMulligan';src:url('" + fontUrl + "') format('opentype');font-weight:400 900;font-style:normal;font-display:swap;}" : '') +
            ".hsdl-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:160000;display:flex;align-items:center;justify-content:center;font-family:'HSMulligan',Georgia,\"Times New Roman\",serif;}" +
            '.hsdl-modal{background:#fff;width:min(560px,95vw);max-height:92vh;display:flex;flex-direction:column;border-radius:8px;overflow:hidden;font-family:inherit;}' +
            '.hsdl-modal-head,.hsdl-modal-foot{padding:12px 16px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;}' +
            '.hsdl-modal-foot{border-top:1px solid #eee;border-bottom:none;gap:8px;justify-content:flex-end;}' +
            '.hsdl-modal-head h2{margin:0;font-size:18px;}' +
            '.hsdl-close{background:none;border:0;font-size:24px;cursor:pointer;line-height:1;}' +
            '.hsdl-modal-body{padding:16px;overflow:auto;}' +
            '.hsdl-label{display:block;font-weight:600;margin:10px 0 4px;}' +
            '.hsdl-req{color:#b32d2e;}' +
            '.hsdl-modal-body input[type=text],.hsdl-modal-body textarea{width:100%;padding:8px;border:1px solid #ccd0d4;border-radius:4px;font-family:inherit;box-sizing:border-box;}' +
            '.hsdl-modal-body .hsdl-code{font-family:monospace;}' +
            '.hsdl-hint{color:#666;font-size:12px;margin:14px 0 0;}' +
            '.hsdl-cancel,.hsdl-save{padding:8px 14px;border-radius:4px;border:0;cursor:pointer;font-weight:600;}' +
            '.hsdl-cancel{background:#f0f0f1;}' +
            '.hsdl-save{background:#2271b1;color:#fff;}';
        var s = document.createElement('style');
        s.id = 'hsdl-modal-styles';
        s.textContent = css;
        document.head.appendChild(s);
    }
})();
