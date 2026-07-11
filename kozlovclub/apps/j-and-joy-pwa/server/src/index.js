import 'dotenv/config';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import express from 'express';
import helmet from 'helmet';
import { rateLimit } from 'express-rate-limit';
import { Server as SocketIOServer } from 'socket.io';
import { z } from 'zod';
import {
  db, nowIso, publicUser, getPeer, getUserByUsername,
  attachmentDir, tempDir, cleanExpiredSessions,
} from './db.js';
import {
  hashPassword, verifyPassword, createSession, setSessionCookie,
  clearSessionCookie, requireAuth, requireSameOrigin, sessionFromRequest,
} from './auth.js';
import { vapidKeys, saveSubscription, removeSubscription, notifyUser } from './push.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const app = express();
app.disable('x-powered-by');
app.set('trust proxy', 1);
app.use(helmet({
  crossOriginResourcePolicy: { policy: 'same-origin' },
  contentSecurityPolicy: process.env.NODE_ENV === 'production' ? {
    directives: {
      defaultSrc: ["'self'"],
      scriptSrc: ["'self'"],
      styleSrc: ["'self'", "'unsafe-inline'"],
      imgSrc: ["'self'", 'blob:', 'data:'],
      mediaSrc: ["'self'", 'blob:'],
      connectSrc: ["'self'", 'wss:', 'ws:'],
      workerSrc: ["'self'"],
      objectSrc: ["'none'"],
      baseUri: ["'self'"],
      frameAncestors: ["'none'"],
    },
  } : false,
}));
app.use(express.json({ limit: '1mb' }));
app.use(requireSameOrigin);

const authLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  limit: 20,
  standardHeaders: 'draft-8',
  legacyHeaders: false,
  message: { error: 'Слишком много попыток. Повторите позже.' },
});

const usernameSchema = z.string().trim().toLowerCase().min(3).max(32).regex(/^[a-z0-9_.-]+$/);
const keySchema = z.string().min(40).max(160);
const vaultSchema = z.object({
  v: z.literal(1),
  salt: z.string().min(16).max(128),
  nonce: z.string().min(16).max(128),
  ciphertext: z.string().min(80).max(5000),
});

let io;
const onlineCounts = new Map();

function apiUserPayload(user) {
  const peer = getPeer(user.id);
  return {
    user: publicUser(user),
    peer: publicUser(peer),
    vault: JSON.parse(user.vault_json),
    registrationOpen: db.prepare('SELECT COUNT(*) AS n FROM users').get().n < 2,
  };
}

app.get('/api/status', (req, res) => {
  const count = db.prepare('SELECT COUNT(*) AS n FROM users').get().n;
  res.json({ name: 'J&Joy', registrationOpen: count < 2, registeredUsers: count });
});

app.post('/api/register', authLimiter, async (req, res, next) => {
  try {
    const schema = z.object({
      username: usernameSchema,
      displayName: z.string().trim().min(1).max(50),
      accountPassword: z.string().min(10).max(200),
      inviteCode: z.string().min(1).max(300),
      boxPublicKey: keySchema,
      signPublicKey: keySchema,
      vault: vaultSchema,
    });
    const input = schema.parse(req.body);
    if (!process.env.INVITE_CODE || input.inviteCode !== process.env.INVITE_CODE) {
      return res.status(403).json({ error: 'Неверный код приглашения' });
    }
    const count = db.prepare('SELECT COUNT(*) AS n FROM users').get().n;
    if (count >= 2) return res.status(409).json({ error: 'В J&Joy уже зарегистрированы два пользователя' });
    if (getUserByUsername(input.username)) return res.status(409).json({ error: 'Это имя уже занято' });

    const { salt, hash } = await hashPassword(input.accountPassword);
    const result = db.prepare(`INSERT INTO users(
      username,display_name,password_salt,password_hash,box_public_key,sign_public_key,vault_json,created_at
    ) VALUES(?,?,?,?,?,?,?,?)`).run(
      input.username, input.displayName, salt, hash,
      input.boxPublicKey, input.signPublicKey, JSON.stringify(input.vault), nowIso(),
    );
    const user = db.prepare('SELECT * FROM users WHERE id = ?').get(Number(result.lastInsertRowid));
    const session = createSession(user.id);
    setSessionCookie(res, session.token, session.expiresAt);
    res.status(201).json(apiUserPayload(user));
  } catch (error) { next(error); }
});

app.post('/api/login', authLimiter, async (req, res, next) => {
  try {
    const input = z.object({
      username: usernameSchema,
      accountPassword: z.string().min(1).max(200),
    }).parse(req.body);
    const user = getUserByUsername(input.username);
    if (!user || !(await verifyPassword(input.accountPassword, user.password_salt, user.password_hash))) {
      return res.status(401).json({ error: 'Неверное имя или пароль' });
    }
    const session = createSession(user.id);
    setSessionCookie(res, session.token, session.expiresAt);
    res.json(apiUserPayload(user));
  } catch (error) { next(error); }
});

app.post('/api/logout', requireAuth, (req, res) => {
  db.prepare('DELETE FROM sessions WHERE token_hash = ?').run(req.auth.tokenHash);
  clearSessionCookie(res);
  res.status(204).end();
});

app.get('/api/me', requireAuth, (req, res) => res.json(apiUserPayload(req.auth.user)));

app.get('/api/messages', requireAuth, (req, res) => {
  const before = req.query.before ? Number(req.query.before) : Number.MAX_SAFE_INTEGER;
  const limit = Math.min(500, Math.max(1, Number(req.query.limit || 200)));
  const rows = db.prepare(`SELECT * FROM messages
    WHERE id < ? AND (sender_id = ? OR recipient_id = ?)
    ORDER BY id DESC LIMIT ?`).all(before, req.auth.user.id, req.auth.user.id, limit).reverse();
  res.json({ messages: rows.map(messageRow) });
});

function messageRow(row) {
  return {
    id: row.id,
    clientId: row.client_id,
    senderId: row.sender_id,
    recipientId: row.recipient_id,
    nonce: row.nonce,
    ciphertext: row.ciphertext,
    signature: row.signature,
    createdAt: row.created_at,
    readAt: row.read_at,
  };
}

app.post('/api/messages', requireAuth, async (req, res, next) => {
  try {
    const input = z.object({
      clientId: z.string().uuid(),
      recipientId: z.number().int().positive(),
      nonce: z.string().min(20).max(100),
      ciphertext: z.string().min(20).max(2_000_000),
      signature: z.string().min(40).max(300),
    }).parse(req.body);
    const peer = getPeer(req.auth.user.id);
    if (!peer || peer.id !== input.recipientId) return res.status(400).json({ error: 'Получатель недоступен' });

    const createdAt = nowIso();
    try {
      const result = db.prepare(`INSERT INTO messages(client_id,sender_id,recipient_id,nonce,ciphertext,signature,created_at)
        VALUES(?,?,?,?,?,?,?)`).run(
        input.clientId, req.auth.user.id, input.recipientId,
        input.nonce, input.ciphertext, input.signature, createdAt,
      );
      const row = db.prepare('SELECT * FROM messages WHERE id = ?').get(Number(result.lastInsertRowid));
      const payload = messageRow(row);
      io.to(`user:${input.recipientId}`).emit('message:new', payload);
      res.status(201).json(payload);
      notifyUser(input.recipientId, {
        title: 'J&Joy',
        body: `Новое защищённое сообщение от ${req.auth.user.display_name}`,
        url: '/',
        tag: 'jj-message',
      }).catch(console.error);
    } catch (error) {
      if (String(error.message).includes('UNIQUE')) {
        const existing = db.prepare('SELECT * FROM messages WHERE client_id = ?').get(input.clientId);
        return res.json(messageRow(existing));
      }
      throw error;
    }
  } catch (error) { next(error); }
});

app.post('/api/messages/read', requireAuth, (req, res, next) => {
  try {
    const { ids } = z.object({ ids: z.array(z.number().int().positive()).max(500) }).parse(req.body);
    if (!ids.length) return res.status(204).end();
    const placeholders = ids.map(() => '?').join(',');
    const readAt = nowIso();
    const rows = db.prepare(`SELECT id,sender_id FROM messages WHERE id IN (${placeholders}) AND recipient_id = ? AND read_at IS NULL`)
      .all(...ids, req.auth.user.id);
    if (rows.length) {
      const updateIds = rows.map(r => r.id);
      const updateMarks = updateIds.map(() => '?').join(',');
      db.prepare(`UPDATE messages SET read_at = ? WHERE id IN (${updateMarks})`).run(readAt, ...updateIds);
      const senderIds = [...new Set(rows.map(r => r.sender_id))];
      senderIds.forEach(senderId => io.to(`user:${senderId}`).emit('messages:read', { ids: updateIds, readAt }));
    }
    res.status(204).end();
  } catch (error) { next(error); }
});

app.post('/api/attachments/init', requireAuth, (req, res) => {
  const id = crypto.randomUUID();
  db.prepare('INSERT INTO attachments(id,owner_id,status,created_at) VALUES(?,?,?,?)')
    .run(id, req.auth.user.id, 'uploading', nowIso());
  fs.mkdirSync(path.join(tempDir, id), { recursive: true });
  res.status(201).json({ id });
});

app.put('/api/attachments/:id/chunks/:index', requireAuth,
  express.raw({ type: 'application/octet-stream', limit: '3mb' }), (req, res, next) => {
  try {
    const id = z.string().uuid().parse(req.params.id);
    const index = z.coerce.number().int().min(0).max(2000).parse(req.params.index);
    const row = db.prepare('SELECT * FROM attachments WHERE id = ?').get(id);
    if (!row || row.owner_id !== req.auth.user.id || row.status !== 'uploading') {
      return res.status(404).json({ error: 'Загрузка не найдена' });
    }
    if (!Buffer.isBuffer(req.body) || !req.body.length) return res.status(400).json({ error: 'Пустой фрагмент' });
    fs.writeFileSync(path.join(tempDir, id, `${index}.part`), req.body, { mode: 0o600 });
    res.status(204).end();
  } catch (error) { next(error); }
});

app.post('/api/attachments/:id/complete', requireAuth, (req, res, next) => {
  try {
    const id = z.string().uuid().parse(req.params.id);
    const { chunkCount } = z.object({ chunkCount: z.number().int().min(1).max(2001) }).parse(req.body);
    const row = db.prepare('SELECT * FROM attachments WHERE id = ?').get(id);
    if (!row || row.owner_id !== req.auth.user.id || row.status !== 'uploading') {
      return res.status(404).json({ error: 'Загрузка не найдена' });
    }
    const dir = path.join(tempDir, id);
    const finalPath = path.join(attachmentDir, `${id}.bin`);
    const fd = fs.openSync(finalPath, 'w', 0o600);
    let total = 0;
    try {
      for (let i = 0; i < chunkCount; i += 1) {
        const partPath = path.join(dir, `${i}.part`);
        if (!fs.existsSync(partPath)) throw new Error(`Отсутствует фрагмент ${i}`);
        const part = fs.readFileSync(partPath);
        fs.writeSync(fd, part);
        total += part.length;
      }
    } finally { fs.closeSync(fd); }
    fs.rmSync(dir, { recursive: true, force: true });
    db.prepare('UPDATE attachments SET status=?,chunk_count=?,byte_size=? WHERE id=?')
      .run('complete', chunkCount, total, id);
    res.json({ id, encryptedBytes: total, chunkCount });
  } catch (error) { next(error); }
});

app.get('/api/attachments/:id', requireAuth, (req, res, next) => {
  try {
    const id = z.string().uuid().parse(req.params.id);
    const row = db.prepare('SELECT * FROM attachments WHERE id = ? AND status = ?').get(id, 'complete');
    if (!row) return res.status(404).json({ error: 'Файл не найден' });
    res.setHeader('Content-Type', 'application/octet-stream');
    res.setHeader('Content-Length', String(row.byte_size));
    res.setHeader('Cache-Control', 'private, no-store');
    res.setHeader('Content-Disposition', `attachment; filename="${id}.bin"`);
    fs.createReadStream(path.join(attachmentDir, `${id}.bin`)).pipe(res);
  } catch (error) { next(error); }
});

app.get('/api/push/public-key', requireAuth, (req, res) => res.json({ publicKey: vapidKeys.publicKey }));
app.post('/api/push/subscribe', requireAuth, (req, res, next) => {
  try {
    const subscription = z.object({
      endpoint: z.string().url().max(4000),
      expirationTime: z.number().nullable().optional(),
      keys: z.object({ p256dh: z.string(), auth: z.string() }),
    }).parse(req.body);
    saveSubscription(req.auth.user.id, subscription);
    res.status(204).end();
  } catch (error) { next(error); }
});
app.delete('/api/push/subscribe', requireAuth, (req, res, next) => {
  try {
    const { endpoint } = z.object({ endpoint: z.string().url() }).parse(req.body);
    removeSubscription(endpoint);
    res.status(204).end();
  } catch (error) { next(error); }
});

const clientDist = path.resolve(__dirname, '../../client/dist');
if (fs.existsSync(clientDist)) {
  app.use(express.static(clientDist, { index: false, maxAge: '1h' }));
  app.use((req, res, next) => {
    if (req.method !== 'GET' || req.path.startsWith('/api/') || req.path.startsWith('/socket.io/')) return next();
    res.setHeader('Cache-Control', 'no-cache');
    res.sendFile(path.join(clientDist, 'index.html'));
  });
}

app.use((error, req, res, next) => {
  if (error instanceof z.ZodError) return res.status(400).json({ error: 'Проверьте введённые данные', details: error.issues });
  if (error?.type === 'entity.too.large') return res.status(413).json({ error: 'Фрагмент файла слишком большой' });
  console.error(error);
  res.status(500).json({ error: process.env.NODE_ENV === 'production' ? 'Внутренняя ошибка' : String(error.message || error) });
});

const port = Number(process.env.PORT || 8080);
const server = app.listen(port, () => {
  cleanExpiredSessions();
  console.log(`J&Joy server: http://localhost:${port}`);
  if (!process.env.INVITE_CODE) console.warn('WARNING: set INVITE_CODE before registering users.');
  if (!process.env.PASSWORD_PEPPER) console.warn('WARNING: set PASSWORD_PEPPER in production.');
});

io = new SocketIOServer(server, { serveClient: false, maxHttpBufferSize: 1e6 });
io.use((socket, next) => {
  const session = sessionFromRequest(socket.request);
  if (!session) return next(new Error('unauthorized'));
  socket.user = session.user;
  next();
});

io.on('connection', (socket) => {
  const userId = socket.user.id;
  socket.join(`user:${userId}`);
  onlineCounts.set(userId, (onlineCounts.get(userId) || 0) + 1);
  const peer = getPeer(userId);
  if (peer) {
    io.to(`user:${peer.id}`).emit('presence', { userId, online: true });
    socket.emit('presence', { userId: peer.id, online: (onlineCounts.get(peer.id) || 0) > 0 });
  }

  socket.on('typing', (isTyping) => {
    const target = getPeer(userId);
    if (target) io.to(`user:${target.id}`).emit('typing', { userId, isTyping: Boolean(isTyping) });
  });

  socket.on('disconnect', () => {
    const count = Math.max(0, (onlineCounts.get(userId) || 1) - 1);
    if (count) onlineCounts.set(userId, count); else onlineCounts.delete(userId);
    const target = getPeer(userId);
    if (target && count === 0) io.to(`user:${target.id}`).emit('presence', { userId, online: false });
  });
});
