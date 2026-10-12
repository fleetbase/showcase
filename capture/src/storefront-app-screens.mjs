// capture/src/storefront-app-screens.mjs
//
// Every screenshot the Storefront app's Maestro flows (storefront-app/.maestro/screenshots)
// are expected to produce, by edition, in the order a person walks through the app. The
// gallery lays them out from this list, so a screen the flows didn't reach shows as a gap
// instead of silently disappearing. Each name is captured dark (unsuffixed) and light
// (-light) on iOS and Android.

export const STOREFRONT_APP_EDITIONS = [
    {
        key: 'store',
        title: 'Single store',
        note: 'A store key: the store page is home.',
        screens: [
            ['storefront-app-store-details', 'Store page'],
            ['storefront-app-product-details', 'Product'],
            ['storefront-app-category-details', 'Category'],
            ['storefront-app-products-list', 'Search results'],
            ['storefront-app-store-map', 'Store map'],
            ['storefront-app-cart-details', 'Cart'],
            ['storefront-app-login-create', 'Sign in'],
            ['storefront-app-login-create-code', 'Verification code'],
            ['storefront-app-account-details', 'Account'],
            ['storefront-app-orders-list', 'Orders'],
            ['storefront-app-order-details', 'Order in progress'],
            ['storefront-app-checkout-create', 'Checkout'],
        ],
    },
    {
        key: 'network',
        title: 'Network',
        note: 'A network key: the marketplace of member stores.',
        screens: [
            ['storefront-app-network-details', 'Marketplace home'],
            ['storefront-app-store-details-network', 'Member store'],
            ['storefront-app-product-details-network', 'Product'],
            ['storefront-app-products-list-network', 'Search results'],
            ['storefront-app-stores-map-network', 'Stores map'],
            ['storefront-app-cart-details-network', 'Cart'],
            ['storefront-app-login-create-network', 'Sign in'],
            ['storefront-app-login-create-code-network', 'Verification code'],
            ['storefront-app-account-details-network', 'Account'],
            ['storefront-app-orders-list-network', 'Orders'],
            ['storefront-app-order-details-network', 'Order in progress'],
            ['storefront-app-checkout-create-network', 'Checkout'],
        ],
    },
    {
        key: 'trucks',
        title: 'Food trucks',
        note: 'A store key with the trucks map as home.',
        screens: [
            ['storefront-app-food-trucks-map', 'Trucks map'],
            ['storefront-app-food-trucks-list', 'Trucks list'],
            ['storefront-app-food-truck-details', 'Truck menu'],
            ['storefront-app-products-list-food-trucks', 'Trucks search'],
        ],
    },
    {
        key: 'network-trucks',
        title: 'Network food trucks',
        note: "A network key with the trucks map as home: the member stores' trucks.",
        screens: [
            ['storefront-app-food-trucks-map-network', 'Trucks map'],
            ['storefront-app-food-trucks-list-network', 'Trucks list'],
            ['storefront-app-food-truck-details-network', 'Truck menu'],
            ['storefront-app-products-list-food-trucks-network', 'Trucks search'],
        ],
    },
];

export const STOREFRONT_APP_PLATFORMS = ['ios', 'android'];
export const STOREFRONT_APP_THEMES = [
    ['dark', ''],
    ['light', '-light'],
];
