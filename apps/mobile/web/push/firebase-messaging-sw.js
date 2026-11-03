// Separate /mobile/push/ scope preserves the app's existing offline/install worker.
if (self.location.origin === 'https://staging.evcspowersolutions.com') {
  importScripts('https://www.gstatic.com/firebasejs/13.0.0/firebase-app-compat.js');
  importScripts('https://www.gstatic.com/firebasejs/13.0.0/firebase-messaging-compat.js');
  firebase.initializeApp({
    apiKey: 'AIzaSyCnlelFSN7BqFBJJRomb8iLKnJCfu5ChZw',
    authDomain: 'evcs-power-solutions-staging.firebaseapp.com',
    projectId: 'evcs-power-solutions-staging',
    storageBucket: 'evcs-power-solutions-staging.firebasestorage.app',
    messagingSenderId: '516894406407',
    appId: '1:516894406407:web:637b4bac0240a39fda37a2',
  });
  // Notification payloads are displayed by FCM. Do not display a second copy.
  firebase.messaging();
}
