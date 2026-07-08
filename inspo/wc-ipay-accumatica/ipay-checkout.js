const ipaySettings = window.wc.wcSettings.getSetting( 'ipay_data', {} );
const ipayLabel = window.wp.htmlEntities.decodeEntities(ipaySettings.title) || window.wp.i18n.__('iPay', 'ipay');
const ipayContent = () => {
    return window.wp.htmlEntities.decodeEntities( ipaySettings.description || '' );
};
const Ipay_Block_Gateway = {
    name: ipaySettings.id,
    label: window.wp.element.createElement(() =>
        window.wp.element.createElement(
            "span",
            null,            
            ipaySettings.title,
            window.wp.element.createElement("img", {
                src: ipaySettings.icon,
                alt: ipayLabel
            })
        )
    ),
    content: Object( window.wp.element.createElement )( ipayContent, null ),
    edit: Object( window.wp.element.createElement )( ipayContent, null ),
    canMakePayment: () => true,
    ariaLabel: ipayLabel,
    supports: {
        features: ipaySettings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod( Ipay_Block_Gateway );