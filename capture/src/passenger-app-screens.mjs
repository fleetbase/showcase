// capture/src/passenger-app-screens.mjs
//
// Every screenshot the Passenger app's Maestro flows (passenger-app/.maestro/screenshots) are
// expected to produce, in the order a rider walks through the app: grouped into the gallery's
// sections. The gallery lays them out from this list, so a screen the flows didn't reach shows as
// a gap instead of silently disappearing. Each name is captured dark (unsuffixed) and light
// (-light) on iOS and Android.

export const PASSENGER_APP_SECTIONS = [
    {
        key: 'booking',
        title: 'Booking',
        note: 'Sign-in with a texted code, then a trip from Marina Bay Sands to the rider’s saved Home.',
        screens: [
            ['passenger-app-login-create', 'Sign in'],
            ['passenger-app-login-create-code', 'Verification code'],
            ['passenger-app-booking-create', 'Home'],
            ['passenger-app-booking-create-destination', 'Drop-off'],
            ['passenger-app-quotes-list', 'Car classes and fares'],
            ['passenger-app-ride-details-searching', 'Finding a driver'],
        ],
    },
    {
        key: 'trip',
        title: 'The trip',
        note: 'Taken by a seeded driver through the Fleetbase API while the app follows it.',
        screens: [
            ['passenger-app-ride-details-en-route', 'Driver on the way'],
            ['passenger-app-ride-details-arrived', 'Driver arrived'],
            ['passenger-app-ride-details-in-progress', 'Trip in progress'],
            ['passenger-app-fare-details', 'Final fare'],
            ['passenger-app-rating-create', 'Rating'],
        ],
    },
    {
        key: 'account',
        title: 'Account',
        note: 'The rider’s account, with the seeded trip history.',
        screens: [
            ['passenger-app-account-details', 'Account'],
            ['passenger-app-trips-list', 'Trips'],
            ['passenger-app-trip-details', 'Past trip'],
            ['passenger-app-receipt-details', 'Receipt'],
            ['passenger-app-places-list', 'Saved places'],
            ['passenger-app-help-details', 'Help'],
        ],
    },
];

export const PASSENGER_APP_PLATFORMS = ['ios', 'android'];
export const PASSENGER_APP_THEMES = [
    ['dark', ''],
    ['light', '-light'],
];
