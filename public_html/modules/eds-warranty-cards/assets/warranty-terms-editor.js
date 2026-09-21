(function () {
    'use strict';

    var form = document.querySelector('form[action$="/module-manager/eds-warranty-cards/settings"]');
    var textarea = document.getElementById('eds_warranty_cards_terms_html');
    var shell = document.getElementById('eds-warranty-cards-quill');
    var editor = document.getElementById('eds-warranty-cards-quill-editor');
    var stylesheet = document.getElementById('eds-warranty-cards-quill-stylesheet');

    if (!form || !textarea || !shell || !editor || !stylesheet) {
        return;
    }
    if (typeof window.Quill !== 'function' || !stylesheet.sheet) {
        return;
    }

    var quill;
    try {
        quill = new window.Quill(editor, {
            theme: 'snow',
            modules: {
                toolbar: '#eds-warranty-cards-quill-toolbar'
            },
            formats: ['bold', 'list']
        });

        var initial = textarea.value;
        if (initial !== '') {
            quill.setContents(quill.clipboard.convert({ html: initial, text: '' }), 'silent');
        }
        quill.root.setAttribute('aria-labelledby', 'eds-warranty-cards-terms-label');
        quill.root.setAttribute('aria-describedby', 'eds-warranty-cards-terms-help');
    } catch (error) {
        shell.hidden = true;
        textarea.hidden = false;
        return;
    }

    shell.hidden = false;
    textarea.hidden = true;

    form.addEventListener('submit', function () {
        textarea.value = quill.getSemanticHTML(0, quill.getLength());
    });
}());
