import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

const target = path.resolve('.env');
if (fs.existsSync(target)) {
  console.error('.env уже существует. Удалите его только если хотите создать новую конфигурацию.');
  process.exit(1);
}
const invite = crypto.randomBytes(18).toString('base64url');
const pepper = crypto.randomBytes(32).toString('base64url');
const content = `PORT=8080\nNODE_ENV=production\nINVITE_CODE=${invite}\nSESSION_DAYS=30\nPASSWORD_PEPPER=${pepper}\nVAPID_SUBJECT=mailto:admin@your-domain.example\nCOOKIE_SECURE=true\n`;
fs.writeFileSync(target, content, { mode: 0o600 });
console.log('Конфигурация J&Joy создана.');
console.log(`Код приглашения для двух пользователей: ${invite}`);
console.log('Перед публикацией замените VAPID_SUBJECT и используйте HTTPS.');
