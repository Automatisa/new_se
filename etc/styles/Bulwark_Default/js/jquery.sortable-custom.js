/*
* HTML5 Sortable jQuery Plugin
* http://farhadi.ir/projects/html5sortable
*
* Copyright 2012, Ali Farhadi
* Released under the MIT license.
*
* Sentora custom (parche 2026): robustez/optimización sin cambiar el comportamiento:
*  - regex de método corregida  /^(enable|disable|destroy)$/
*  - .sortable() IDEMPOTENTE: des-bindea handlers .h5s y elimina el placeholder previo
*    antes de remontar (evita handlers duplicados -> doble 'sortupdate'/guardado AJAX)
*  - destroy limpia el placeholder de la colección global (evita fuga de memoria)
*  - mousedown/mouseup -> .on(...'.h5s') (no deprecado en jQuery 3, y limpiable)
*  - eliminado código muerto de IE (selectstart + this.dragDrop()); dataTransfer usa
*    'text/plain' (estándar) en vez de 'Text' (legacy)
*  - guarda contra arrastres externos (dragging null) en dragover/drop
*  Nota: sigue usando Drag&Drop nativo HTML5 -> sin soporte táctil (limitación del lib).
*/ (function($) {
    var dragging, placeholders = $();
    $.fn.sortable = function(options) {
        var method = String(options);
        options = $.extend({
            connectWith: false,
            handle: false,
            forcePlaceholderSize: false,
            onStartDrag: function(){},
            onEndDrag: function(){},
            onChangeOrder:  function(){}
        }, options);
        return this.each(function() {
            if (/^(enable|disable|destroy)$/.test(method)) {
                var items = $(this)
                    .children($(this)
                    .data('items'))
                    .attr('draggable', method == 'enable');
                if (method == 'destroy') {
                    items.add(this)
                        .removeData('connectWith items')
                        .off('.h5s');
                    // Quitar el placeholder de este contenedor de la colección global.
                    var ph = $(this).data('placeholder');
                    if (ph) { placeholders = placeholders.not(ph); ph.remove(); }
                    $(this).removeData('placeholder');
                }
                return;
            }
            var isHandle, index, items = $(this)
                .children(options.items);

            // Idempotencia: si este contenedor ya se inicializó, limpiar antes de remontar
            // para no acumular handlers (que provocaban doble disparo del guardado).
            var oldPh = $(this).data('placeholder');
            if (oldPh) { placeholders = placeholders.not(oldPh); oldPh.remove(); }
            items.off('.h5s');
            $(this).off('.h5s');

            var placeholder = $('<' + (/^ul|ol$/i.test(this.tagName) ? 'li' : 'div') + ' class="module-box sortable-placeholder">');
            items.find(options.handle)
                .off('.h5s')
                .on('mousedown.h5s', function() {
                isHandle = true;
            })
                .on('mouseup.h5s', function() {
                isHandle = false;
            });
            $(this)
                .data('items', options.items)
                .data('placeholder', placeholder)
            placeholders = placeholders.add(placeholder);
            if (options.connectWith) {
                $(options.connectWith)
                    .add(this)
                    .data('connectWith', options.connectWith);
            }
            items.attr('draggable', 'true')
                .on('dragstart.h5s', function(e) {
                if (options.handle && !isHandle) {
                    return false;
                }
                options.onStartDrag();
                placeholder.css("height",$(this).height()+"px");
                isHandle = false;
                var dt = e.originalEvent.dataTransfer;
                dt.effectAllowed = 'move';
                dt.setData('text/plain', 'dummy');
                index = (dragging = $(this))
                    .addClass('sortable-dragging')
                    .index();
            })
                .on('dragend.h5s', function() {
                if (!dragging) {
                    return;
                }
                options.onEndDrag();
                dragging.removeClass('sortable-dragging')
                    .show();
                placeholders.detach();
                if (index != dragging.index()) {
                    dragging.parent()
                        .trigger('sortupdate', {
                        item: dragging
                    });
                }
                dragging = null;
            })
                .add([this, placeholder])
                .on('dragover.h5s dragenter.h5s drop.h5s', function(e) {
                // Ignorar arrastres externos (no iniciados por este plugin): sin esto,
                // $(dragging) sería $(null) al evaluar la condición de connectWith.
                if (!dragging) {
                    return true;
                }
                if (!items.is(dragging) && options.connectWith !== $(dragging)
                    .parent()
                    .data('connectWith')) {
                    return true;
                }
                if (e.type == 'drop') {
                    e.stopPropagation();
                    placeholders.filter(':visible')
                        .after(dragging);
                    dragging.trigger('dragend.h5s');
                    return false;
                }
                e.preventDefault();
                e.originalEvent.dataTransfer.dropEffect = 'move';
                if (items.is(this)) {
                    if (options.forcePlaceholderSize) {
                        placeholder.height(dragging.outerHeight());
                    }
                    dragging.hide();
                    $(this)[placeholder.index() < $(this)
                        .index() ? 'after' : 'before'](placeholder);
                    placeholders.not(placeholder)
                        .detach();
                } else if (!placeholders.is(this) && !$(this)
                    .children(options.items)
                    .length) {
                    placeholders.detach();
                    $(this)
                        .append(placeholder);
                }
                options.onChangeOrder();
                return false;
            });
        });
    };
})(jQuery);
