import { registerPlugin } from '@capacitor/core';

// Native plugins bundled in the Android app (see mobile/). Only loaded inside the app.
export const App = registerPlugin('App');
export const NativeApp = registerPlugin('One2OneNative');
// Google Play Billing (Y2): coins and plans bought inside the Android app.
export const NativeBilling = registerPlugin('One2OneBilling');

export { SystemBars, SystemBarsStyle } from '@capacitor/core';
