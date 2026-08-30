(function() {
    tinymce.PluginManager.add('mtp_table', function(editor, url) {
        editor.addButton('mtp_table', {
            title: 'Вставить таблицу',
            text: 'Таблица',
            icon: 'mtp_table',
            cmd: 'mtp_insert_table',
            onclick: function() {
                editor.execCommand('mtp_insert_table');
            }
        });
        
        editor.addCommand('mtp_insert_table', function() {
            var rows = [
                {label: '', value: ''},
                {label: '', value: ''},
                {label: '', value: ''}
            ];
            
            var modal = editor.windowManager.open({
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
                        name: 'rows_container',
                        html: '<div id="mtp-table-rows-container" style="max-height:400px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;padding:15px;background:#f9f9f9;margin-top:15px;"></div>'
                    },
                    {
                        type: 'container',
                        html: '<button type="button" id="mtp-add-row-btn" style="margin-top:15px;padding:12px 24px;background:#0073aa;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:14px;font-weight:bold;width:calc(100% - 0px);max-width:100%;box-sizing:border-box;display:block;overflow:hidden;text-overflow:ellipsis;">+ Добавить строку</button>'
                    },
                    {
                        type: 'container',
                        html: '<p style="margin:15px 0 0 0;color:#666;font-size:12px;line-height:1.5;">Заполните категорию и значение для каждой строки таблицы. Нажмите "Добавить строку" для создания новых строк.</p>'
                    }
                ],
                width: 800,
                height: 700,
                onopen: function() {
                    var self = this;
                    
                    // Ждем, пока модальное окно полностью откроется
                    setTimeout(function() {
                        var modalBody = self.getEl();
                        if (!modalBody) {
                            setTimeout(arguments.callee, 50);
                            return;
                        }
                        
                        var container = modalBody.querySelector('#mtp-table-rows-container');
                        var addBtn = modalBody.querySelector('#mtp-add-row-btn');
                        
                        if (!container || !addBtn) {
                            setTimeout(arguments.callee, 50);
                            return;
                        }
                        
                        // Функция для рендеринга строк
                        function renderRows() {
                            if (!container) return;
                            
                            var html = '';
                            rows.forEach(function(row, index) {
                                var labelEsc = (row.label || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                                var valueEsc = (row.value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                                
                                html += '<div class="mtp-row-item" data-index="' + index + '" style="margin-bottom:15px;padding:15px;background:#fff;border:1px solid #ddd;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
                                html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">';
                                html += '<strong style="color:#333;font-size:14px;">Строка ' + (index + 1) + '</strong>';
                                if (rows.length > 1) {
                                    html += '<button type="button" class="mtp-remove-row-btn" data-index="' + index + '" style="padding:6px 12px;background:#dc3232;color:#fff;border:none;border-radius:3px;cursor:pointer;font-size:12px;font-weight:bold;">Удалить</button>';
                                }
                                html += '</div>';
                                html += '<div style="display:flex;gap:12px;align-items:flex-start;">';
                                html += '<div style="flex:1;">';
                                html += '<label style="display:block;margin-bottom:5px;color:#555;font-size:13px;font-weight:600;">Категория:</label>';
                                html += '<input type="text" class="mtp-row-label-input" data-index="' + index + '" value="' + labelEsc + '" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:14px;" placeholder="Например: Топ-1000 Легенды" />';
                                html += '</div>';
                                html += '<div style="flex:1;">';
                                html += '<label style="display:block;margin-bottom:5px;color:#555;font-size:13px;font-weight:600;">Значение:</label>';
                                html += '<input type="text" class="mtp-row-value-input" data-index="' + index + '" value="' + valueEsc + '" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:14px;" placeholder="Например: 2,929,000" />';
                                html += '</div>';
                                html += '</div>';
                                html += '</div>';
                            });
                            
                            // Добавляем кнопку "Добавить строку" в конец контейнера
                            html += '<button type="button" id="mtp-add-row-btn-inner" style="margin-top:15px;padding:12px 24px;background:#0073aa;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:14px;font-weight:bold;width:calc(100% - 0px);max-width:100%;box-sizing:border-box;display:block;overflow:hidden;text-overflow:ellipsis;">+ Добавить строку</button>';
                            
                            container.innerHTML = html;
                            
                            // Обработчик для кнопки внутри контейнера
                            var addBtnInner = container.querySelector('#mtp-add-row-btn-inner');
                            if (addBtnInner) {
                                addBtnInner.onclick = function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    rows.push({label: '', value: ''});
                                    renderRows();
                                    setTimeout(function() {
                                        if (container) {
                                            container.scrollTop = container.scrollHeight;
                                        }
                                    }, 100);
                                    return false;
                                };
                            }
                            
                            // Обработчики удаления строк
                            var removeButtons = container.querySelectorAll('.mtp-remove-row-btn');
                            for (var i = 0; i < removeButtons.length; i++) {
                                (function(btn) {
                                    btn.onclick = function(e) {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        var idx = parseInt(this.getAttribute('data-index'));
                                        if (idx >= 0 && idx < rows.length) {
                                            rows.splice(idx, 1);
                                            renderRows();
                                        }
                                        return false;
                                    };
                                })(removeButtons[i]);
                            }
                            
                            // Обработчики изменения значений
                            var labelInputs = container.querySelectorAll('.mtp-row-label-input');
                            for (var j = 0; j < labelInputs.length; j++) {
                                (function(input) {
                                    input.oninput = function() {
                                        var idx = parseInt(this.getAttribute('data-index'));
                                        if (idx >= 0 && idx < rows.length) {
                                            rows[idx].label = this.value;
                                        }
                                    };
                                })(labelInputs[j]);
                            }
                            
                            var valueInputs = container.querySelectorAll('.mtp-row-value-input');
                            for (var k = 0; k < valueInputs.length; k++) {
                                (function(input) {
                                    input.oninput = function() {
                                        var idx = parseInt(this.getAttribute('data-index'));
                                        if (idx >= 0 && idx < rows.length) {
                                            rows[idx].value = this.value;
                                        }
                                    };
                                })(valueInputs[k]);
                            }
                        }
                        
                        // Кнопка добавления строки (внешняя, скрываем её)
                        addBtn.style.display = 'none';
                        
                        // Первоначальный рендеринг
                        renderRows();
                    }, 300);
                },
                onsubmit: function(e) {
                    var color = e.data.color || 'default';
                    
                    // Фильтруем пустые строки
                    var validRows = rows.filter(function(row) {
                        return (row.label && row.label.trim() !== '') || (row.value && row.value.trim() !== '');
                    });
                    
                    if (validRows.length === 0) {
                        alert('Пожалуйста, заполните хотя бы одну строку таблицы');
                        return false;
                    }
                    
                    // ВАЖНО: Используем [row] вместо \n для надежности
                    var content = validRows.map(function(row) {
                        var label = (row.label || '').trim();
                        var value = (row.value || '').trim();
                        return label + '|' + value;
                    }).join('[row]');
                    
                    var shortcode = '[table color="' + color + '"]' + content + '[/table]';
                    editor.insertContent(shortcode);
                }
            });
        });
    });
})();




