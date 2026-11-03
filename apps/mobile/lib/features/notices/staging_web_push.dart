import 'package:firebase_core/firebase_core.dart';

// Public Firebase client configuration; the Admin SDK private key stays on the server.
const stagingWebFirebase = FirebaseOptions(
  apiKey: 'AIzaSyCnlelFSN7BqFBJJRomb8iLKnJCfu5ChZw',
  authDomain: 'evcs-power-solutions-staging.firebaseapp.com',
  projectId: 'evcs-power-solutions-staging',
  storageBucket: 'evcs-power-solutions-staging.firebasestorage.app',
  messagingSenderId: '516894406407',
  appId: '1:516894406407:web:637b4bac0240a39fda37a2',
);

const stagingWebVapidKey =
    'BDa-8MAbZm8fV84u8DRO3I5Qlnk4uo8x2JE6vfWehPeUBI4b-R_rkdYhArRkCMKdbQZ3sZ2rE0W3UFJxkuS3w-M';
