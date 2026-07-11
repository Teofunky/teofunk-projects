import fs from 'node:fs';
import path from 'node:path';
import webpush from 'web-push';
import { db, dataDir, nowIso } from './db.js';

const keyFile = path.join(dataDir, 'vapid.json');

function loadKeys() {
  if (process.env.VAPID_PUBLIC_KEY && process.env.VAPID_PRIVATE_KEY) {
    return { publicKey: process.env.VAPID_PUBLIC_KEY, privateKey: process.env.VAPID_PRIVATE_KEY };
  }
  if (fs.existsSync(keyFile)) return JSON.parse(fs.readFileSync(keyFile, 'utf8'));
  const generated = webpush.generateVAPIDKeys();
  fs.writeFileSync(keyFile, JSON.stringify(generated, null, 2), { mode: 0o600 });
  return generated;
}

export const vapidKeys = loadKeys();
webpush.setVapidDetails(
  process.env.VAPID_SUBJECT || 'mailto:admin@jandjoy.app',
  vapidKeys.publicKey,
  vapidKeys.privateKey,
);

export function saveSubscription(userId, subscription) {
  const json = JSON.stringify(subscription);
  db.prepare(`INSERT INTO push_subscriptions(user_id,endpoint,subscription_json,created_at)
    VALUES(?,?,?,?) ON CONFLICT(endpoint) DO UPDATE SET user_id=excluded.user_id, subscription_json=excluded.subscription_json`)
    .run(userId, subscription.endpoint, json, nowIso());
}

export function removeSubscription(endpoint) {
  db.prepare('DELETE FROM push_subscriptions WHERE endpoint = ?').run(endpoint);
}

export async function notifyUser(userId, payload) {
  const rows = db.prepare('SELECT endpoint, subscription_json FROM push_subscriptions WHERE user_id = ?').all(userId);
  await Promise.all(rows.map(async (row) => {
    try {
      await webpush.sendNotification(JSON.parse(row.subscription_json), JSON.stringify(payload), {
        TTL: 60,
        urgency: 'high',
      });
    } catch (error) {
      if (error.statusCode === 404 || error.statusCode === 410) removeSubscription(row.endpoint);
      else console.error('Push error:', error.message);
    }
  }));
}
