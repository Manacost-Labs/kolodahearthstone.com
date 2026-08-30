(function () {
    'use strict';

    var config = window.KH_ARTICLE_FEEDBACK || {};
    var emailCookieName = 'kh_feedback_email';
    var emailCookieMaxAge = 60 * 60 * 24 * 180;

    function readEmailCookie() {
        var prefix = emailCookieName + '=';
        var parts = document.cookie ? document.cookie.split(';') : [];

        for (var index = 0; index < parts.length; index += 1) {
            var item = parts[index].trim();
            if (item.indexOf(prefix) !== 0) {
                continue;
            }
            try {
                return decodeURIComponent(item.slice(prefix.length)).trim();
            } catch (error) {
                return '';
            }
        }
        return '';
    }

    function writeEmailCookie(email) {
        document.cookie = emailCookieName
            + '=' + encodeURIComponent(email)
            + '; Max-Age=' + emailCookieMaxAge
            + '; Path=/; SameSite=Lax; Secure';
    }

    function clearEmailCookie() {
        document.cookie = emailCookieName
            + '=; Max-Age=0; Path=/; SameSite=Lax; Secure';
    }

    function init() {
        var form = document.querySelector('.kh-article-feedback__form');

        if (!form || !config.ajaxUrl || !config.nonce) {
            return;
        }

        var emailInput = form.querySelector('input[name="email"]');
        var ratingInput = form.querySelector('input[name="rating"]');
        var ratingButtons = Array.from(form.querySelectorAll('[data-kh-rating]'));
        var submitButton = form.querySelector('button[type="submit"]');
        var status = form.querySelector('.kh-article-feedback__status');

        if (emailInput) {
            var savedEmail = readEmailCookie();
            if (
                savedEmail
                && savedEmail.length <= 254
                && !emailInput.value
            ) {
                emailInput.value = savedEmail;
            }

            emailInput.addEventListener('input', function () {
                if (!emailInput.value.trim()) {
                    clearEmailCookie();
                }
            });

            emailInput.addEventListener('change', function () {
                var email = emailInput.value.trim();
                if (!email) {
                    clearEmailCookie();
                } else if (emailInput.checkValidity()) {
                    writeEmailCookie(email);
                }
            });
        }

        function setStatus(message, state) {
            status.textContent = message || '';
            status.dataset.state = state || '';
            status.hidden = !message;
        }

        function selectRating(rating) {
            ratingInput.value = String(rating);

            ratingButtons.forEach(function (button) {
                var value = Number(button.dataset.khRating || 0);
                var selected = value <= rating;
                button.classList.toggle('is-selected', selected);
                button.setAttribute(
                    'aria-pressed',
                    value === rating ? 'true' : 'false'
                );
            });

            setStatus('', '');
        }

        ratingButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                selectRating(Number(button.dataset.khRating || 0));
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!emailInput || !emailInput.value.trim() || !emailInput.checkValidity()) {
                setStatus(
                    config.emailRequired || 'Введите корректный email.',
                    'error'
                );
                if (emailInput) {
                    emailInput.focus();
                }
                return;
            }

            if (!ratingInput.value) {
                setStatus(
                    config.ratingRequired || 'Выберите оценку от 1 до 5 звёзд.',
                    'error'
                );
                ratingButtons[0].focus();
                return;
            }

            var formData = new FormData(form);
            formData.set('action', 'kh_submit_article_feedback');
            formData.set('nonce', config.nonce);
            formData.set('post_id', String(config.postId || 0));

            form.setAttribute('aria-busy', 'true');
            submitButton.disabled = true;
            setStatus(config.sending || 'Отправляем отзыв…', 'loading');

            window.fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
                headers: { Accept: 'application/json' }
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok || !data.success) {
                            throw new Error(
                                data && data.data && data.data.message
                                    ? data.data.message
                                    : ''
                            );
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    var message = data && data.data && data.data.message
                        ? data.data.message
                        : (config.success || 'Спасибо! Ваш отзыв сохранён.');

                    if (emailInput && emailInput.value.trim()) {
                        writeEmailCookie(emailInput.value.trim());
                    }
                    form.classList.add('is-submitted');
                    form.querySelectorAll('button, textarea, input').forEach(function (field) {
                        field.disabled = true;
                    });
                    setStatus(message, 'success');
                })
                .catch(function (error) {
                    setStatus(
                        error.message || config.error || 'Не удалось отправить отзыв. Попробуйте ещё раз.',
                        'error'
                    );
                    submitButton.disabled = false;
                })
                .finally(function () {
                    form.removeAttribute('aria-busy');
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
