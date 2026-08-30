(function() {
    tinymce.PluginManager.add('khs_poll', function(editor, url) {
        editor.addButton('khs_poll', {
            title: 'Вставить опрос',
            text: 'Опрос',
            icon: false,
            cmd: 'khs_insert_poll',
            onclick: function() {
                editor.execCommand('khs_insert_poll');
            }
        });
        
        editor.addCommand('khs_insert_poll', function() {
            // Создаем контейнер для опций
            var optionsHtml = '<div id="khs-poll-options-container" style="margin: 10px 0;">';
            optionsHtml += '<div style="margin-bottom: 10px;"><strong>Варианты ответа:</strong></div>';
            optionsHtml += '<div id="khs-poll-options-list">';
            optionsHtml += '<div style="margin-bottom: 8px;"><input type="text" data-option="1" value="Вариант 1" style="width: 85%; padding: 5px; box-sizing: border-box;" placeholder="Вариант ответа"><button type="button" class="khs-remove-option" style="width: 13%; margin-left: 2%; padding: 5px; display: none;">×</button></div>';
            optionsHtml += '<div style="margin-bottom: 8px;"><input type="text" data-option="2" value="Вариант 2" style="width: 85%; padding: 5px; box-sizing: border-box;" placeholder="Вариант ответа"><button type="button" class="khs-remove-option" style="width: 13%; margin-left: 2%; padding: 5px;">×</button></div>';
            optionsHtml += '</div>';
            optionsHtml += '<button type="button" id="khs-add-option" style="margin-top: 8px; padding: 6px 12px; background: #0085ba; color: white; border: none; border-radius: 3px; cursor: pointer;">+ Добавить вариант</button>';
            optionsHtml += '</div>';
            
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
                        type: 'container',
                        html: optionsHtml
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
                height: 550,
                onopen: function(e) {
                    var win = e.target;
                    var container = win.find('#khs-poll-options-container')[0].getEl();
                    
                    // Обработчик добавления опции
                    container.querySelector('#khs-add-option').onclick = function() {
                        var optionsList = container.querySelector('#khs-poll-options-list');
                        var optionCount = optionsList.children.length;
                        var newOption = document.createElement('div');
                        newOption.style.cssText = 'margin-bottom: 8px;';
                        newOption.innerHTML = '<input type="text" data-option="' + (optionCount + 1) + '" value="" style="width: 85%; padding: 5px; box-sizing: border-box;" placeholder="Вариант ответа"><button type="button" class="khs-remove-option" style="width: 13%; margin-left: 2%; padding: 5px;">×</button>';
                        optionsList.appendChild(newOption);
                        updateRemoveButtons();
                    };
                    
                    // Обработчик удаления опции
                    function updateRemoveButtons() {
                        var optionsList = container.querySelector('#khs-poll-options-list');
                        var removeButtons = optionsList.querySelectorAll('.khs-remove-option');
                        removeButtons.forEach(function(btn, index) {
                            btn.style.display = optionsList.children.length > 2 ? '' : 'none';
                            btn.onclick = function() {
                                if (optionsList.children.length > 2) {
                                    this.parentElement.remove();
                                    updateRemoveButtons();
                                }
                            };
                        });
                    }
                    
                    updateRemoveButtons();
                },
                onsubmit: function(e) {
                    var question = e.data.question || 'Вопрос?';
                    var content = e.data.content || '';
                    
                    // Собираем все опции из формы
                    var win = e.target;
                    var container = win.find('#khs-poll-options-container')[0].getEl();
                    var optionInputs = container.querySelectorAll('#khs-poll-options-list input[type="text"]');
                    var options = [];
                    
                    optionInputs.forEach(function(input) {
                        var value = input.value.trim();
                        if (value) {
                            options.push(value);
                        }
                    });
                    
                    // Минимум 2 опции
                    if (options.length < 2) {
                        alert('Добавьте минимум 2 варианта ответа');
                        return false;
                    }
                    
                    // Экранируем кавычки
                    question = question.replace(/"/g, '&quot;');
                    
                    // Формируем шорткод с опциями
                    var shortcode = '[khs_poll question="' + question + '"';
                    options.forEach(function(option, index) {
                        var escapedOption = option.replace(/"/g, '&quot;');
                        shortcode += ' option' + (index + 1) + '="' + escapedOption + '"';
                    });
                    shortcode += ']';
                    
                    // Если есть контент, добавляем его внутрь тегов
                    if (content && content.trim()) {
                        shortcode += content.trim();
                    }
                    
                    shortcode += '[/khs_poll]';
                    
                    editor.insertContent(shortcode);
                }
            });
        });
    });
})();

