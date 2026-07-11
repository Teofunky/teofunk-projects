import crypto from 'node:crypto';
import { parse as parseCookie, serialize as serializeCookie } from 'cookie';
import { db, getUserById, nowIso } from './db.js';

const COOKIE_NAME = 'jj_session';
const SESSION_DAYS = Math.max(1, Number(process.env.SESSION_DAYS || 30));
const PEPPER = process.env.PASSWORD_PEPPER || '';

function scryptAsync(password, salt) {
  return new Promise((resolve, reject) => {
    crypto.scrypt(`${password}${PEPPER}`, salt, 64, { N: 32768, r: 8, p: 1, maxmem: 96 * 1024 * 1024 }, (err, key) => {
      if (err) reject(err); else resolve(key);
    });
  });
}

export async function hashPassword(password) {
  const salt = crypto.randomBytes(16);
  const key = await scryptAsync(password, salt);
  return { salt: salt.toString('base64'), hash: key.toString('base64') };
}

export async function verifyPassword(password, saltB64, hashB64) {
  const actual = await scryptAsync(password, Buffer.from(saltB64, 'base64'));
  const expected = Buffer.from(hashB64, 'base64');
  return actual.length === expected.length && crypto.timingSafeEqual(actual, expected);
}

function tokenHash(token) {
  return crypto.createHash('sha256').update(token).digest('hex');
}

export function createSession(userId) {
  const token = crypto.randomBytes(32).toString('base64url');
  const createdAt = nowIso();
  const expiresAt = new Date(Date.now() + SESSION_DAYS * 86400000).toISOString();
  db.prepare('INSERT INTO sessions(token_hash,user_id,expires_at,created_at) VALUES(?,?,?,?)')
    .run(tokenHash(token), userId, expiresAt, createdAt);
  return { token, expiresAt };
}

export function setSessionCookie(res, token, expiresAt) {
  res.setHeader('Set-Cookie', serializeCookie(COOKIE_NAME, token, {
    httpOnly: true,
    secure: process.env.COOKIE_SECURE === 'true' || process.env.NODE_ENV === 'production',
    sameSite: 'strict',
    path: '/',
    expires: new Date(expiresAt),
  }));
}

export function clearSessionCookie(res) {
  res.setHeader('Set-Cookie', serializeCookie(COOKIE_NAME, '', {
    httpOnly: true,
    secure: process.env.COOKIE_SECURE === 'true' || process.env.NODE_ENV === 'production',
    sameSite: 'strict',
    path: '/',
    expires: new Date(0),
  }));
}

export function sessionFromRequest(req) {
  const cookies = parseCookie(req.headers.cookie || '');
  const token = cookies[COOKIE_NAME];
  if (!token) return null;
  const session = db.prepare('SELECT * FROM sessions WHERE token_hash = ? AND expires_at > ?')
    .get(tokenHash(token), nowIso());
  if (!session) return null;
  const user = getUserById(session.user_id);
  return user ? { user, tokenHash: tokenHash(token) } : null;
}

export function requireAuth(req, res, next) {
  const session = sessionFromRequest(req);
  if (!session) return res.status(401).json({ error: 'Требуется вход' });
  req.auth = session;
  next();
}

export function requireSameOrigin(req, res, next) {
  if (['GET', 'HEAD', 'OPTIONS'].includes(req.method)) return next();
  const marker = req.get('x-jj-request');
  const origin = req.get('origin');
  const host = req.get('host');
  if (marker !== '1') return res.status(403).json({ error: 'Запрос отклонён' });
  if (origin) {
    try {
      const parsed = new URL(origin);
      if (parsed.host !== host) return res.status(403).json({ error: 'Неверный источник запроса' });
    } catch {
      return res.status(403).json({ error: 'Неверный источник запроса' });
    }
  }
  next();
}
