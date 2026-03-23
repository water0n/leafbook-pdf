/**
 * Archivo: blocks/flipbook-block/index.js
 *
 * ¿Qué hace?
 * Registra el bloque "Flipbook" en el editor Gutenberg.
 * Cuando el editor carga, este script le dice a WordPress:
 *   - Cómo se llama el bloque
 *   - Qué controles tiene en el panel lateral (Inspector)
 *   - Qué muestra en el editor (preview)
 *   - Qué guarda en la base de datos (save)
 *
 * IMPORTANTE: Este archivo NO usa JSX ni necesita compilación.
 * Usa wp.element.createElement() directamente para ser compatible
 * con cualquier WordPress sin necesitar Node.js / npm.
 */

(function (blocks, element, blockEditor, components, data) {
    'use strict';

    var el               = element.createElement;
    var registerBlockType = blocks.registerBlockType;
    var InspectorControls = blockEditor.InspectorControls;
    var useBlockProps     = blockEditor.useBlockProps;
    var PanelBody         = components.PanelBody;
    var SelectControl     = components.SelectControl;
    var RangeControl      = components.RangeControl;
    var Placeholder       = components.Placeholder;
    var Spinner           = components.Spinner;
    var useSelect         = data.useSelect;

    // ============================================================
    // REGISTRAR EL BLOQUE
    // ============================================================
    registerBlockType('flipbook-manager/flipbook', {

        // ---- EDIT: Lo que VE el editor (no se guarda en la DB) ----
        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;

            // Carga la lista de flipbooks publicados desde la API REST de WordPress
            var flipbooks = useSelect(function (select) {
                return select('core').getEntityRecords('postType', 'flipbook', {
                    per_page: 100,
                    status:   'publish',
                    _fields:  'id,title',
                });
            }, []);

            var blockProps = useBlockProps({
                className: 'fbm-bloque-editor',
            });

            // ---- Opciones para el SelectControl ----
            var opciones = [{ label: '— Elige un flipbook —', value: 0 }];
            if (flipbooks) {
                flipbooks.forEach(function (fb) {
                    opciones.push({
                        label: fb.title.rendered || fb.title.raw || 'Flipbook #' + fb.id,
                        value: fb.id,
                    });
                });
            }

            // ---- Panel lateral (Inspector) ----
            var panelLateral = el(
                InspectorControls,
                null,

                el(PanelBody,
                    { title: '📖 Configuración', initialOpen: true },

                    // Selector de flipbook
                    el(SelectControl, {
                        label:    'Publicación',
                        value:    attributes.flipbookId,
                        options:  opciones,
                        onChange: function (valor) {
                            setAttributes({ flipbookId: parseInt(valor, 10) });
                        },
                        help: 'Selecciona qué revista mostrar.',
                    }),

                    // Control de ancho
                    el(RangeControl, {
                        label:    'Ancho (px)',
                        value:    attributes.ancho,
                        min:      400,
                        max:      1400,
                        step:     10,
                        onChange: function (valor) {
                            setAttributes({ ancho: valor });
                        },
                        help: 'Ancho máximo del visor.',
                    }),

                    // Control de alto
                    el(RangeControl, {
                        label:    'Alto (px)',
                        value:    attributes.alto,
                        min:      300,
                        max:      900,
                        step:     10,
                        onChange: function (valor) {
                            setAttributes({ alto: valor });
                        },
                        help: 'Altura del visor.',
                    })
                )
            );

            // ---- Área de previsualización en el editor ----
            var preview;

            if (!flipbooks) {
                // Cargando la lista...
                preview = el('div', { className: 'fbm-editor-loading' },
                    el(Spinner),
                    el('span', null, ' Cargando flipbooks...')
                );

            } else if (attributes.flipbookId === 0) {
                // No se ha elegido ninguno todavía
                preview = el(Placeholder, {
                        icon:  'book-alt',
                        label: 'Flipbook',
                        instructions: 'Elige una publicación en el panel lateral derecho →',
                    },
                    el(SelectControl, {
                        value:    attributes.flipbookId,
                        options:  opciones,
                        onChange: function (valor) {
                            setAttributes({ flipbookId: parseInt(valor, 10) });
                        },
                    })
                );

            } else {
                // Flipbook seleccionado — muestra una preview
                var flipbookElegido = flipbooks
                    ? flipbooks.find(function (fb) { return fb.id === attributes.flipbookId; })
                    : null;

                var titulo = flipbookElegido
                    ? (flipbookElegido.title.rendered || flipbookElegido.title.raw)
                    : 'Flipbook #' + attributes.flipbookId;

                preview = el('div', { className: 'fbm-editor-preview' },
                    el('div', { className: 'fbm-editor-preview-icono' }, '📖'),
                    el('p',   { className: 'fbm-editor-preview-titulo' }, titulo),
                    el('p',   { className: 'fbm-editor-preview-info' },
                        attributes.ancho + ' × ' + attributes.alto + ' px'
                    ),
                    el('p',   { className: 'fbm-editor-preview-shortcode' },
                        '[flipbook id="' + attributes.flipbookId + '"]'
                    ),
                    el('p', { className: 'fbm-editor-preview-nota' },
                        '✅ El visor se mostrará en el frontend cuando publiques.'
                    )
                );
            }

            // ---- Retorna: panel lateral + preview en el editor ----
            return el(
                'div',
                blockProps,
                panelLateral,
                preview
            );
        },


        // ---- SAVE: Lo que se GUARDA en la base de datos ----
        // Devuelve null porque el bloque es "dinámico":
        // el HTML final lo genera PHP en tiempo real (ver class-flipbook-block.php)
        save: function () {
            return null;
        },
    });

}(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.data
));
