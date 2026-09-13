import { registerPlugin } from '@capacitor/core';

// Native plugins bundled in the Android app (see mobile/). Only loaded inside the app.
export const App = registerPlugin('App');
export const NativeApp = registerPlugin('One2OneNative');

export { SystemBars, SystemBarsStyle } from '@capacitor/core';
