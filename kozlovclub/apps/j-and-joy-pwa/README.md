# J&Joy

J&Joy is a private two-person encrypted PWA messenger. It supports text, photos, video and files, local history search, a media archive, real-time delivery, read status, typing/presence, Web Push, install-to-home-screen and a separate app-lock password.

## Security model

- Account passwords are hashed on the server with `scrypt` and a unique salt.
- Each user generates X25519 and Ed25519 key pairs in the browser.
- Private keys are encrypted in a vault using Argon2id + XChaCha20-Poly1305 before being uploaded.
- The app-lock password never leaves the device.
- Messages are encrypted in the browser with XChaCha20-Poly1305 using an X25519-derived shared key.
- Message envelopes are signed with Ed25519.
- The peer public keys are pinned on first contact; a later key change blocks the session until the safety code is checked.
- Files are encrypted chunk-by-chunk with libsodium secretstream before upload.
- Push notifications contain no message text, only a generic alert.

This is a production-minded MVP, not an independently audited replacement for Signal. Before high-risk use, commission a security review, deploy behind HTTPS, protect backups and keep dependencies patched.

## Run locally

Requirements: Node.js 22.5+.

```bash
npm install
npm run setup
# For local HTTP development, set NODE_ENV=development and COOKIE_SECURE=false in .env
npm run dev
```

Open `http://localhost:5173`. The Vite development server proxies API and WebSocket traffic to `http://localhost:8080`.

Register the first user, then the second user with the same `INVITE_CODE`. Registration is closed automatically after two accounts.

## Production

```bash
npm install
npm run setup
# Set your real VAPID_SUBJECT in .env
npm run build
npm start
```

Docker is also included:

```bash
docker compose up -d --build
```

The server serves `client/dist` on `PORT` (default 8080). Put it behind a TLS reverse proxy such as Caddy, Nginx or a managed HTTPS host. Web Push and service workers require HTTPS, except on localhost.

## iPhone and Android

- iPhone/iPad: open the HTTPS address in Safari, Share → Add to Home Screen, launch J&Joy from the new icon, then enable notifications in Settings.
- Android: open the HTTPS address in Chrome, choose Install app/Add to Home screen, launch it, then enable notifications.

## Environment variables

See `.env.example`. The server generates VAPID keys on first launch if none are supplied and stores them in `server/data/vapid.json`.
