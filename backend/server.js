'use strict';
/*
 * Lumen — референс лид-эндпоинта.
 * POST /api/lead → валидация → доставка (Telegram / email) → { ok: true }
 * Требует Node.js 18+ (встроенный fetch). Запуск: npm install && npm start
 */
require('dotenv').config();
const express = require('express');
const cors = require('cors');
const rateLimit = require('express-rate-limit');

const app = express();
app.disable('x-powered-by');
app.set('trust proxy', 1); // корректный req.ip за прокси/хостингом
app.use(express.json({ limit: '16kb' }));

// --- CORS: только с доменов из ALLOWED_ORIGINS (через запятую) ---
const allowed = (process.env.ALLOWED_ORIGINS || '')
  .split(',').map((s) => s.trim()).filter(Boolean);
app.use(cors({ origin: allowed.length ? allowed : true, methods: ['POST', 'GET'] }));

// --- Rate limit: 5 заявок в минуту с одного IP ---
const leadLimiter = rateLimit({ windowMs: 60 * 1000, max: 5, standardHeaders: true, legacyHeaders: false });

app.get('/health', (_req, res) => res.json({ ok: true }));

app.post('/api/lead', leadLimiter, async (req, res) => {
  const body = req.body || {};
  const name = String(body.name || '').trim();
  const contact = String(body.contact || '').trim();
  const message = String(body.message || '').trim();
  const honeypot = String(body.company || '').trim();

  // Бот заполнил скрытое поле — делаем вид, что всё ок, но ничего не шлём.
  if (honeypot) return res.json({ ok: true });

  const errors = [];
  if (name.length < 2 || name.length > 80) errors.push('name');
  if (contact.length < 3 || contact.length > 200) errors.push('contact');
  if (message.length > 4000) errors.push('message');
  if (errors.length) return res.status(400).json({ ok: false, errors });

  const lead = { name, contact, message, at: new Date().toISOString(), ip: req.ip };

  const results = await Promise.allSettled([notifyTelegram(lead), notifyEmail(lead)]);
  const delivered = results.some((r) => r.status === 'fulfilled' && r.value === true);
  const failed = results.find((r) => r.status === 'rejected');

  if (!delivered) {
    if (failed) console.error('Доставка не удалась:', failed.reason);
    else console.error('Не настроен ни один канал доставки (Telegram/email).');
    return res.status(500).json({ ok: false, error: 'delivery_failed' });
  }
  return res.json({ ok: true });
});

// --- Telegram Bot API. Возвращает true, если реально отправлено. ---
async function notifyTelegram(lead) {
  const token = process.env.TELEGRAM_BOT_TOKEN;
  const chatId = process.env.TELEGRAM_CHAT_ID;
  if (!token || !chatId) return false;

  const text =
    '🔔 Новая заявка с сайта Lumen\n\n' +
    `👤 Имя: ${lead.name}\n` +
    `📇 Контакт: ${lead.contact}\n` +
    `📝 Задача: ${lead.message || '—'}\n` +
    `🕒 ${lead.at}`;

  const r = await fetch(`https://api.telegram.org/bot${token}/sendMessage`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ chat_id: chatId, text, disable_web_page_preview: true }),
  });
  if (!r.ok) throw new Error('Telegram API ' + r.status + ': ' + (await r.text()));
  return true;
}

// --- Email через SMTP (nodemailer). Возвращает true, если реально отправлено. ---
async function notifyEmail(lead) {
  if (!process.env.SMTP_HOST) return false;
  const nodemailer = require('nodemailer');
  const transport = nodemailer.createTransport({
    host: process.env.SMTP_HOST,
    port: Number(process.env.SMTP_PORT || 587),
    secure: Number(process.env.SMTP_PORT) === 465,
    auth: { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS },
  });
  await transport.sendMail({
    from: process.env.SMTP_FROM || process.env.SMTP_USER,
    to: process.env.LEAD_EMAIL_TO || 'fggtf24@gmail.com',
    subject: `Новая заявка — Lumen (${lead.name})`,
    text: `Имя: ${lead.name}\nКонтакт: ${lead.contact}\nЗадача: ${lead.message || '—'}\nВремя: ${lead.at}\nIP: ${lead.ip}`,
  });
  return true;
}

const port = process.env.PORT || 3000;
app.listen(port, () => console.log(`Lumen lead API → http://localhost:${port}`));
