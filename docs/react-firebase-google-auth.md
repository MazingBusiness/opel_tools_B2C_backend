# React: Google sign-in with Firebase

OPEL B2C does **not** talk to Google OAuth directly. The shop uses [Firebase Authentication](https://firebase.google.com/docs/auth/web/google-signin) in the browser, then sends the Firebase **ID token** to this API. The API verifies the token and returns the same Sanctum Bearer token as email/phone OTP.

```
React → Firebase Google popup → idToken → POST /api/v1/auth/google → { token, user, profile_complete }
```

Backend setup (Laravel): put a Firebase **service account** JSON on the server and set `FIREBASE_CREDENTIALS` (path or JSON) and `FIREBASE_PROJECT_ID` in `.env`. Until those are set, `POST /api/v1/auth/google` returns 503.

## 1. Firebase console

1. Create or open a Firebase project (same project as the Laravel service account).
2. Add a **Web** app; copy the firebaseConfig object (`apiKey`, `authDomain`, `projectId`, …).
3. Authentication → Sign-in method → enable **Google**.
4. Authentication → Settings → **Authorized domains**: `localhost` and the production shop host.

Do not put the service account JSON in the React app. Only the web config belongs on the client.

## 2. Install

```bash
npm install firebase
```

## 3. Initialize and sign in

```js
import { initializeApp } from 'firebase/app'
import { getAuth, GoogleAuthProvider, signInWithPopup } from 'firebase/auth'

const app = initializeApp({
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
  appId: import.meta.env.VITE_FIREBASE_APP_ID,
})

const auth = getAuth(app)
const provider = new GoogleAuthProvider()

export async function signInWithGoogle() {
  const result = await signInWithPopup(auth, provider)
  const idToken = await result.user.getIdToken()

  const response = await fetch(`${import.meta.env.VITE_API_URL}/api/v1/auth/google`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ id_token: idToken }),
  })

  if (!response.ok) {
    throw new Error('Google sign-in failed')
  }

  const data = await response.json()
  // data.token — Sanctum Bearer token (store like OTP login)
  // data.user, data.profile_complete
  return data
}
```

Use `signInWithRedirect` instead of popup if the shop is a mobile WebView that blocks popups.

## 4. After login

- Persist `data.token` the same way as OTP verify.
- Send `Authorization: Bearer <token>` on `/api/v1/auth/me`, cart, checkout, etc.
- If `profile_complete` is false, send the user to the profile screen (`PATCH /api/v1/auth/profile`). Google usually supplies `name` and `avatar`, so this is often already true.
- Logout: `POST /api/v1/auth/logout` with the Bearer token, and `signOut(auth)` on Firebase so the Google session does not auto-replay.

## 5. Env (React)

```
VITE_API_URL=http://127.0.0.1:8000
VITE_FIREBASE_API_KEY=
VITE_FIREBASE_AUTH_DOMAIN=
VITE_FIREBASE_PROJECT_ID=
VITE_FIREBASE_APP_ID=
```

`VITE_FIREBASE_PROJECT_ID` must match Laravel `FIREBASE_PROJECT_ID`.
