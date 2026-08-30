(function() {
    tinymce.PluginManager.add('hs_deck', function(editor, url) {
        var selectedDeckId = null;
        var searchTimeout = null;
        
        editor.addButton('hs_deck', {
            title: 'Вставить колоду',
            text: 'Колоды',
            icon: false,
            type: 'menubutton',
            menu: [
                {
                    text: 'Вставить существующую колоду',
                    onclick: function() {
                        editor.execCommand('hs_insert_deck');
                    }
                },
                {
                    text: 'Создать новую колоду',
                    onclick: function() {
                        editor.execCommand('hs_create_deck');
                    }
                },
                {
                    text: 'Привязать код к выделенному тексту',
                    onclick: function() {
                        editor.execCommand('hs_create_inline_deck_link');
                    }
                }
            ]
        });

        editor.addButton('hs_inline_deck_link', {
            title: 'Картинка колоды по коду',
            text: false,
            icon: 'image',
            onclick: function() {
                editor.execCommand('hs_create_inline_deck_link');
            }
        });

        // Создать изображение через kolodahs.ru, сохранить его в медиатеку
        // и привязать к выделенному слову или фразе.
        editor.addCommand('hs_create_inline_deck_link', function() {
            var selectedHtml = editor.selection.getContent({ format: 'html' });
            var selectedText = editor.selection.getContent({ format: 'text' }).trim();

            if (!selectedText) {
                editor.windowManager.alert('Сначала выделите слово или фразу, к которой нужно привязать колоду.');
                return;
            }

            var bookmark = editor.selection.getBookmark(2, true);
            var pollTimer = null;
            var pollAttempts = 0;
            var isBusy = false;
            var isClosed = false;
            var modal = null;

            function escapeInlineHtml(text) {
                var node = document.createElement('div');
                node.textContent = text || '';
                return node.innerHTML;
            }

            function setBusy(busy, message, isError) {
                isBusy = busy;
                if (!modal || isClosed) return;

                var root = modal.getEl();
                var status = root ? root.querySelector('#hs-inline-deck-status') : null;
                var submit = root ? root.querySelector('.mce-primary') : null;

                if (status) {
                    status.textContent = message || '';
                    status.style.color = isError ? '#b32d2e' : '#50575e';
                }
                if (submit) {
                    submit.disabled = !!busy;
                    submit.setAttribute('aria-busy', busy ? 'true' : 'false');
                }
            }

            function responseMessage(xhr, fallback) {
                var payload = xhr && xhr.responseJSON;
                if (payload && payload.data && payload.data.message) {
                    return payload.data.message;
                }
                return fallback;
            }

            function sendRequest(action, code, jobHash, onSuccess) {
                if (typeof jQuery === 'undefined') {
                    setBusy(false, 'Не удалось загрузить системный модуль AJAX. Обновите страницу.', true);
                    return;
                }

                var config = typeof hsDeckAjax !== 'undefined' ? hsDeckAjax : {};
                jQuery.ajax({
                    url: config.ajaxurl || '/wp-admin/admin-ajax.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: action,
                        nonce: config.nonce || '',
                        post_id: config.postId || 0,
                        code: code,
                        selected_html: selectedHtml,
                        job_hash: jobHash || ''
                    }
                }).done(function(response) {
                    if (!response || !response.success || !response.data) {
                        setBusy(false, (response && response.data && response.data.message) || 'Не удалось создать изображение.', true);
                        return;
                    }
                    onSuccess(response.data);
                }).fail(function(xhr) {
                    setBusy(false, responseMessage(xhr, 'Сервис временно недоступен. Попробуйте ещё раз.'), true);
                });
            }

            function insertReadyShortcode(data) {
                if (!data.shortcode) {
                    setBusy(false, 'Сервер не вернул готовую вставку.', true);
                    return;
                }

                if (pollTimer) {
                    window.clearTimeout(pollTimer);
                }
                editor.focus();
                editor.selection.moveToBookmark(bookmark);
                editor.insertContent(data.shortcode);
                editor.windowManager.close();
            }

            function handleJob(data, code) {
                if (data.status === 'ready') {
                    insertReadyShortcode(data);
                    return;
                }

                if (data.status !== 'pending' || !data.job_hash) {
                    setBusy(false, data.message || 'Не удалось создать изображение.', true);
                    return;
                }

                pollAttempts += 1;
                if (pollAttempts > 60) {
                    setBusy(false, 'Генерация заняла слишком много времени. Попробуйте повторить через минуту.', true);
                    return;
                }

                setBusy(true, 'Создаём изображение и сохраняем его в медиатеку…', false);
                pollTimer = window.setTimeout(function() {
                    sendRequest('hs_inline_deck_finish', code, data.job_hash, function(nextData) {
                        handleJob(nextData, code);
                    });
                }, 1500);
            }

            modal = editor.windowManager.open({
                title: 'Привязать колоду к выделенному тексту',
                body: [
                    {
                        type: 'container',
                        html: '<div class="hs-inline-deck-selection" style="box-sizing:border-box;margin:0 0 12px;padding:10px 12px;border-left:4px solid #8b5a3c;background:#f6f7f7;color:#1d2327;"><strong>Выделено:</strong> ' +
                            escapeInlineHtml(selectedText) + '</div>'
                    },
                    {
                        type: 'textbox',
                        name: 'code',
                        label: 'Код колоды',
                        multiline: true,
                        minHeight: 72,
                        value: '',
                        placeholder: 'Вставьте код колоды из Hearthstone'
                    },
                    {
                        type: 'container',
                        html: '<div id="hs-inline-deck-status" role="status" aria-live="polite" style="box-sizing:border-box;margin-top:12px;min-height:22px;color:#50575e;">' +
                            'Изображение будет создано один раз и сохранено в медиатеку WordPress.' +
                            '</div>'
                    }
                ],
                width: 520,
                height: 300,
                onsubmit: function(e) {
                    if (e && typeof e.preventDefault === 'function') {
                        e.preventDefault();
                    }
                    if (isBusy) return false;

                    var code = (e.data.code || '').replace(/\s+/g, '');
                    if (!code) {
                        setBusy(false, 'Вставьте код колоды.', true);
                        return false;
                    }

                    pollAttempts = 0;
                    setBusy(true, 'Проверяем код и запускаем генерацию…', false);
                    sendRequest('hs_inline_deck_start', code, '', function(data) {
                        handleJob(data, code);
                    });
                    return false;
                },
                onclose: function() {
                    isClosed = true;
                    if (pollTimer) {
                        window.clearTimeout(pollTimer);
                    }
                }
            });
        });
        
        // Команда для создания новой колоды
        editor.addCommand('hs_create_deck', function() {
            var selectedImageId = null;
            var selectedImageUrl = '';
            
            editor.windowManager.open({
                title: 'Создать новую колоду',
                body: [
                    {
                        type: 'textbox',
                        name: 'title',
                        label: 'Название колоды',
                        value: '',
                        placeholder: 'Введите название колоды'
                    },
                    {
                        type: 'textbox',
                        name: 'code',
                        label: 'Код колоды',
                        multiline: true,
                        minHeight: 100,
                        value: '',
                        placeholder: 'Вставьте код колоды из Hearthstone'
                    },
                    {
                        type: 'container',
                        name: 'image_container',
                        html: '<div style="margin:10px 0;box-sizing:border-box;">' +
                              '<label style="display:block;margin-bottom:8px;font-weight:600;box-sizing:border-box;">Изображение колоды (необязательно):</label>' +
                              '<div id="hs-deck-image-preview" style="margin-bottom:10px;display:none;box-sizing:border-box;">' +
                              '<img id="hs-deck-image-preview-img" src="" style="max-width:200px;max-height:150px;border:2px solid #ddd;border-radius:5px;padding:5px;background:#fff;box-sizing:border-box;display:block;">' +
                              '<button type="button" id="hs-deck-remove-image" style="display:block;margin-top:5px;padding:5px 10px;background:#dc3232;color:#fff;border:none;border-radius:3px;cursor:pointer;font-size:12px;box-sizing:border-box;width:auto;">Удалить изображение</button>' +
                              '</div>' +
                              '<button type="button" id="hs-deck-upload-image" style="padding:10px 20px;background:#0073aa;color:#fff;border:none;border-radius:5px;cursor:pointer;font-weight:bold;box-sizing:border-box;width:auto;">📷 Выбрать изображение</button>' +
                              '</div>'
                    }
                ],
                width: 700,
                height: 600,
                onopen: function() {
                    var self = this;
                    var bodyEl = self.getEl();
                    
                    if (!bodyEl) return;
                    
                    var uploadBtn = bodyEl.querySelector('#hs-deck-upload-image');
                    var removeBtn = bodyEl.querySelector('#hs-deck-remove-image');
                    var previewDiv = bodyEl.querySelector('#hs-deck-image-preview');
                    var previewImg = bodyEl.querySelector('#hs-deck-image-preview-img');
                    
                    // Загрузка изображения через медиа-библиотеку
                    if (uploadBtn && typeof wp !== 'undefined' && wp.media) {
                        uploadBtn.onclick = function(e) {
                            e.preventDefault();
                            
                            var frame = wp.media({
                                title: 'Выберите изображение колоды',
                                button: {
                                    text: 'Использовать это изображение'
                                },
                                multiple: false,
                                library: {
                                    type: 'image'
                                }
                            });
                            
                            frame.on('select', function() {
                                var attachment = frame.state().get('selection').first().toJSON();
                                selectedImageId = attachment.id;
                                selectedImageUrl = attachment.url;
                                
                                if (previewImg) {
                                    previewImg.src = selectedImageUrl;
                                }
                                if (previewDiv) {
                                    previewDiv.style.display = 'block';
                                }
                            });
                            
                            frame.open();
                        };
                    }
                    
                    // Удаление изображения
                    if (removeBtn) {
                        removeBtn.onclick = function(e) {
                            e.preventDefault();
                            selectedImageId = null;
                            selectedImageUrl = '';
                            if (previewDiv) {
                                previewDiv.style.display = 'none';
                            }
                            if (previewImg) {
                                previewImg.src = '';
                            }
                        };
                    }
                },
                onsubmit: function(e) {
                    var title = (e.data.title || '').trim();
                    var code = (e.data.code || '').trim();
                    var imageUrl = selectedImageUrl || '';
                    
                    if (!title) {
                        alert('Пожалуйста, введите название колоды');
                        return;
                    }
                    
                    if (!code) {
                        alert('Пожалуйста, введите код колоды');
                        return;
                    }
                    
                    var ajaxurl = (typeof ajaxurl !== 'undefined' && ajaxurl) ? ajaxurl : 
                                  (typeof hsDeckAjax !== 'undefined' && hsDeckAjax.ajaxurl) ? hsDeckAjax.ajaxurl : 
                                  '/wp-admin/admin-ajax.php';
                    var nonce = (typeof hsDeckAjax !== 'undefined' && hsDeckAjax.nonce) ? hsDeckAjax.nonce : '';
                    
                    if (!nonce) {
                        alert('Ошибка: не удалось получить токен безопасности. Обновите страницу.');
                        return;
                    }
                    
                    var data = {
                        action: 'hs_create_deck',
                        title: title,
                        code: code,
                        image_url: imageUrl,
                        image_id: selectedImageId || '',
                        nonce: nonce
                    };
                    
                    if (typeof jQuery !== 'undefined') {
                        jQuery.post(ajaxurl, data, function(response) {
                            if (response.success) {
                                editor.insertContent('[hs_deck id="' + response.data.deck_id + '"]');
                                editor.windowManager.close();
                            } else {
                                alert('Ошибка: ' + (response.data.message || 'Не удалось создать колоду'));
                            }
                        }).fail(function() {
                            alert('Ошибка при создании колоды');
                        });
                    } else {
                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', ajaxurl, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onreadystatechange = function() {
                            if (xhr.readyState === 4 && xhr.status === 200) {
                                try {
                                    var response = JSON.parse(xhr.responseText);
                                    if (response.success) {
                                        editor.insertContent('[hs_deck id="' + response.data.deck_id + '"]');
                                        editor.windowManager.close();
                                    } else {
                                        alert('Ошибка: ' + (response.data.message || 'Не удалось создать колоду'));
                                    }
                                } catch(e) {
                                    alert('Ошибка при обработке ответа');
                                }
                            }
                        };
                        var formData = Object.keys(data).map(function(k) {
                            return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
                        }).join('&');
                        xhr.send(formData);
                    }
                }
            });
        });
        
        // Команда для вставки существующей колоды с поиском
        editor.addCommand('hs_insert_deck', function() {
            selectedDeckId = null;
            
            var modal = editor.windowManager.open({
                title: 'Вставить колоду',
                body: [
                    {
                        type: 'textbox',
                        name: 'search',
                        label: 'Поиск колоды',
                        value: '',
                        placeholder: 'Начните вводить название колоды...'
                    },
                    {
                        type: 'container',
                        name: 'results_container',
                        html: '<div id="hs-deck-results" style="max-height:300px;overflow-y:auto;margin:10px 0;border:1px solid #ddd;border-radius:4px;display:none;background:#fff;box-sizing:border-box;"></div>'
                    },
                    {
                        type: 'container',
                        html: '<p style="margin:10px 0;color:#666;font-size:12px;box-sizing:border-box;">Начните вводить название колоды. Результаты отсортированы от новых к старым.</p>'
                    }
                ],
                width: 650,
                height: 600,
                onsubmit: function(e) {
                    if (!selectedDeckId) {
                        alert('Пожалуйста, выберите колоду из списка результатов');
                        return;
                    }
                    editor.insertContent('[hs_deck id="' + selectedDeckId + '"]');
                },
                onopen: function() {
                    var self = this;
                    var searchCtrl = self.find('#search')[0];
                    var resultsDiv = null;
                    
                    if (searchCtrl) {
                        // Получаем контейнер результатов
                        setTimeout(function() {
                            var bodyEl = self.getEl();
                            if (bodyEl) {
                                if (typeof jQuery !== 'undefined') {
                                    resultsDiv = jQuery(bodyEl).find('#hs-deck-results')[0];
                                } else {
                                    resultsDiv = bodyEl.querySelector('#hs-deck-results');
                                }
                            }
                            
                            if (searchCtrl && resultsDiv) {
                                searchCtrl.on('keyup', function() {
                                    clearTimeout(searchTimeout);
                                    var query = (this.value() || '').trim();
                                    
                                    if (query.length < 2) {
                                        if (resultsDiv) {
                                            if (typeof jQuery !== 'undefined') {
                                                jQuery(resultsDiv).hide();
                                            } else {
                                                resultsDiv.style.display = 'none';
                                            }
                                        }
                                        selectedDeckId = null;
                                        return;
                                    }
                                    
                                    searchTimeout = setTimeout(function() {
                                        searchDecks(query, resultsDiv, searchCtrl);
                                    }, 300);
                                });
                            }
                        }, 200);
                    }
                }
            });
            
            function searchDecks(query, container, searchControl) {
                if (!container) return;
                
                var ajaxurl = (typeof ajaxurl !== 'undefined' && ajaxurl) ? ajaxurl : 
                              (typeof hsDeckAjax !== 'undefined' && hsDeckAjax.ajaxurl) ? hsDeckAjax.ajaxurl : 
                              '/wp-admin/admin-ajax.php';
                var nonce = (typeof hsDeckAjax !== 'undefined' && hsDeckAjax.nonce) ? hsDeckAjax.nonce : '';
                
                if (!nonce) {
                    updateContainer(container, '<div style="padding:15px;color:#e74c3c;text-align:center;">Ошибка: токен безопасности не найден</div>', true);
                    return;
                }
                
                updateContainer(container, '<div style="padding:15px;text-align:center;color:#666;">Поиск...</div>', true);
                
                var data = {
                    action: 'hs_search_decks',
                    query: query,
                    nonce: nonce
                };
                
                if (typeof jQuery !== 'undefined') {
                    jQuery.post(ajaxurl, data, function(response) {
                        if (response.success && response.data && response.data.decks && response.data.decks.length > 0) {
                            showResults(response.data.decks, container, searchControl);
                        } else {
                            updateContainer(container, '<div style="padding:15px;color:#666;text-align:center;">Колоды не найдены</div>', true);
                        }
                    }).fail(function() {
                        updateContainer(container, '<div style="padding:15px;color:#e74c3c;text-align:center;">Ошибка при поиске</div>', true);
                    });
                } else {
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4 && xhr.status === 200) {
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.success && response.data && response.data.decks && response.data.decks.length > 0) {
                                    showResults(response.data.decks, container, searchControl);
                                } else {
                                    updateContainer(container, '<div style="padding:15px;color:#666;text-align:center;">Колоды не найдены</div>', true);
                                }
                            } catch(e) {
                                updateContainer(container, '<div style="padding:15px;color:#e74c3c;text-align:center;">Ошибка при обработке ответа</div>', true);
                            }
                        }
                    };
                    var formData = Object.keys(data).map(function(k) {
                        return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
                    }).join('&');
                    xhr.send(formData);
                }
            }
            
            function updateContainer(container, html, show) {
                if (!container) return;
                
                if (typeof jQuery !== 'undefined') {
                    jQuery(container).html(html);
                    if (show) {
                        jQuery(container).show();
                    }
                } else {
                    container.innerHTML = html;
                    if (show) {
                        container.style.display = 'block';
                    }
                }
            }
            
            function showResults(decks, container, searchControl) {
                if (!container) return;
                
                var html = '<div style="padding:5px;">';
                
                for (var i = 0; i < decks.length; i++) {
                    var deck = decks[i];
                    var date = new Date(deck.date);
                    var dateStr = '';
                    
                    try {
                        dateStr = date.toLocaleDateString('ru-RU', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        });
                    } catch(e) {
                        dateStr = date.getDate() + ' ' + 
                                 ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
                                  'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'][date.getMonth()] + ' ' + 
                                 date.getFullYear();
                    }
                    
                    var title = escapeHtml(deck.title || 'Без названия');
                    var deckId = deck.id || '';
                    
                    html += '<div class="hs-deck-item" data-id="' + deckId + '" style="padding:12px;border-bottom:1px solid #eee;cursor:pointer;background:#fff;transition:all 0.2s;">';
                    html += '<div style="font-weight:bold;color:#2d1b0e;margin-bottom:4px;font-size:14px;">' + title + '</div>';
                    html += '<div style="font-size:11px;color:#999;">Создано: ' + dateStr + '</div>';
                    html += '</div>';
                }
                
                html += '</div>';
                updateContainer(container, html, true);
                
                // Обработчики кликов
                setTimeout(function() {
                    var items = null;
                    if (typeof jQuery !== 'undefined') {
                        items = jQuery(container).find('.hs-deck-item');
                        items.on('click', function() {
                            selectedDeckId = jQuery(this).attr('data-id');
                            items.css({
                                'background': '#fff',
                                'border-left': 'none'
                            });
                            jQuery(this).css({
                                'background': '#e8f5e9',
                                'border-left': '3px solid #4caf50'
                            });
                            if (searchControl) {
                                var title = jQuery(this).find('div').first().text();
                                searchControl.value(title);
                            }
                        });
                        items.on('mouseenter', function() {
                            if (jQuery(this).attr('data-id') !== selectedDeckId) {
                                jQuery(this).css('background', '#f5f5f5');
                            }
                        });
                        items.on('mouseleave', function() {
                            if (jQuery(this).attr('data-id') !== selectedDeckId) {
                                jQuery(this).css('background', '#fff');
                            }
                        });
                    } else {
                        items = container.querySelectorAll('.hs-deck-item');
                        for (var j = 0; j < items.length; j++) {
                            (function(item) {
                                item.addEventListener('click', function() {
                                    selectedDeckId = this.getAttribute('data-id');
                                    for (var k = 0; k < items.length; k++) {
                                        items[k].style.background = '#fff';
                                        items[k].style.borderLeft = 'none';
                                    }
                                    this.style.background = '#e8f5e9';
                                    this.style.borderLeft = '3px solid #4caf50';
                                    if (searchControl) {
                                        var titleDiv = this.querySelector('div');
                                        if (titleDiv) {
                                            searchControl.value(titleDiv.textContent);
                                        }
                                    }
                                });
                                item.addEventListener('mouseenter', function() {
                                    if (this.getAttribute('data-id') !== selectedDeckId) {
                                        this.style.background = '#f5f5f5';
                                    }
                                });
                                item.addEventListener('mouseleave', function() {
                                    if (this.getAttribute('data-id') !== selectedDeckId) {
                                        this.style.background = '#fff';
                                    }
                                });
                            })(items[j]);
                        }
                    }
                }, 100);
            }
            
            function escapeHtml(text) {
                if (!text) return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        });
    });
})();
