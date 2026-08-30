(function($) {
    'use strict';
    
    // Функция для обновления результатов опроса без перезагрузки страницы
    function updatePollResults($wrapper, results) {
        var total = results.total || 0;
        
        // Находим все кнопки опций в этом опросе
        $wrapper.find('.khs-poll-option').each(function() {
            var $option = $(this);
            var optionKey = $option.data('option');
            
            // Получаем количество голосов для этой опции
            var votes = results[optionKey] || 0;
            var percent = total > 0 ? Math.round((votes / total) * 100) : 0;
            
            var $votes = $option.find('.khs-poll-option-votes');
            var $bar = $option.find('.khs-poll-bar');
            var $barContainer = $option.find('.khs-poll-bar-container');
            
            // Обновляем или создаем элемент с количеством голосов
            if ($votes.length) {
                $votes.text(votes + ' голосов (' + percent + '%)');
            } else {
                $option.append('<span class="khs-poll-option-votes">' + votes + ' голосов (' + percent + '%)</span>');
            }
            
            // Обновляем или создаем полосу прогресса с анимацией
            if ($barContainer.length === 0) {
                $option.append('<div class="khs-poll-bar-container"><div class="khs-poll-bar" style="width: 0%"></div></div>');
                // Анимируем заполнение прогресс-бара
                setTimeout(function() {
                    $option.find('.khs-poll-bar').css('width', percent + '%');
                }, 50);
            } else {
                if ($bar.length) {
                    // Сбрасываем ширину для анимации
                    $bar.css('width', '0%');
                    setTimeout(function() {
                        $bar.css('width', percent + '%');
                    }, 50);
                } else {
                    $barContainer.html('<div class="khs-poll-bar" style="width: 0%"></div>');
                    setTimeout(function() {
                        $barContainer.find('.khs-poll-bar').css('width', percent + '%');
                    }, 50);
                }
            }
        });
    }
    
    $(document).ready(function() {
        // Обработчик голосования
        $(document).on('click', '.khs-poll-option:not(:disabled)', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var $option = $(this);
            var $wrapper = $option.closest('.khs-poll-wrapper');
            var pollId = $wrapper.data('poll-id');
            var option = $option.data('option');
            
            if (!pollId || !option) {
                return;
            }
            
            // Блокируем кнопки только в этом опросе
            $wrapper.find('.khs-poll-option').prop('disabled', true).addClass('khs-poll-loading');
            
            // Очищаем предыдущее сообщение
            $wrapper.find('.khs-poll-message').removeClass('success error').text('').hide();
            
            // Отправляем AJAX запрос
            $.ajax({
                url: khsPoll.ajaxurl,
                type: 'POST',
                data: {
                    action: 'khs_poll_vote',
                    poll_id: pollId,
                    option: option,
                    nonce: khsPoll.nonce
                },
                cache: false,
                timeout: 10000,
                success: function(response) {
                    if (response.success && response.data && response.data.results) {
                        // Обновляем результаты на странице без перезагрузки
                        updatePollResults($wrapper, response.data.results);
                        
                        // Убираем класс загрузки (кнопки остаются disabled, но без спиннера)
                        $wrapper.find('.khs-poll-option').removeClass('khs-poll-loading');
                        
                        // Добавляем кнопку отмены голоса, если её нет
                        if (!$wrapper.find('.khs-poll-cancel-vote').length) {
                            $wrapper.find('.khs-poll-options').after('<button type="button" class="khs-poll-cancel-vote" data-poll-id="' + pollId + '">Отменить голос</button>');
                        }
                        
                        // Показываем сообщение кратковременно
                        var $message = $wrapper.find('.khs-poll-message');
                        $message.text(response.data.message || 'Ваш голос учтен!').addClass('success').removeClass('error').fadeIn(200);
                        
                        // Скрываем сообщение через 2 секунды
                        setTimeout(function() {
                            $message.fadeOut(300);
                        }, 2000);
                    } else {
                        $wrapper.find('.khs-poll-message').text(response.data && response.data.message ? response.data.message : 'Ошибка при голосовании').addClass('error').removeClass('success').show();
                        $wrapper.find('.khs-poll-option').prop('disabled', false).removeClass('khs-poll-loading');
                    }
                },
                error: function(xhr, status, error) {
                    $wrapper.find('.khs-poll-message').text('Ошибка при отправке голоса. Попробуйте еще раз.').addClass('error').removeClass('success').show();
                    $wrapper.find('.khs-poll-option').prop('disabled', false).removeClass('khs-poll-loading');
                }
            });
        });
        
        // Обработчик отмены голоса
        $(document).on('click', '.khs-poll-cancel-vote', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var $button = $(this);
            var $wrapper = $button.closest('.khs-poll-wrapper');
            var pollId = $button.data('poll-id');
            
            if (!pollId) {
                return;
            }
            
            // Блокируем кнопку
            $button.prop('disabled', true).addClass('khs-poll-loading');
            
            // Очищаем предыдущее сообщение
            $wrapper.find('.khs-poll-message').removeClass('success error').text('').hide();
            
            // Отправляем AJAX запрос
            $.ajax({
                url: khsPoll.ajaxurl,
                type: 'POST',
                data: {
                    action: 'khs_poll_cancel_vote',
                    poll_id: pollId,
                    nonce: khsPoll.nonce
                },
                cache: false,
                timeout: 10000,
                success: function(response) {
                    if (response.success && response.data && response.data.results) {
                        // Обновляем результаты перед удалением элементов
                        updatePollResults($wrapper, response.data.results);
                        
                        // Удаляем результаты с кнопок только если total = 0
                        if (!response.data.results.total || response.data.results.total === 0) {
                            $wrapper.find('.khs-poll-option-votes').remove();
                            $wrapper.find('.khs-poll-bar-container').remove();
                        }
                        
                        // Удаляем кнопку отмены
                        $button.remove();
                        
                        // Разблокируем кнопки голосования
                        $wrapper.find('.khs-poll-option').prop('disabled', false).removeClass('khs-poll-loading');
                        
                        // Показываем сообщение кратковременно
                        var $message = $wrapper.find('.khs-poll-message');
                        $message.text(response.data.message || 'Ваш голос отменен').addClass('success').removeClass('error').fadeIn(200);
                        
                        // Скрываем сообщение через 2 секунды
                        setTimeout(function() {
                            $message.fadeOut(300);
                        }, 2000);
                    } else {
                        $wrapper.find('.khs-poll-message').text(response.data && response.data.message ? response.data.message : 'Ошибка при отмене голоса').addClass('error').removeClass('success').show();
                        $button.prop('disabled', false).removeClass('khs-poll-loading');
                    }
                },
                error: function(xhr, status, error) {
                    $wrapper.find('.khs-poll-message').text('Ошибка при отмене голоса. Попробуйте еще раз.').addClass('error').removeClass('success').show();
                    $button.prop('disabled', false).removeClass('khs-poll-loading');
                }
            });
        });
    });
})(jQuery);

