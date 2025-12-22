const victoriabank_mia_settings = window.wc.wcSettings.getSetting('victoriabank_mia_data', {});
const victoriabank_mia_title = window.wp.htmlEntities.decodeEntities(victoriabank_mia_settings.title);

const victoriabank_mia_content = () => {
    return window.wp.htmlEntities.decodeEntities(victoriabank_mia_settings.description || '');
};

const victoriabank_mia_label = () => {
    let icon = victoriabank_mia_settings.icon
        ? window.wp.element.createElement(
            'img',
            {
                alt: victoriabank_mia_title,
                title: victoriabank_mia_title,
                src: victoriabank_mia_settings.icon,
                style: { float: 'right', paddingRight: '1em' }
            }
        )
        : null;

    let label = window.wp.element.createElement(
        'span',
        icon ? { style: { width: '100%' } } : null,
        victoriabank_mia_title,
        icon
    );

    return label;
};

const victoriabank_mia_blockGateway = {
    name: victoriabank_mia_settings.id,
    label: Object(window.wp.element.createElement)(victoriabank_mia_label, null),
    icons: [{id: 'mia', alt: victoriabank_mia_settings.title, src: victoriabank_mia_settings.icon}],
    content: Object(window.wp.element.createElement)(victoriabank_mia_content, null),
    edit: Object(window.wp.element.createElement)(victoriabank_mia_content, null),
    canMakePayment: () => true,
    ariaLabel: victoriabank_mia_title,
    supports: {
        features: victoriabank_mia_settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(victoriabank_mia_blockGateway);
