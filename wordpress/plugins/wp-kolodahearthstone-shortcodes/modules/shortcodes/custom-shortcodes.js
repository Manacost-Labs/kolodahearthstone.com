(function($) {
    'use strict';
    
    // Функция для инициализации обработчика кнопки
    function initButtonHandler() {
        // Привязываем обработчик клика с делегированием
        $(document).off('click', '#mtp-custom-shortcodes-btn.customShortcodes').on('click', '#mtp-custom-shortcodes-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Пытаемся получить редактор
            var editor = null;
            if (typeof tinymce !== 'undefined') {
                editor = tinymce.get('content');
            }
            
            // Если редактор не найден, пытаемся получить его с небольшой задержкой
            if (!editor) {
                setTimeout(function() {
                    if (typeof tinymce !== 'undefined') {
                        editor = tinymce.get('content');
                    }
                    showShortcodesMenu(editor);
                }, 100);
            } else {
                showShortcodesMenu(editor);
            }
        });
    }
    
    // Используем делегирование событий для надежной работы
    // Привязываем обработчик при готовности документа
    $(document).ready(function() {
        initButtonHandler();
    });
    
    // Также инициализируем при полной загрузке страницы (резервный вариант)
    if (document.readyState === 'loading') {
        window.addEventListener('load', initButtonHandler);
    } else {
        // Если документ уже загружен, инициализируем сразу
        initButtonHandler();
    }
    
    // Объявляем функции в глобальной области видимости модуля
    function showShortcodesMenu(editor) {
        // Если редактор не передан, пытаемся получить его
        if (!editor && typeof tinymce !== 'undefined') {
            editor = tinymce.get('content');
        }
        // Создаем модальное окно с выбором шорткода
        var modal = $('<div id="mtp-shortcodes-modal" style="display:none;"></div>');
        modal.html(`
            <div class="mtp-modal-overlay"></div>
            <div class="mtp-modal-content">
                <div class="mtp-modal-header">
                    <h2>Выберите шорткод</h2>
                    <button type="button" class="mtp-modal-close">&times;</button>
                </div>
                <div class="mtp-modal-body">
                    <div class="mtp-shortcode-option" data-shortcode="spoiler">
                        <div class="mtp-shortcode-icon">📦</div>
                        <div class="mtp-shortcode-info">
                            <h3>Спойлер</h3>
                            <p>Создать скрытый контент с заголовком</p>
                        </div>
                    </div>
                    <div class="mtp-shortcode-option" data-shortcode="banner">
                        <div class="mtp-shortcode-icon">🎯</div>
                        <div class="mtp-shortcode-info">
                            <h3>Баннер</h3>
                            <p>Создать красивый баннер с иконкой</p>
                        </div>
                    </div>
                    <div class="mtp-shortcode-option" data-shortcode="table">
                        <div class="mtp-shortcode-icon">📊</div>
                        <div class="mtp-shortcode-info">
                            <h3>Таблица</h3>
                            <p>Создать таблицу на два столбца</p>
                        </div>
                    </div>
                    <div class="mtp-shortcode-option" data-shortcode="poll">
                        <div class="mtp-shortcode-icon">📋</div>
                        <div class="mtp-shortcode-info">
                            <h3>Опрос</h3>
                            <p>Создать опрос с двумя вариантами ответа</p>
                        </div>
                    </div>
                    <div class="mtp-shortcode-option" data-shortcode="deck">
                        <div class="mtp-shortcode-icon">🃏</div>
                        <div class="mtp-shortcode-info">
                            <h3>Колода</h3>
                            <p>Вставить или создать колоду Hearthstone</p>
                        </div>
                    </div>
                    <div class="mtp-shortcode-option" data-shortcode="spoiler-template">
                        <div class="mtp-shortcode-icon">📋</div>
                        <div class="mtp-shortcode-info">
                            <h3>Шаблон спойлеров</h3>
                            <p>Быстрая вставка сохраненных шаблонов спойлеров</p>
                        </div>
                    </div>
                    <div class="mtp-shortcode-option" data-shortcode="banner-template">
                        <div class="mtp-shortcode-icon">📄</div>
                        <div class="mtp-shortcode-info">
                            <h3>Шаблон баннеров</h3>
                            <p>Быстрая вставка сохраненных шаблонов баннеров</p>
                        </div>
                    </div>
                </div>
            </div>
        `);
        
        $('body').append(modal);
        modal.fadeIn(200);
        
        // Обработчики событий
        $('.mtp-shortcode-option').on('click', function() {
            var shortcodeType = $(this).data('shortcode');
            modal.fadeOut(200, function() {
                $(this).remove();
            });
            insertShortcode(editor, shortcodeType);
        });
        
        $('.mtp-modal-close, .mtp-modal-overlay').on('click', function() {
            modal.fadeOut(200, function() {
                $(this).remove();
            });
        });
        
        // Закрытие по ESC
        $(document).on('keydown.mtpShortcodes', function(e) {
            if (e.keyCode === 27) {
                modal.fadeOut(200, function() {
                    $(this).remove();
                });
                $(document).off('keydown.mtpShortcodes');
            }
        });
    }
    
    function insertShortcode(editor, shortcodeType) {
        // Всегда используем наши формы для единообразия и гарантии наличия всех опций
        switch(shortcodeType) {
            case 'spoiler':
                openSpoilerForm(editor);
                break;
                
            case 'banner':
                openBannerForm(editor);
                break;
                
            case 'table':
                openTableForm(editor);
                break;
                
            case 'poll':
                openPollForm(editor);
                break;
                
            case 'deck':
                openDeckMenu(editor);
                break;
                
            case 'spoiler-template':
                openSpoilerTemplates(editor);
                break;
                
            case 'banner-template':
                openBannerTemplates(editor);
                break;
        }
    }
    
    // Функции для открытия форм, если команды недоступны
    function openSpoilerForm(editor) {
            if (!editor) {
                alert('Редактор не загружен. Пожалуйста, подождите загрузки редактора.');
                return;
            }
            var selectedContent = editor.selection.getContent();
            var selectedText = editor.selection.getContent({format: 'text'});
            
            editor.windowManager.open({
                title: 'Вставить спойлер',
                body: [
                    {
                        type: 'textbox',
                        name: 'title',
                        label: 'Заголовок спойлера',
                        value: 'Спойлер'
                    },
                    {
                        type: 'listbox',
                        name: 'color',
                        label: 'Цвет спойлера',
                        values: [
                            {text: 'По умолчанию (бежевый)', value: 'default'},
                            {text: 'Синий', value: 'blue'},
                            {text: 'Зеленый', value: 'green'},
                            {text: 'Красный', value: 'red'},
                            {text: 'Фиолетовый', value: 'purple'},
                            {text: 'Оранжевый', value: 'orange'},
                            {text: 'Желтый', value: 'yellow'},
                            {text: 'Коричневый', value: 'brown'}
                        ],
                        value: 'default'
                    },
                    {
                        type: 'checkbox',
                        name: 'open',
                        label: 'Открытый спойлер',
                        checked: false
                    }
                ],
                width: 600,
                height: 400,
                onsubmit: function(e) {
                    var content = selectedContent || selectedText || 'Ваш контент здесь';
                    var shortcode = '[spoiler title="' + e.data.title.replace(/"/g, '&quot;') + '" color="' + e.data.color + '"';
                    // Проверяем значение checkbox (может быть true, 'true', или '1')
                    if (e.data.open === true || e.data.open === 'true' || e.data.open === '1' || e.data.open === 1) {
                        shortcode += ' open="1"';
                    }
                    shortcode += ']' + content + '[/spoiler]';
                    editor.insertContent(shortcode);
                }
            });
    }
    
    function openBannerForm(editor) {
            if (!editor) {
                alert('Редактор не загружен. Пожалуйста, подождите загрузки редактора.');
                return;
            }
            var selectedContent = editor.selection.getContent();
            var selectedText = editor.selection.getContent({format: 'text'});
            
            editor.windowManager.open({
                title: 'Вставить баннер',
                body: [
                    {
                        type: 'textbox',
                        name: 'title',
                        label: 'Заголовок баннера',
                        value: 'Баннер'
                    },
                    {
                        type: 'listbox',
                        name: 'color',
                        label: 'Цвет баннера',
                        values: [
                            {text: 'По умолчанию (бежевый)', value: 'default'},
                            {text: 'Синий', value: 'blue'},
                            {text: 'Зеленый', value: 'green'},
                            {text: 'Красный', value: 'red'},
                            {text: 'Фиолетовый', value: 'purple'},
                            {text: 'Оранжевый', value: 'orange'},
                            {text: 'Желтый', value: 'yellow'},
                            {text: 'Коричневый', value: 'brown'}
                        ],
                        value: 'default'
                    },
                    {
                        type: 'textbox',
                        name: 'url',
                        label: 'Ссылка (необязательно)',
                        value: ''
                    }
                ],
                width: 600,
                height: 400,
                onsubmit: function(e) {
                    var content = selectedContent || selectedText || 'Ваш текст здесь';
                    var shortcode = '[banner title="' + e.data.title.replace(/"/g, '&quot;') + '" color="' + e.data.color + '"';
                    if (e.data.url && e.data.url.trim()) {
                        shortcode += ' url="' + e.data.url.replace(/"/g, '&quot;') + '"';
                    }
                    shortcode += ']' + content + '[/banner]';
                    editor.insertContent(shortcode);
                }
            });
    }
    
    function openTableForm(editor) {
            if (!editor) {
                alert('Редактор не загружен. Пожалуйста, подождите загрузки редактора.');
                return;
            }
            editor.windowManager.open({
                title: 'Вставить таблицу',
                body: [
                    {
                        type: 'listbox',
                        name: 'color',
                        label: 'Цвет таблицы',
                        values: [
                            {text: 'По умолчанию (бежевый)', value: 'default'},
                            {text: 'Синий', value: 'blue'},
                            {text: 'Зеленый', value: 'green'},
                            {text: 'Красный', value: 'red'},
                            {text: 'Фиолетовый', value: 'purple'},
                            {text: 'Оранжевый', value: 'orange'},
                            {text: 'Желтый', value: 'yellow'},
                            {text: 'Коричневый', value: 'brown'}
                        ],
                        value: 'default'
                    },
                    {
                        type: 'container',
                        html: '<p style="margin:10px 0;color:#666;font-size:12px;box-sizing:border-box;">Введите строки таблицы в формате: Категория|Значение (каждая строка с новой строки)</p>'
                    },
                    {
                        type: 'textbox',
                        name: 'content',
                        label: 'Содержимое таблицы',
                        multiline: true,
                        minHeight: 200,
                        value: 'Категория|Значение'
                    }
                ],
                width: 700,
                height: 550,
                onsubmit: function(e) {
                    var content = e.data.content || 'Категория|Значение';
                    var shortcode = '[table color="' + e.data.color + '"]' + content + '[/table]';
                    editor.insertContent(shortcode);
                }
            });
        }
        
        function openPollForm(editor) {
            if (!editor) {
                alert('Редактор не загружен. Пожалуйста, подождите загрузки редактора.');
                return;
            }
            // Используем команду TinyMCE плагина, если она доступна
            try {
                editor.execCommand('khs_insert_poll');
            } catch(e) {
                // Если команда недоступна, создаем свою форму
                editor.windowManager.open({
                    title: 'Вставить опрос',
                    body: [
                        {
                            type: 'textbox',
                            name: 'question',
                            label: 'Вопрос',
                            value: 'Вопрос?',
                            placeholder: 'Введите вопрос'
                        },
                        {
                            type: 'textbox',
                            name: 'option1',
                            label: 'Вариант 1',
                            value: 'Вариант 1',
                            placeholder: 'Первый вариант ответа'
                        },
                        {
                            type: 'textbox',
                            name: 'option2',
                            label: 'Вариант 2',
                            value: 'Вариант 2',
                            placeholder: 'Второй вариант ответа'
                        },
                        {
                            type: 'container',
                            html: '<p style="margin:10px 0;color:#666;font-size:12px;box-sizing:border-box;">Описание (необязательно):</p>'
                        },
                        {
                            type: 'textbox',
                            name: 'content',
                            label: 'Описание/Контент',
                            multiline: true,
                            minHeight: 100,
                            value: '',
                            placeholder: 'Дополнительное описание или контент, который будет отображаться под вопросом'
                        }
                    ],
                    width: 600,
                    height: 500,
                    onsubmit: function(e) {
                        var question = e.data.question || 'Вопрос?';
                        var option1 = e.data.option1 || 'Вариант 1';
                        var option2 = e.data.option2 || 'Вариант 2';
                        var content = e.data.content || '';
                        
                        // Экранируем кавычки в атрибутах
                        question = question.replace(/"/g, '&quot;');
                        option1 = option1.replace(/"/g, '&quot;');
                        option2 = option2.replace(/"/g, '&quot;');
                        
                        // Формируем шорткод - парный с содержимым
                        var shortcode = '[khs_poll question="' + question + '" option1="' + option1 + '" option2="' + option2 + ']';
                        
                        // Если есть контент, добавляем его внутрь тегов
                        if (content && content.trim()) {
                            shortcode += content.trim();
                        }
                        
                        shortcode += '[/khs_poll]';
                        
                        editor.insertContent(shortcode);
                    }
                });
            }
    }
    
    function openDeckMenu(editor) {
            if (!editor) {
                alert('Редактор не загружен. Пожалуйста, подождите загрузки редактора.');
                return;
            }
            // Показываем меню выбора: вставить существующую или создать новую
            editor.windowManager.open({
                title: 'Вставить колоду',
                body: [
                    {
                        type: 'container',
                        html: '<div style="padding:20px;text-align:center;box-sizing:border-box;width:100%;max-width:100%;overflow:hidden;">' +
                              '<button type="button" id="mtp-insert-deck-btn" style="width:calc(100% - 0px);max-width:100%;padding:15px;margin-bottom:10px;background:#0073aa;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:14px;font-weight:bold;box-sizing:border-box;display:block;">Вставить существующую колоду</button>' +
                              '<button type="button" id="mtp-create-deck-btn" style="width:calc(100% - 0px);max-width:100%;padding:15px;background:#46b450;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:14px;font-weight:bold;box-sizing:border-box;display:block;">Создать новую колоду</button>' +
                              '</div>'
                    }
                ],
                width: 500,
                height: 250,
                onopen: function() {
                    var self = this;
                    var bodyEl = self.getEl();
                    
                    if (bodyEl) {
                        var insertBtn = bodyEl.querySelector('#mtp-insert-deck-btn');
                        var createBtn = bodyEl.querySelector('#mtp-create-deck-btn');
                        
                        if (insertBtn) {
                            insertBtn.onclick = function() {
                                editor.windowManager.close();
                                // Вызываем команду плагина для вставки существующей колоды
                                try {
                                    editor.execCommand('hs_insert_deck');
                                } catch(e) {
                                    alert('Ошибка: плагин колод не загружен. Убедитесь, что плагин "KolodaHearthstone: Decks" активирован.');
                                }
                            };
                        }
                        
                        if (createBtn) {
                            createBtn.onclick = function() {
                                editor.windowManager.close();
                                // Вызываем команду плагина для создания новой колоды
                                try {
                                    editor.execCommand('hs_create_deck');
                                } catch(e) {
                                    alert('Ошибка: плагин колод не загружен. Убедитесь, что плагин "KolodaHearthstone: Decks" активирован.');
                                }
                            };
                        }
                    }
                }
            });
    }
    
    // Функции для работы с шаблонами
    function getTemplates(type) {
            var key = 'mtp_' + type + '_templates';
            var templates = localStorage.getItem(key);
            return templates ? JSON.parse(templates) : [];
    }
    
    function saveTemplate(type, template) {
            var key = 'mtp_' + type + '_templates';
            var templates = getTemplates(type);
            template.id = Date.now();
            template.created = new Date().toLocaleString('ru-RU');
            templates.push(template);
            localStorage.setItem(key, JSON.stringify(templates));
            return template.id;
    }
    
    function deleteTemplate(type, id) {
            var key = 'mtp_' + type + '_templates';
            var templates = getTemplates(type);
            templates = templates.filter(function(t) { return t.id != id; });
            localStorage.setItem(key, JSON.stringify(templates));
    }
    
    function openSpoilerTemplates(editor) {
            if (!editor) {
                alert('Редактор не загружен. Пожалуйста, подождите загрузки редактора.');
                return;
            }
            var templates = getTemplates('spoiler');
            var selectedContent = editor.selection.getContent();
            var selectedText = editor.selection.getContent({format: 'text'});
            var showSaveForm = false;
            
            var templatesHtml = '';
            if (templates.length > 0) {
                templatesHtml = '<div id="mtp-templates-list" style="max-height:450px;overflow-y:auto;margin:15px 0;border:1px solid #ddd;border-radius:5px;padding:10px;box-sizing:border-box;">';
                templates.forEach(function(template) {
                    templatesHtml += '<div class="mtp-template-item" data-id="' + template.id + '" style="padding:12px;margin-bottom:8px;background:#f9f9f9;border:1px solid #e0e0e0;border-radius:5px;cursor:pointer;position:relative;box-sizing:border-box;">' +
                        '<div style="font-weight:bold;margin-bottom:5px;box-sizing:border-box;">' + (template.name || 'Без названия') + '</div>' +
                        '<div style="font-size:11px;color:#666;margin-bottom:5px;box-sizing:border-box;">' + template.created + '</div>' +
                        '<div style="font-size:12px;color:#888;font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;box-sizing:border-box;">' + 
                        '[spoiler title="' + (template.title || '') + '" color="' + (template.color || 'default') + '"' + 
                        (template.open ? ' open="1"' : '') + ']...</div>' +
                        '<button class="mtp-delete-template" data-id="' + template.id + '" style="position:absolute;top:8px;right:8px;background:#dc3232;color:#fff;border:none;border-radius:3px;padding:4px 8px;font-size:11px;cursor:pointer;box-sizing:border-box;">Удалить</button>' +
                        '</div>';
                });
                templatesHtml += '</div>';
            } else {
                templatesHtml = '<div id="mtp-templates-list" style="padding:30px;text-align:center;color:#666;box-sizing:border-box;">Нет сохраненных шаблонов</div>';
            }
            
            var saveFormHtml = '<div id="mtp-save-form" style="display:none;background:#fff;padding:20px;border-radius:6px;margin-bottom:20px;border:2px solid #e0e0e0;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:10;">' +
                '<div style="font-weight:bold;font-size:16px;color:#23282d;margin-bottom:15px;padding-bottom:10px;border-bottom:1px solid #e0e0e0;box-sizing:border-box;">Создать новый шаблон:</div>' +
                '<div style="margin-bottom:15px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:11;"><label style="display:block;margin-bottom:8px;font-weight:600;font-size:13px;color:#23282d;box-sizing:border-box;">Название шаблона:</label>' +
                '<input type="text" id="mtp-template-name" style="width:100%;max-width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;background:#fff;font-size:14px;" placeholder="Введите название"></div>' +
                '<div style="margin-bottom:15px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:11;"><label style="display:block;margin-bottom:8px;font-weight:600;font-size:13px;color:#23282d;box-sizing:border-box;">Заголовок спойлера:</label>' +
                '<input type="text" id="mtp-template-title" value="Спойлер" style="width:100%;max-width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;background:#fff;font-size:14px;"></div>' +
                '<div style="margin-bottom:15px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:11;"><label style="display:block;margin-bottom:8px;font-weight:600;font-size:13px;color:#23282d;box-sizing:border-box;">Цвет:</label>' +
                '<select id="mtp-template-color" style="width:100%;max-width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;background:#fff;font-size:14px;">' +
                '<option value="default">По умолчанию (бежевый)</option>' +
                '<option value="blue">Синий</option>' +
                '<option value="green">Зеленый</option>' +
                '<option value="red">Красный</option>' +
                '<option value="purple">Фиолетовый</option>' +
                '<option value="orange">Оранжевый</option>' +
                '<option value="yellow">Желтый</option>' +
                '<option value="brown">Коричневый</option>' +
                '</select></div>' +
                '<div style="margin-bottom:10px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:11;"><label style="display:flex;align-items:center;font-weight:600;font-size:13px;cursor:pointer;box-sizing:border-box;">' +
                '<input type="checkbox" id="mtp-template-open" style="margin-right:8px;box-sizing:border-box;"> Открытый спойлер</label></div>' +
                '<div style="margin-bottom:15px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:11;"><label style="display:block;margin-bottom:8px;font-weight:600;font-size:13px;color:#23282d;box-sizing:border-box;">Содержимое:</label>' +
                '<textarea id="mtp-template-content" style="width:100%;max-width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;min-height:180px;resize:vertical;background:#fff;font-size:14px;font-family:inherit;" placeholder="Ваш контент здесь">' + (selectedContent || selectedText || 'Ваш контент здесь') + '</textarea></div>' +
                '<div style="display:flex;gap:10px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:11;margin-top:10px;"><button type="button" id="mtp-save-template-btn" style="flex:1 1 0;min-width:0;max-width:100%;padding:12px 20px;background:#0073aa;color:#fff;border:none;border-radius:5px;cursor:pointer;font-weight:600;font-size:14px;box-sizing:border-box;position:relative;z-index:12;">Сохранить</button>' +
                '<button type="button" id="mtp-cancel-save-btn" style="flex:1 1 0;min-width:0;max-width:100%;padding:12px 20px;background:#ccc;color:#333;border:none;border-radius:5px;cursor:pointer;font-weight:600;font-size:14px;box-sizing:border-box;position:relative;z-index:12;">Отмена</button></div>' +
                '</div>';
            
            editor.windowManager.open({
                title: 'Шаблоны спойлеров',
                body: [
                    {
                        type: 'container',
                        html: '<div style="margin-bottom:15px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:1;">' +
                              '<button type="button" id="mtp-show-save-form" style="width:100%;max-width:100%;padding:14px 20px;background:#0073aa;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:15px;font-weight:600;margin-bottom:20px;box-sizing:border-box;display:block;position:relative;z-index:5;">💾 Создать новый шаблон</button>' +
                              '</div>' +
                              saveFormHtml +
                              '<div style="font-weight:600;font-size:15px;color:#23282d;margin-bottom:15px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:1;">Сохраненные шаблоны:</div>' +
                              templatesHtml
                    }
                ],
                width: Math.min(1400, window.innerWidth - 100),
                height: Math.min(900, window.innerHeight - 80),
                onopen: function() {
                    var self = this;
                    var bodyEl = self.getEl();
                    
                    // Центрируем окно
                    setTimeout(function() {
                        var windowEl = self.getEl().closest('.mce-window');
                        if (windowEl) {
                            windowEl.style.position = 'fixed';
                            windowEl.style.top = '50%';
                            windowEl.style.left = '50%';
                            windowEl.style.transform = 'translate(-50%, -50%)';
                            windowEl.style.margin = '0';
                        }
                    }, 10);
                    
                    // Показать форму сохранения
                    var showBtn = bodyEl.querySelector('#mtp-show-save-form');
                    var saveForm = bodyEl.querySelector('#mtp-save-form');
                    var templatesList = bodyEl.querySelector('#mtp-templates-list');
                    
                    if (showBtn && saveForm) {
                        showBtn.onclick = function() {
                            saveForm.style.display = saveForm.style.display === 'none' ? 'block' : 'none';
                            showBtn.textContent = saveForm.style.display === 'none' ? '💾 Создать новый шаблон' : '✖️ Скрыть форму';
                        };
                    }
                    
                    // Сохранение шаблона
                    var saveTemplateBtn = bodyEl.querySelector('#mtp-save-template-btn');
                    var cancelBtn = bodyEl.querySelector('#mtp-cancel-save-btn');
                    
                    if (saveTemplateBtn) {
                        saveTemplateBtn.onclick = function() {
                            var name = bodyEl.querySelector('#mtp-template-name').value.trim();
                            var title = bodyEl.querySelector('#mtp-template-title').value.trim() || 'Спойлер';
                            var color = bodyEl.querySelector('#mtp-template-color').value;
                            var open = bodyEl.querySelector('#mtp-template-open').checked;
                            var content = bodyEl.querySelector('#mtp-template-content').value.trim() || 'Ваш контент здесь';
                            
                            if (!name) {
                                name = 'Шаблон ' + new Date().toLocaleString('ru-RU');
                            }
                            
                            var template = {
                                name: name,
                                title: title,
                                color: color,
                                open: open,
                                content: content
                            };
                            
                            saveTemplate('spoiler', template);
                            
                            // Очистить форму
                            bodyEl.querySelector('#mtp-template-name').value = '';
                            bodyEl.querySelector('#mtp-template-title').value = 'Спойлер';
                            bodyEl.querySelector('#mtp-template-color').value = 'default';
                            bodyEl.querySelector('#mtp-template-open').checked = false;
                            bodyEl.querySelector('#mtp-template-content').value = 'Ваш контент здесь';
                            
                            // Обновить список
                            editor.windowManager.close();
                            openSpoilerTemplates(editor);
                        };
                    }
                    
                    if (cancelBtn) {
                        cancelBtn.onclick = function() {
                            saveForm.style.display = 'none';
                            showBtn.textContent = '💾 Создать новый шаблон';
                        };
                    }
                    
                    // Вставка шаблона
                    var templateItems = bodyEl.querySelectorAll('.mtp-template-item');
                    templateItems.forEach(function(item) {
                        item.onclick = function(e) {
                            if (e.target.classList.contains('mtp-delete-template')) {
                                return;
                            }
                            var id = this.getAttribute('data-id');
                            var template = templates.find(function(t) { return t.id == id; });
                            if (template) {
                                var shortcode = '[spoiler title="' + (template.title || 'Спойлер').replace(/"/g, '&quot;') + 
                                               '" color="' + (template.color || 'default') + '"';
                                if (template.open) {
                                    shortcode += ' open="1"';
                                }
                                shortcode += ']' + (template.content || 'Ваш контент здесь') + '[/spoiler]';
                                editor.insertContent(shortcode);
                                editor.windowManager.close();
                            }
                        };
                    });
                    
                    // Удаление шаблона
                    var deleteBtns = bodyEl.querySelectorAll('.mtp-delete-template');
                    deleteBtns.forEach(function(btn) {
                        btn.onclick = function(e) {
                            e.stopPropagation();
                            if (confirm('Удалить этот шаблон?')) {
                                var id = parseInt(this.getAttribute('data-id'));
                                deleteTemplate('spoiler', id);
                                editor.windowManager.close();
                                openSpoilerTemplates(editor);
                            }
                        };
                    });
                }
            });
    }
    
    function openBannerTemplates(editor) {
            if (!editor) {
                alert('Редактор не загружен. Пожалуйста, подождите загрузки редактора.');
                return;
            }
            var templates = getTemplates('banner');
            var selectedContent = editor.selection.getContent();
            var selectedText = editor.selection.getContent({format: 'text'});
            
            var templatesHtml = '';
            if (templates.length > 0) {
                templatesHtml = '<div id="mtp-templates-list" style="max-height:450px;overflow-y:auto;margin:15px 0;border:1px solid #ddd;border-radius:5px;padding:10px;box-sizing:border-box;">';
                templates.forEach(function(template) {
                    templatesHtml += '<div class="mtp-template-item" data-id="' + template.id + '" style="padding:12px;margin-bottom:8px;background:#f9f9f9;border:1px solid #e0e0e0;border-radius:5px;cursor:pointer;position:relative;box-sizing:border-box;">' +
                        '<div style="font-weight:bold;margin-bottom:5px;box-sizing:border-box;">' + (template.name || 'Без названия') + '</div>' +
                        '<div style="font-size:11px;color:#666;margin-bottom:5px;box-sizing:border-box;">' + template.created + '</div>' +
                        '<div style="font-size:12px;color:#888;font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;box-sizing:border-box;">' + 
                        '[banner title="' + (template.title || '') + '" color="' + (template.color || 'default') + '"' + 
                        (template.url ? ' url="' + template.url + '"' : '') + ']...</div>' +
                        '<button class="mtp-delete-template" data-id="' + template.id + '" style="position:absolute;top:8px;right:8px;background:#dc3232;color:#fff;border:none;border-radius:3px;padding:4px 8px;font-size:11px;cursor:pointer;box-sizing:border-box;">Удалить</button>' +
                        '</div>';
                });
                templatesHtml += '</div>';
            } else {
                templatesHtml = '<div id="mtp-templates-list" style="padding:30px;text-align:center;color:#666;box-sizing:border-box;">Нет сохраненных шаблонов</div>';
            }
            
            var saveFormHtml = '<div id="mtp-save-form" style="display:none;background:#fff;padding:20px;border-radius:6px;margin-bottom:20px;border:2px solid #e0e0e0;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:10;">' +
                '<div style="font-weight:bold;font-size:16px;color:#23282d;margin-bottom:15px;padding-bottom:10px;border-bottom:1px solid #e0e0e0;box-sizing:border-box;">Создать новый шаблон:</div>' +
                '<div style="margin-bottom:15px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:11;"><label style="display:block;margin-bottom:8px;font-weight:600;font-size:13px;color:#23282d;box-sizing:border-box;">Название шаблона:</label>' +
                '<input type="text" id="mtp-template-name" style="width:100%;max-width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;background:#fff;font-size:14px;" placeholder="Введите название"></div>' +
                '<div style="margin-bottom:15px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:11;"><label style="display:block;margin-bottom:8px;font-weight:600;font-size:13px;color:#23282d;box-sizing:border-box;">Заголовок баннера:</label>' +
                '<input type="text" id="mtp-template-title" value="Баннер" style="width:100%;max-width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;background:#fff;font-size:14px;"></div>' +
                '<div style="margin-bottom:15px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:11;"><label style="display:block;margin-bottom:8px;font-weight:600;font-size:13px;color:#23282d;box-sizing:border-box;">Цвет:</label>' +
                '<select id="mtp-template-color" style="width:100%;max-width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;background:#fff;font-size:14px;">' +
                '<option value="default">По умолчанию (бежевый)</option>' +
                '<option value="blue">Синий</option>' +
                '<option value="green">Зеленый</option>' +
                '<option value="red">Красный</option>' +
                '<option value="purple">Фиолетовый</option>' +
                '<option value="orange">Оранжевый</option>' +
                '<option value="yellow">Желтый</option>' +
                '<option value="brown">Коричневый</option>' +
                '</select></div>' +
                '<div style="margin-bottom:15px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:11;"><label style="display:block;margin-bottom:8px;font-weight:600;font-size:13px;color:#23282d;box-sizing:border-box;">Ссылка (необязательно):</label>' +
                '<input type="text" id="mtp-template-url" style="width:100%;max-width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;background:#fff;font-size:14px;" placeholder="https://example.com"></div>' +
                '<div style="margin-bottom:10px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:11;"><label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;box-sizing:border-box;">Содержимое:</label>' +
                '<textarea id="mtp-template-content" style="width:100%;max-width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;min-height:180px;resize:vertical;" placeholder="Ваш текст здесь">' + (selectedContent || selectedText || 'Ваш текст здесь') + '</textarea></div>' +
                '<div style="display:flex;gap:10px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:11;margin-top:10px;"><button type="button" id="mtp-save-template-btn" style="flex:1 1 0;min-width:0;max-width:100%;padding:12px 20px;background:#0073aa;color:#fff;border:none;border-radius:5px;cursor:pointer;font-weight:600;font-size:14px;box-sizing:border-box;position:relative;z-index:12;">Сохранить</button>' +
                '<button type="button" id="mtp-cancel-save-btn" style="flex:1 1 0;min-width:0;max-width:100%;padding:12px 20px;background:#ccc;color:#333;border:none;border-radius:5px;cursor:pointer;font-weight:600;font-size:14px;box-sizing:border-box;position:relative;z-index:12;">Отмена</button></div>' +
                '</div>';
            
            editor.windowManager.open({
                title: 'Шаблоны баннеров',
                body: [
                    {
                        type: 'container',
                        html: '<div style="margin-bottom:15px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:1;">' +
                              '<button type="button" id="mtp-show-save-form" style="width:100%;max-width:100%;padding:14px 20px;background:#0073aa;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:15px;font-weight:600;margin-bottom:20px;box-sizing:border-box;display:block;position:relative;z-index:5;">💾 Создать новый шаблон</button>' +
                              '</div>' +
                              saveFormHtml +
                              '<div style="font-weight:600;font-size:15px;color:#23282d;margin-bottom:15px;box-sizing:border-box;width:100%;max-width:100%;position:relative;z-index:1;">Сохраненные шаблоны:</div>' +
                              templatesHtml
                    }
                ],
                width: Math.min(1400, window.innerWidth - 100),
                height: Math.min(900, window.innerHeight - 80),
                onopen: function() {
                    var self = this;
                    var bodyEl = self.getEl();
                    
                    // Центрируем окно
                    setTimeout(function() {
                        var windowEl = self.getEl().closest('.mce-window');
                        if (windowEl) {
                            windowEl.style.position = 'fixed';
                            windowEl.style.top = '50%';
                            windowEl.style.left = '50%';
                            windowEl.style.transform = 'translate(-50%, -50%)';
                            windowEl.style.margin = '0';
                        }
                    }, 10);
                    
                    // Показать форму сохранения
                    var showBtn = bodyEl.querySelector('#mtp-show-save-form');
                    var saveForm = bodyEl.querySelector('#mtp-save-form');
                    var templatesList = bodyEl.querySelector('#mtp-templates-list');
                    
                    if (showBtn && saveForm) {
                        showBtn.onclick = function() {
                            saveForm.style.display = saveForm.style.display === 'none' ? 'block' : 'none';
                            showBtn.textContent = saveForm.style.display === 'none' ? '💾 Создать новый шаблон' : '✖️ Скрыть форму';
                        };
                    }
                    
                    // Сохранение шаблона
                    var saveTemplateBtn = bodyEl.querySelector('#mtp-save-template-btn');
                    var cancelBtn = bodyEl.querySelector('#mtp-cancel-save-btn');
                    
                    if (saveTemplateBtn) {
                        saveTemplateBtn.onclick = function() {
                            var name = bodyEl.querySelector('#mtp-template-name').value.trim();
                            var title = bodyEl.querySelector('#mtp-template-title').value.trim() || 'Баннер';
                            var color = bodyEl.querySelector('#mtp-template-color').value;
                            var url = bodyEl.querySelector('#mtp-template-url').value.trim();
                            var content = bodyEl.querySelector('#mtp-template-content').value.trim() || 'Ваш текст здесь';
                            
                            if (!name) {
                                name = 'Шаблон ' + new Date().toLocaleString('ru-RU');
                            }
                            
                            var template = {
                                name: name,
                                title: title,
                                color: color,
                                url: url,
                                content: content
                            };
                            
                            saveTemplate('banner', template);
                            
                            // Очистить форму
                            bodyEl.querySelector('#mtp-template-name').value = '';
                            bodyEl.querySelector('#mtp-template-title').value = 'Баннер';
                            bodyEl.querySelector('#mtp-template-color').value = 'default';
                            bodyEl.querySelector('#mtp-template-url').value = '';
                            bodyEl.querySelector('#mtp-template-content').value = 'Ваш текст здесь';
                            
                            // Обновить список
                            editor.windowManager.close();
                            openBannerTemplates(editor);
                        };
                    }
                    
                    if (cancelBtn) {
                        cancelBtn.onclick = function() {
                            saveForm.style.display = 'none';
                            showBtn.textContent = '💾 Создать новый шаблон';
                        };
                    }
                    
                    // Вставка шаблона
                    var templateItems = bodyEl.querySelectorAll('.mtp-template-item');
                    templateItems.forEach(function(item) {
                        item.onclick = function(e) {
                            if (e.target.classList.contains('mtp-delete-template')) {
                                return;
                            }
                            var id = this.getAttribute('data-id');
                            var template = templates.find(function(t) { return t.id == id; });
                            if (template) {
                                var shortcode = '[banner title="' + (template.title || 'Баннер').replace(/"/g, '&quot;') + 
                                               '" color="' + (template.color || 'default') + '"';
                                if (template.url) {
                                    shortcode += ' url="' + template.url.replace(/"/g, '&quot;') + '"';
                                }
                                shortcode += ']' + (template.content || 'Ваш текст здесь') + '[/banner]';
                                editor.insertContent(shortcode);
                                editor.windowManager.close();
                            }
                        };
                    });
                    
                    // Удаление шаблона
                    var deleteBtns = bodyEl.querySelectorAll('.mtp-delete-template');
                    deleteBtns.forEach(function(btn) {
                        btn.onclick = function(e) {
                            e.stopPropagation();
                            if (confirm('Удалить этот шаблон?')) {
                                var id = parseInt(this.getAttribute('data-id'));
                                deleteTemplate('banner', id);
                                editor.windowManager.close();
                                openBannerTemplates(editor);
                            }
                        };
                    });
                }
            });
    }
    
})(jQuery);

