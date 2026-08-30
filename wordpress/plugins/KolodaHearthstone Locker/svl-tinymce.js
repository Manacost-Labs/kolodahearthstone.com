(function(){
    function rnd(n){
        var s = '', c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        for (var i = 0; i < n; i++) s += c[Math.floor(Math.random() * c.length)];
        return s;
    }
    if (typeof tinymce === 'undefined') return;

    tinymce.PluginManager.add('svl_vip', function(editor) {
        editor.addButton('svl_vip_btn', {
            text: '🔒 VIP',
            tooltip: 'Вставить VIP-замок',
            icon: false,
            onclick: function() {
                var win = editor.windowManager.open({
                    title: 'Вставить VIP-замок',
                    width: 640,
                    height: 480,
                    body: [
                        {
                            type: 'textbox',
                            name: 'code',
                            label: 'Код доступа',
                            value: rnd(8),
                            size: 60
                        },
                        {
                            type: 'textbox',
                            name: 'teaser',
                            label: 'Публичный тизер (для SEO и краулеров)',
                            value: '',
                            multiline: true,
                            minHeight: 70,
                            size: 60
                        },
                        {
                            type: 'textbox',
                            name: 'inner',
                            label: 'Закрытый контент',
                            value: 'Закрытый контент здесь',
                            multiline: true,
                            minHeight: 100,
                            size: 60
                        }
                    ],
                    buttons: [
                        {
                            text: '🎲 Новый код',
                            onclick: function() {
                                var data = win.toJSON();
                                win.fromJSON({ code: rnd(8), teaser: data.teaser, inner: data.inner });
                            }
                        },
                        {
                            text: 'Вставить',
                            subtype: 'primary',
                            onclick: function() {
                                var data = win.toJSON();
                                var code = (data.code || '').trim() || rnd(8);
                                var teaser = (data.teaser || '').trim();
                                var inner = (data.inner || '').trim() || 'Закрытый контент здесь';
                                var attrs = 'code="' + code.replace(/"/g, '') + '"';
                                if (teaser) attrs += ' teaser="' + teaser.replace(/"/g, '&quot;') + '"';
                                editor.insertContent('[vip_locker ' + attrs + ']\n' + inner + '\n[/vip_locker]');
                                win.close();
                            }
                        },
                        {
                            text: 'Отмена',
                            onclick: function() { win.close(); }
                        }
                    ]
                });
            }
        });
    });
})();
