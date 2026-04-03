import { registerRootComponent } from 'expo';
import App from './App';
import notifee from '@notifee/react-native';

// REQUIRED: notifee background event handler
// Runs when the app is killed and user interacts with a notifee notification
notifee.onBackgroundEvent(async ({ type, detail }) => {
    // The fullScreenAction in displayNotification already launches the app.
    // This handler fires for button presses/dismissals — no extra action needed here.
    console.log('[Notifee BG] Event type:', type);
});

registerRootComponent(App);
