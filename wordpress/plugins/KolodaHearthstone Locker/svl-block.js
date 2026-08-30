(function(blocks, element, blockEditor, components, i18n) {
    var el = element.createElement;
    var __ = i18n.__;
    var Fragment = element.Fragment;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var TextControl = components.TextControl;
    var TextareaControl = components.TextareaControl;
    var SelectControl = components.SelectControl;
    var ToggleControl = components.ToggleControl;
    var Button = components.Button;

    function rnd(n){
        var s='', c='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        for (var i=0;i<n;i++) s+=c[Math.floor(Math.random()*c.length)];
        return s;
    }

    blocks.registerBlockType('svl/locker', {
        title: 'VIP Locker',
        description: 'Замок для платного контента с SEO и темами оформления',
        icon: {
            background: '#e07a1f',
            foreground: '#fff',
            src: el('svg', { viewBox: '0 0 24 24', width: 24, height: 24 },
                el('path', { fill: 'currentColor', d: 'M12 2a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7a5 5 0 0 0-5-5zm0 2a3 3 0 0 1 3 3v3H9V7a3 3 0 0 1 3-3z' })
            )
        },
        category: 'widgets',
        keywords: ['vip', 'lock', 'paywall', 'замок'],
        attributes: {
            code:      { type: 'string',  default: '' },
            teaser:    { type: 'string',  default: '' },
            theme:     { type: 'string',  default: 'cream' },
            garland:   { type: 'boolean', default: true },
            image:     { type: 'string',  default: '' },
            message:   { type: 'string',  default: '' },
            content:   { type: 'string',  default: 'Закрытый контент здесь' },
            seo_title: { type: 'string',  default: '' },
            seo_desc:  { type: 'string',  default: '' },
            keywords:  { type: 'string',  default: '' }
        },

        edit: function(props) {
            var a = props.attributes;
            var setAttr = function(k){ return function(v){ var u={}; u[k]=v; props.setAttributes(u); }; };
            var themes = (window.svlBlockData && window.svlBlockData.themes) || [{value:'cream', label:'Cream'}];

            // Пред-заполнение случайным кодом при первой вставке
            if (!a.code) {
                setTimeout(function(){ if (!props.attributes.code) props.setAttributes({ code: rnd(8) }); }, 50);
            }

            var inspector = el(InspectorControls, {},
                el(PanelBody, { title: '🔑 Доступ', initialOpen: true },
                    el('div', { style: { display: 'flex', gap: '6px', alignItems: 'flex-end' } },
                        el('div', { style: { flex: 1 } },
                            el(TextControl, { label: 'Код доступа', value: a.code, onChange: setAttr('code') })
                        ),
                        el(Button, { isSecondary: true, onClick: function(){ setAttr('code')(rnd(8)); }, title: 'Сгенерировать новый' }, '🎲')
                    )
                ),
                el(PanelBody, { title: '🎨 Внешний вид', initialOpen: false },
                    el(SelectControl, { label: 'Тема', value: a.theme, options: themes, onChange: setAttr('theme') }),
                    el(ToggleControl, { label: '✨ Декоративная гирлянда', checked: a.garland, onChange: setAttr('garland') }),
                    el(TextControl, { label: 'URL картинки-баннера', value: a.image, onChange: setAttr('image'), help: 'Оставьте пустым для дефолтного' }),
                    el(TextareaControl, { label: 'Сообщение в замке', value: a.message, onChange: setAttr('message'), help: 'Оставьте пустым для дефолтного', rows: 3 })
                ),
                el(PanelBody, { title: '🔍 SEO', initialOpen: false },
                    el(TextareaControl, { label: 'Публичный тизер', value: a.teaser, onChange: setAttr('teaser'), help: 'Виден поисковикам и читателям до разблокировки', rows: 3 }),
                    el(TextControl, { label: 'SEO Title', value: a.seo_title, onChange: setAttr('seo_title') }),
                    el(TextareaControl, { label: 'Meta Description', value: a.seo_desc, onChange: setAttr('seo_desc'), rows: 2 }),
                    el(TextControl, { label: 'Keywords', value: a.keywords, onChange: setAttr('keywords') })
                )
            );

            // Превью карточки в редакторе
            var preview = el('div', {
                className: 'svl-block-edit svl-block-theme-' + (a.theme || 'cream'),
                style: {
                    border: '3px solid #8b6332',
                    borderRadius: '14px',
                    padding: '18px',
                    background: 'linear-gradient(135deg,#fdf4dd,#f5e8c2)',
                    position: 'relative'
                }
            },
                el('div', { style: { fontSize: 11, textTransform: 'uppercase', letterSpacing: '.5px', color: '#92400e', marginBottom: 6, fontWeight: 700 } }, '🔒 VIP LOCKER · Тема: ' + a.theme),
                a.code && el('div', { style: { background: 'rgba(255,255,255,.6)', padding: '6px 12px', borderRadius: 6, marginBottom: 10, fontSize: 13, fontFamily: 'monospace', color: '#3d2817' } },
                    'Код: ', el('strong', null, a.code)
                ),
                a.teaser && el('div', { style: { padding: '10px 14px', background: '#fff7ed', borderLeft: '3px solid #d97706', borderRadius: '0 6px 6px 0', marginBottom: 12, fontSize: 13, color: '#5c4023', fontStyle: 'italic' } },
                    '📝 Тизер: ' + a.teaser
                ),
                el(TextareaControl, {
                    label: 'Закрытый контент (виден после разблокировки)',
                    value: a.content,
                    onChange: setAttr('content'),
                    rows: 4
                }),
                el('p', { style: { fontSize: 11, color: '#92400e', margin: '10px 0 0', textAlign: 'center', opacity: .7 } },
                    '👁 Откройте превью записи чтобы увидеть финальный вид'
                )
            );

            return el(Fragment, {}, inspector, preview);
        },

        // Динамический рендер на сервере — save вернёт null
        save: function() { return null; }
    });
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
