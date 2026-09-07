(function (wp) {
    if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components || !wp.hooks) return;

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var InnerBlocks = wp.blockEditor.InnerBlocks;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;
    var Notice = wp.components.Notice;

    wp.hooks.addFilter('blocks.registerBlockType', 'fem/scene-editor', function (settings, name) {
        if (name !== 'fem/scene') return settings;

        settings.edit = function (props) {
            var attributes = props.attributes || {};
            var blockProps = useBlockProps({ className: 'fem-scene-editor-preview' });
            var hasBinding = Boolean(attributes.femId && attributes.sourceHash);
            return el(Fragment, null,
                el(InspectorControls, null,
                    el(PanelBody, { title: 'FEM Scene', initialOpen: true },
                        el(TextControl, {
                            label: 'ID FEM',
                            value: attributes.femId || '',
                            onChange: function (value) { props.setAttributes({ femId: value }); },
                            help: 'Identificativo stabile del nodo importato da Figma.'
                        }),
                        el(TextControl, {
                            label: 'Versione schema',
                            value: attributes.schemaVersion || '1.0.0',
                            onChange: function (value) { props.setAttributes({ schemaVersion: value }); }
                        }),
                        el(TextControl, {
                            label: 'Hash sorgente',
                            value: attributes.sourceHash || '',
                            onChange: function (value) { props.setAttributes({ sourceHash: value }); }
                        }),
                        el(Notice, { status: hasBinding ? 'success' : 'warning', isDismissible: false }, hasBinding ? 'Binding FEM presente.' : 'Binding FEM incompleto: il blocco resta modificabile, ma la provenienza non è completamente tracciata.')
                    )
                ),
                el('div', blockProps, el(InnerBlocks))
            );
        };
        settings.save = function () { return null; };
        return settings;
    });
})(window.wp);
