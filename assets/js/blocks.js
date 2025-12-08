const vb_mia_settings = window.wc.wcSettings.getSetting('victoriabank_mia_data', {});
const vb_mia_title = window.wp.htmlEntities.decodeEntities(vb_mia_settings.title);

const vb_mia_content = () => {
    return window.wp.htmlEntities.decodeEntities(vb_mia_settings.description || '');
};

const vb_mia_label = () => {
    let icon = vb_mia_settings.icon
        ? window.wp.element.createElement(
            'img',
            {
                alt: vb_mia_title,
                title: vb_mia_title,
                src: vb_mia_settings.icon,
                style: { float: 'right', paddingRight: '1em' }
            }
        )
        : null;

    let label = window.wp.element.createElement(
        'span',
        icon ? { style: { width: '100%' } } : null,
        vb_mia_title,
        icon
    );

    return label;
};

const vb_mia_blockGateway = {
    name: vb_mia_settings.id,
    label: Object(window.wp.element.createElement)(vb_mia_label, null),
    icons: [{id: 'mia', alt: vb_mia_settings.title, src: vb_mia_settings.icon}],
    content: Object(window.wp.element.createElement)(vb_mia_content, null),
    edit: Object(window.wp.element.createElement)(vb_mia_content, null),
    canMakePayment: () => true,
    ariaLabel: vb_mia_title,
    supports: {
        features: vb_mia_settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(vb_mia_blockGateway);
