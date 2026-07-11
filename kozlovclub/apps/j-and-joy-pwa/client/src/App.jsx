import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { io } from 'socket.io-client';
import { api } from './api.js';
import {
  createVault, unlockVault, deriveSharedKey, encryptMessage, decryptMessage,
  encryptAndUploadFile, decryptFile, sodiumReady, peerFingerprint,
} from './crypto.js';
import { makePreview, formatBytes } from './media.js';

const MAX_FILE_BYTES = 250 * 1024 * 1024;
const AUTO_LOCK_BACKGROUND_MS = 5000;

function Icon({ name, size = 22 }) {
  const paths = {
    chat: <><path d="M4 5.8A2.8 2.8 0 0 1 6.8 3h10.4A2.8 2.8 0 0 1 20 5.8v7.4a2.8 2.8 0 0 1-2.8 2.8H10l-5.4 4v-4.8A2.8 2.8 0 0 1 4 13.2Z"/><path d="M8 8h8M8 12h5"/></>,
    media: <><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path d="m4 17 4.5-4 3.2 2.8 2.8-2.5L20 18"/></>,
    search: <><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></>,
    settings: <><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/></>,
    lock: <><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></>,
    attach: <path d="m20.5 11.5-8.3 8.3a6 6 0 0 1-8.5-8.5l8.5-8.5a4 4 0 0 1 5.7 5.7l-8.5 8.5a2 2 0 1 1-2.8-2.8l7.8-7.8"/>,
    send: <><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></>,
    file: <><path d="M6 2h8l4 4v16H6Z"/><path d="M14 2v5h5M9 13h6M9 17h5"/></>,
    play: <path d="m9 7 8 5-8 5Z"/>,
    download: <><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M5 20h14"/></>,
    close: <path d="m6 6 12 12M18 6 6 18"/>,
    bell: <><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></>,
    install: <><path d="M12 3v11m0 0 4-4m-4 4-4-4"/><path d="M5 17v4h14v-4"/></>,
    logout: <><path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/></>,
    shield: <><path d="M12 22s8-3.8 8-10V5l-8-3-8 3v7c0 6.2 8 10 8 10Z"/><path d="m9 12 2 2 4-5"/></>,
    image: <><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path d="m4 17 4.5-4 3.2 2.8 2.8-2.5L20 18"/></>,
  };
  return <svg className="icon" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name]}</svg>;
}

function Logo({ compact = false }) {
  return <div className={`brand ${compact ? 'brand-compact' : ''}`}>
    <div className="brand-mark"><span>J</span><b>&</b><span>J</span></div>
    {!compact && <div><strong>J&Joy</strong><small>private space for two</small></div>}
  </div>;
}

function initials(name = '') {
  return name.trim().split(/\s+/).slice(0, 2).map(x => x[0]?.toUpperCase()).join('') || 'J';
}

function timeLabel(value) {
  return new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

function dayLabel(value) {
  const date = new Date(value);
  const today = new Date();
  const yesterday = new Date(Date.now() - 86400000);
  const same = (a, b) => a.toDateString() === b.toDateString();
  if (same(date, today)) return 'Сегодня';
  if (same(date, yesterday)) return 'Вчера';
  return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: date.getFullYear() === today.getFullYear() ? undefined : 'numeric' }).format(date);
}

function isStandalone() {
  return window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true;
}

function isIos() {
  return /iphone|ipad|ipod/i.test(navigator.userAgent);
}

function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(base64);
  return Uint8Array.from([...raw].map(char => char.charCodeAt(0)));
}

export default function App() {
  const [stage, setStage] = useState('loading');
  const [profile, setProfile] = useState(null);
  const [registrationOpen, setRegistrationOpen] = useState(true);
  const [secrets, setSecrets] = useState(null);
  const [sharedKey, setSharedKey] = useState(null);
  const [messages, setMessages] = useState([]);
  const [activeTab, setActiveTab] = useState('chat');
  const [peerOnline, setPeerOnline] = useState(false);
  const [peerTyping, setPeerTyping] = useState(false);
  const [upload, setUpload] = useState(null);
  const [toast, setToast] = useState('');
  const [modal, setModal] = useState(null);
  const [pushEnabled, setPushEnabled] = useState(false);
  const [deferredInstall, setDeferredInstall] = useState(null);
  const [safetyNumber, setSafetyNumber] = useState('');
  const socketRef = useRef(null);
  const hiddenAt = useRef(null);
  const objectUrls = useRef(new Map());

  const showToast = useCallback((text) => {
    setToast(text);
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => setToast(''), 2800);
  }, []);

  const clearSensitiveState = useCallback(() => {
    setSecrets(null);
    setSharedKey(null);
    setMessages([]);
    setModal(null);
    for (const url of objectUrls.current.values()) URL.revokeObjectURL(url);
    objectUrls.current.clear();
    socketRef.current?.disconnect();
    socketRef.current = null;
  }, []);

  const lockApp = useCallback(() => {
    if (!profile) return;
    clearSensitiveState();
    setStage('locked');
  }, [profile, clearSensitiveState]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      await sodiumReady();
      if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
      try {
        const data = await api.me();
        if (!cancelled) { setProfile(data); setStage('locked'); }
      } catch (error) {
        if (error.status !== 401) showToast(error.message);
        try {
          const status = await api.status();
          if (!cancelled) setRegistrationOpen(status.registrationOpen);
        } catch {}
        if (!cancelled) setStage('auth');
      }
    })();
    const installHandler = event => { event.preventDefault(); setDeferredInstall(event); };
    window.addEventListener('beforeinstallprompt', installHandler);
    return () => { cancelled = true; window.removeEventListener('beforeinstallprompt', installHandler); };
  }, [showToast]);

  useEffect(() => {
    const handler = () => {
      if (document.visibilityState === 'hidden') hiddenAt.current = Date.now();
      else if (hiddenAt.current && Date.now() - hiddenAt.current >= AUTO_LOCK_BACKGROUND_MS && stage === 'ready') lockApp();
      if (document.visibilityState === 'visible') {
        navigator.clearAppBadge?.().catch?.(() => {});
      }
    };
    document.addEventListener('visibilitychange', handler);
    return () => document.removeEventListener('visibilitychange', handler);
  }, [stage, lockApp]);

  const finishUnlock = useCallback(async (profileData, unlockedSecrets) => {
    setSecrets(unlockedSecrets);
    if (profileData.peer) {
      const fingerprint = await peerFingerprint(profileData.peer);
      const pinName = `jj_peer_pin_${profileData.user.id}`;
      const pinned = localStorage.getItem(pinName);
      if (pinned && pinned !== fingerprint) {
        throw new Error('Ключ собеседника изменился. Не продолжайте, пока не сверите код безопасности.');
      }
      if (!pinned) localStorage.setItem(pinName, fingerprint);
      setSafetyNumber(fingerprint);
      const key = await deriveSharedKey(unlockedSecrets.boxPrivateKey, profileData.peer.boxPublicKey);
      setSharedKey(key);
    }
    setProfile(profileData);
    setStage('ready');
  }, []);

  const handleRegister = async (form) => {
    const created = await createVault(form.lockPassword);
    const data = await api.register({
      username: form.username,
      displayName: form.displayName,
      accountPassword: form.accountPassword,
      inviteCode: form.inviteCode,
      boxPublicKey: created.boxPublicKey,
      signPublicKey: created.signPublicKey,
      vault: created.vault,
    });
    setRegistrationOpen(data.registrationOpen);
    await finishUnlock(data, created.secrets);
  };

  const handleLogin = async (form) => {
    const data = await api.login(form);
    setProfile(data);
    setStage('locked');
  };

  const handleUnlock = async (password) => {
    const unlocked = await unlockVault(profile.vault, password);
    await finishUnlock(profile, unlocked);
  };

  useEffect(() => {
    if (stage !== 'ready' || profile?.peer || !secrets) return;
    const timer = setInterval(async () => {
      try {
        const fresh = await api.me();
        if (fresh.peer) {
          const fingerprint = await peerFingerprint(fresh.peer);
          const pinName = `jj_peer_pin_${fresh.user.id}`;
          const pinned = localStorage.getItem(pinName);
          if (pinned && pinned !== fingerprint) throw new Error('Ключ собеседника изменился');
          if (!pinned) localStorage.setItem(pinName, fingerprint);
          const key = await deriveSharedKey(secrets.boxPrivateKey, fresh.peer.boxPublicKey);
          setSafetyNumber(fingerprint);
          setProfile(fresh);
          setSharedKey(key);
          showToast(`${fresh.peer.displayName} подключён к J&Joy`);
        }
      } catch {}
    }, 5000);
    return () => clearInterval(timer);
  }, [stage, profile?.peer, secrets, showToast]);

  const decryptEnvelope = useCallback(async (envelope) => {
    if (!sharedKey || !profile?.peer) return null;
    const senderKey = envelope.senderId === profile.user.id
      ? profile.user.signPublicKey
      : profile.peer.signPublicKey;
    try {
      const content = await decryptMessage(envelope, sharedKey, senderKey);
      return { ...envelope, content, verified: true };
    } catch (error) {
      return { ...envelope, content: { type: 'error', text: error.message }, verified: false };
    }
  }, [sharedKey, profile]);

  useEffect(() => {
    if (stage !== 'ready' || !sharedKey || !profile?.peer) return;
    let cancelled = false;
    let socket;
    (async () => {
      try {
        let all = [];
        let before;
        for (let page = 0; page < 10; page += 1) {
          const data = await api.messages(before, 500);
          all = [...data.messages, ...all];
          if (data.messages.length < 500) break;
          before = data.messages[0]?.id;
        }
        const decrypted = (await Promise.all(all.map(decryptEnvelope))).filter(Boolean);
        if (!cancelled) setMessages(decrypted);
        const unread = decrypted.filter(m => m.senderId === profile.peer.id && !m.readAt).map(m => m.id);
        if (unread.length) api.readMessages(unread).catch(() => {});
      } catch (error) { showToast(error.message); }

      socket = io({ transports: ['websocket', 'polling'] });
      socketRef.current = socket;
      socket.on('message:new', async envelope => {
        const decrypted = await decryptEnvelope(envelope);
        if (!decrypted || cancelled) return;
        setMessages(current => current.some(m => m.clientId === decrypted.clientId) ? current : [...current, decrypted]);
        if (envelope.senderId === profile.peer.id) api.readMessages([envelope.id]).catch(() => {});
      });
      socket.on('messages:read', ({ ids, readAt }) => {
        setMessages(current => current.map(m => ids.includes(m.id) ? { ...m, readAt } : m));
      });
      socket.on('presence', ({ userId, online }) => {
        if (userId === profile.peer.id) setPeerOnline(online);
      });
      socket.on('typing', ({ userId, isTyping }) => {
        if (userId === profile.peer.id) setPeerTyping(isTyping);
      });
    })();
    return () => {
      cancelled = true;
      socket?.disconnect();
      if (socketRef.current === socket) socketRef.current = null;
    };
  }, [stage, sharedKey, profile?.peer?.id, decryptEnvelope, showToast]);

  useEffect(() => {
    if (stage !== 'ready') return;
    (async () => {
      try {
        const registration = await navigator.serviceWorker?.ready;
        const subscription = await registration?.pushManager?.getSubscription();
        setPushEnabled(Boolean(subscription));
      } catch {}
    })();
  }, [stage]);

  const appendSent = useCallback(async (envelope, content) => {
    setMessages(current => current.some(m => m.clientId === envelope.clientId)
      ? current
      : [...current, { ...envelope, content, verified: true }]);
  }, []);

  const sendText = async (text) => {
    const clean = text.trim();
    if (!clean || !sharedKey || !profile?.peer) return;
    const content = { type: 'text', text: clean };
    const encrypted = await encryptMessage(content, sharedKey, profile.user.id, secrets.signPrivateKey);
    const envelope = await api.sendMessage({ ...encrypted, recipientId: profile.peer.id });
    await appendSent(envelope, { v: 1, ...content });
  };

  const sendFile = async (file) => {
    if (!file || !sharedKey || !profile?.peer) return;
    if (file.size > MAX_FILE_BYTES) throw new Error('Максимальный размер файла — 250 МБ');
    setUpload({ name: file.name, percent: 0 });
    try {
      const [attachment, preview] = await Promise.all([api.initAttachment(), makePreview(file)]);
      const encryptedMeta = await encryptAndUploadFile(
        file,
        sharedKey,
        (index, body) => api.uploadChunk(attachment.id, index, body),
        percent => setUpload({ name: file.name, percent }),
      );
      await api.completeAttachment(attachment.id, encryptedMeta.chunkCount);
      const type = file.type.startsWith('image/') ? 'image' : file.type.startsWith('video/') ? 'video' : 'file';
      const content = {
        type,
        attachment: {
          id: attachment.id,
          name: file.name,
          mime: file.type || 'application/octet-stream',
          preview,
          ...encryptedMeta,
        },
      };
      const encrypted = await encryptMessage(content, sharedKey, profile.user.id, secrets.signPrivateKey);
      const envelope = await api.sendMessage({ ...encrypted, recipientId: profile.peer.id });
      await appendSent(envelope, { v: 1, ...content });
    } finally { setUpload(null); }
  };

  const getDecryptedBlob = useCallback(async (message) => {
    if (objectUrls.current.has(message.clientId)) return objectUrls.current.get(message.clientId);
    const meta = message.content.attachment;
    const encrypted = await api.getAttachment(meta.id);
    const blob = await decryptFile(encrypted, meta, sharedKey, meta.mime);
    const url = URL.createObjectURL(blob);
    objectUrls.current.set(message.clientId, url);
    return url;
  }, [sharedKey]);

  const openAttachment = async (message, forceDownload = false) => {
    try {
      setModal({ loading: true, message });
      const url = await getDecryptedBlob(message);
      const attachment = message.content.attachment;
      if (forceDownload || message.content.type === 'file') {
        const a = document.createElement('a');
        a.href = url; a.download = attachment.name; document.body.appendChild(a); a.click(); a.remove();
        setModal(null);
      } else setModal({ loading: false, message, url });
    } catch (error) { setModal(null); showToast(error.message); }
  };

  const togglePush = async () => {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      throw new Error('Push-уведомления не поддерживаются этим браузером');
    }
    if (isIos() && !isStandalone()) {
      throw new Error('На iPhone сначала добавьте J&Joy на экран «Домой» и откройте с иконки');
    }
    const registration = await navigator.serviceWorker.ready;
    const current = await registration.pushManager.getSubscription();
    if (current) {
      await api.unsubscribePush(current.endpoint);
      await current.unsubscribe();
      setPushEnabled(false);
      showToast('Push-уведомления выключены');
    } else {
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') throw new Error('Разрешение на уведомления не выдано');
      const { publicKey } = await api.pushKey();
      const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey),
      });
      await api.subscribePush(subscription.toJSON());
      setPushEnabled(true);
      showToast('Push-уведомления включены');
    }
  };

  const installApp = async () => {
    if (deferredInstall) {
      deferredInstall.prompt();
      await deferredInstall.userChoice;
      setDeferredInstall(null);
      return;
    }
    if (isIos()) showToast('Safari: «Поделиться» → «На экран Домой»');
    else showToast('Откройте меню браузера и выберите «Установить приложение»');
  };

  const logout = async () => {
    try { await api.logout(); } catch {}
    clearSensitiveState();
    setProfile(null);
    setStage('auth');
  };

  if (stage === 'loading') return <Splash />;
  if (stage === 'auth') return <AuthScreen registrationOpen={registrationOpen} onLogin={handleLogin} onRegister={handleRegister} />;
  if (stage === 'locked') return <LockScreen profile={profile} onUnlock={handleUnlock} onLogout={logout} />;
  if (!profile?.peer) return <WaitingScreen profile={profile} onLock={lockApp} onLogout={logout} />;

  return <>
    <Messenger
      profile={profile}
      messages={messages}
      activeTab={activeTab}
      setActiveTab={setActiveTab}
      peerOnline={peerOnline}
      peerTyping={peerTyping}
      upload={upload}
      pushEnabled={pushEnabled}
      installed={isStandalone()}
      onSendText={sendText}
      onSendFile={sendFile}
      onTyping={value => socketRef.current?.emit('typing', value)}
      onOpenAttachment={openAttachment}
      onLock={lockApp}
      onLogout={logout}
      onTogglePush={async () => { try { await togglePush(); } catch (e) { showToast(e.message); } }}
      onInstall={installApp}
      safetyNumber={safetyNumber}
    />
    {toast && <div className="toast">{toast}</div>}
    {modal && <MediaModal data={modal} onClose={() => setModal(null)} onDownload={() => openAttachment(modal.message, true)} />}
  </>;
}

function Splash() {
  return <main className="splash"><Logo/><div className="loader"><span/><span/><span/></div><p>Защищённое пространство загружается</p></main>;
}

function AuthScreen({ registrationOpen, onLogin, onRegister }) {
  const [mode, setMode] = useState(registrationOpen ? 'register' : 'login');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [form, setForm] = useState({ username: '', displayName: '', accountPassword: '', accountPassword2: '', lockPassword: '', lockPassword2: '', inviteCode: '' });
  const update = (key, value) => setForm(current => ({ ...current, [key]: value }));
  const submit = async event => {
    event.preventDefault(); setError(''); setBusy(true);
    try {
      if (mode === 'register') {
        if (form.accountPassword !== form.accountPassword2) throw new Error('Пароли учётной записи не совпадают');
        if (form.lockPassword !== form.lockPassword2) throw new Error('Пароли приложения не совпадают');
        if (form.accountPassword.length < 10) throw new Error('Пароль учётной записи должен содержать не менее 10 символов');
        if (form.lockPassword.length < 8) throw new Error('Пароль приложения должен содержать не менее 8 символов');
        await onRegister(form);
      } else await onLogin({ username: form.username, accountPassword: form.accountPassword });
    } catch (e) { setError(e.message); } finally { setBusy(false); }
  };
  return <main className="auth-page">
    <section className="auth-visual">
      <Logo/>
      <div className="auth-copy"><span className="eyebrow">Только для двоих</span><h1>Ваш разговор.<br/><em>Ничей больше.</em></h1><p>Текст, фотографии, видео и файлы со сквозным шифрованием, локальным поиском и отдельным паролем приложения.</p></div>
      <div className="security-strip"><Icon name="shield"/><div><strong>End-to-end encryption</strong><small>Сервер не получает ключ расшифровки</small></div></div>
    </section>
    <section className="auth-panel">
      <div className="auth-card">
        <div className="auth-mobile-logo"><Logo/></div>
        <div className="segmented">
          <button className={mode === 'login' ? 'active' : ''} onClick={() => setMode('login')}>Войти</button>
          {registrationOpen && <button className={mode === 'register' ? 'active' : ''} onClick={() => setMode('register')}>Создать аккаунт</button>}
        </div>
        <form onSubmit={submit}>
          {mode === 'register' && <Field label="Как вас называть" value={form.displayName} onChange={v => update('displayName', v)} autoComplete="name" placeholder="Имя"/>}
          <Field label="Имя пользователя" value={form.username} onChange={v => update('username', v.toLowerCase())} autoComplete="username" placeholder="latin_letters"/>
          <Field label="Пароль учётной записи" type="password" value={form.accountPassword} onChange={v => update('accountPassword', v)} autoComplete={mode === 'login' ? 'current-password' : 'new-password'} placeholder="Для входа на сервер"/>
          {mode === 'register' && <>
            <Field label="Повторите пароль учётной записи" type="password" value={form.accountPassword2} onChange={v => update('accountPassword2', v)} autoComplete="new-password"/>
            <div className="form-divider"><span>Защита ключей на устройстве</span></div>
            <Field label="Пароль приложения" type="password" value={form.lockPassword} onChange={v => update('lockPassword', v)} autoComplete="new-password" placeholder="Нужен при каждом запуске"/>
            <Field label="Повторите пароль приложения" type="password" value={form.lockPassword2} onChange={v => update('lockPassword2', v)} autoComplete="new-password"/>
            <Field label="Код приглашения" type="password" value={form.inviteCode} onChange={v => update('inviteCode', v)} autoComplete="off" placeholder="Общий секретный код"/>
          </>}
          {error && <div className="form-error">{error}</div>}
          <button className="primary-button" disabled={busy}>{busy ? 'Подождите…' : mode === 'login' ? 'Войти в J&Joy' : 'Создать защищённый аккаунт'}</button>
        </form>
        <p className="privacy-note">Пароль приложения не отправляется на сервер и не может быть восстановлен.</p>
      </div>
    </section>
  </main>;
}

function Field({ label, type = 'text', value, onChange, placeholder, autoComplete }) {
  return <label className="field"><span>{label}</span><input required type={type} value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder} autoComplete={autoComplete}/></label>;
}

function LockScreen({ profile, onUnlock, onLogout }) {
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const submit = async event => {
    event.preventDefault(); setBusy(true); setError('');
    try { await onUnlock(password); } catch (e) { setError(e.message); setPassword(''); } finally { setBusy(false); }
  };
  return <main className="lock-page">
    <div className="ambient ambient-one"/><div className="ambient ambient-two"/>
    <section className="lock-card">
      <Logo/>
      <div className="lock-avatar">{initials(profile.user.displayName)}</div>
      <h1>С возвращением, {profile.user.displayName}</h1>
      <p>Введите пароль приложения, чтобы расшифровать ключи и открыть J&Joy.</p>
      <form onSubmit={submit}>
        <label className="lock-input"><Icon name="lock"/><input autoFocus required type="password" value={password} onChange={e => setPassword(e.target.value)} placeholder="Пароль приложения" autoComplete="current-password"/></label>
        {error && <div className="form-error">{error}</div>}
        <button className="primary-button" disabled={busy}>{busy ? 'Расшифровка…' : 'Открыть J&Joy'}</button>
      </form>
      <button className="text-button" onClick={onLogout}>Войти под другим именем</button>
    </section>
  </main>;
}

function WaitingScreen({ profile, onLock, onLogout }) {
  return <main className="waiting-page"><div className="waiting-card"><Logo/><div className="waiting-orbit"><div className="avatar large">{initials(profile.user.displayName)}</div><div className="orbit-ring"/><div className="avatar ghost">?</div></div><h1>Ожидаем второго пользователя</h1><p>Передайте ему адрес J&Joy и код приглашения. Как только он зарегистрируется, защищённый чат откроется автоматически.</p><div className="waiting-actions"><button className="secondary-button" onClick={onLock}><Icon name="lock"/>Заблокировать</button><button className="text-button" onClick={onLogout}>Выйти</button></div></div></main>;
}

function Messenger(props) {
  const { profile, messages, activeTab, setActiveTab, peerOnline, peerTyping, upload } = props;
  const nav = [
    ['chat', 'Чат', 'chat'], ['media', 'Архив', 'media'], ['search', 'Поиск', 'search'], ['settings', 'Настройки', 'settings'],
  ];
  return <main className="app-shell">
    <aside className="sidebar">
      <Logo compact/>
      <nav>{nav.map(([key, label, icon]) => <button key={key} className={activeTab === key ? 'active' : ''} onClick={() => setActiveTab(key)} title={label}><Icon name={icon}/><span>{label}</span></button>)}</nav>
      <button className="sidebar-lock" onClick={props.onLock} title="Заблокировать"><Icon name="lock"/></button>
    </aside>
    <section className="workspace">
      <header className="topbar">
        <div className="peer-block"><div className="avatar">{initials(profile.peer.displayName)}</div><div><strong>{profile.peer.displayName}</strong><small className={peerOnline ? 'online' : ''}>{peerTyping ? 'печатает…' : peerOnline ? 'в сети' : 'не в сети'}</small></div></div>
        <div className="topbar-actions"><button onClick={() => setActiveTab('search')} title="Поиск"><Icon name="search"/></button><button onClick={props.onLock} title="Заблокировать"><Icon name="lock"/></button></div>
      </header>
      <div className="content-area">
        {activeTab === 'chat' && <ChatView {...props}/>} 
        {activeTab === 'media' && <MediaArchive messages={messages} onOpen={props.onOpenAttachment}/>} 
        {activeTab === 'search' && <SearchView messages={messages} profile={profile} onOpen={props.onOpenAttachment} onGoChat={() => setActiveTab('chat')}/>} 
        {activeTab === 'settings' && <SettingsView {...props}/>} 
      </div>
    </section>
    <nav className="bottom-nav">{nav.map(([key, label, icon]) => <button key={key} className={activeTab === key ? 'active' : ''} onClick={() => setActiveTab(key)}><Icon name={icon}/><span>{label}</span></button>)}</nav>
    {upload && <div className="upload-toast"><div className="upload-ring" style={{ '--progress': `${upload.percent * 3.6}deg` }}><span>{upload.percent}</span></div><div><strong>Защищаем и отправляем</strong><small>{upload.name}</small></div></div>}
  </main>;
}

function ChatView({ messages, profile, peerTyping, onSendText, onSendFile, onTyping, onOpenAttachment }) {
  const [text, setText] = useState('');
  const [error, setError] = useState('');
  const fileRef = useRef(null);
  const bottomRef = useRef(null);
  const typingTimer = useRef(null);
  useEffect(() => { bottomRef.current?.scrollIntoView({ behavior: messages.length > 1 ? 'smooth' : 'auto' }); }, [messages.length, peerTyping]);
  const changeText = value => {
    setText(value); onTyping(true); clearTimeout(typingTimer.current); typingTimer.current = setTimeout(() => onTyping(false), 1200);
  };
  const submit = async event => {
    event.preventDefault();
    if (!text.trim()) return;
    const value = text; setText(''); onTyping(false);
    try { await onSendText(value); } catch (e) { setText(value); setError(e.message); }
  };
  const pick = async event => {
    const file = event.target.files?.[0]; event.target.value = '';
    if (!file) return;
    try { setError(''); await onSendFile(file); } catch (e) { setError(e.message); }
  };
  let previousDay = '';
  return <section className="chat-view">
    <div className="messages" aria-live="polite">
      {!messages.length && <div className="empty-chat"><div className="empty-icon"><Icon name="shield" size={32}/></div><h2>Начните ваш защищённый разговор</h2><p>Сообщения и вложения шифруются на этом устройстве до отправки.</p></div>}
      {messages.map(message => {
        const day = new Date(message.createdAt).toDateString();
        const showDay = day !== previousDay; previousDay = day;
        return <React.Fragment key={message.clientId}>{showDay && <div className="date-chip">{dayLabel(message.createdAt)}</div>}<MessageBubble message={message} mine={message.senderId === profile.user.id} onOpen={onOpenAttachment}/></React.Fragment>;
      })}
      {peerTyping && <div className="typing-bubble"><i/><i/><i/></div>}
      <div ref={bottomRef}/>
    </div>
    {error && <button className="composer-error" onClick={() => setError('')}>{error}</button>}
    <form className="composer" onSubmit={submit}>
      <input ref={fileRef} className="hidden-input" type="file" onChange={pick}/>
      <button type="button" className="composer-icon" onClick={() => fileRef.current?.click()} aria-label="Прикрепить файл"><Icon name="attach"/></button>
      <textarea value={text} onChange={e => changeText(e.target.value)} onKeyDown={e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); e.currentTarget.form?.requestSubmit(); }
      }} rows="1" placeholder="Сообщение" aria-label="Сообщение"/>
      <button className="send-button" disabled={!text.trim()} aria-label="Отправить"><Icon name="send"/></button>
    </form>
  </section>;
}

function MessageBubble({ message, mine, onOpen }) {
  const content = message.content;
  return <div className={`message-row ${mine ? 'mine' : ''}`}><article className={`message-bubble ${content.type !== 'text' ? 'attachment-bubble' : ''} ${!message.verified ? 'invalid' : ''}`}>
    {content.type === 'text' && <p>{content.text}</p>}
    {content.type === 'error' && <p className="invalid-text">{content.text}</p>}
    {['image', 'video', 'file'].includes(content.type) && <AttachmentCard message={message} onOpen={onOpen}/>} 
    <footer><time>{timeLabel(message.createdAt)}</time>{mine && <span className={`ticks ${message.readAt ? 'read' : ''}`}>✓✓</span>}</footer>
  </article></div>;
}

function AttachmentCard({ message, onOpen }) {
  const { type, attachment } = message.content;
  if (type === 'file') return <button className="file-card" onClick={() => onOpen(message, true)}><span className="file-icon"><Icon name="file"/></span><span><strong>{attachment.name}</strong><small>{formatBytes(attachment.size)}</small></span><Icon name="download"/></button>;
  return <button className="visual-card" onClick={() => onOpen(message)}>
    {attachment.preview ? <img src={attachment.preview} alt=""/> : <span className="visual-placeholder"><Icon name={type === 'video' ? 'play' : 'image'} size={30}/></span>}
    {type === 'video' && <span className="play-badge"><Icon name="play"/></span>}
    <span className="visual-caption"><strong>{attachment.name}</strong><small>{formatBytes(attachment.size)}</small></span>
  </button>;
}

function MediaArchive({ messages, onOpen }) {
  const [filter, setFilter] = useState('all');
  const items = useMemo(() => messages.filter(m => ['image', 'video', 'file'].includes(m.content.type)).reverse(), [messages]);
  const filtered = items.filter(m => filter === 'all' || m.content.type === filter);
  return <section className="page-view"><PageTitle eyebrow="Все вложения" title="Архив" text="Медиа расшифровываются только на вашем устройстве."/>
    <div className="filter-pills">{[['all','Все'],['image','Фото'],['video','Видео'],['file','Файлы']].map(([key,label]) => <button key={key} className={filter === key ? 'active' : ''} onClick={() => setFilter(key)}>{label}</button>)}</div>
    {!filtered.length ? <EmptyState icon="media" title="Архив пока пуст" text="Отправленные фотографии, видео и файлы появятся здесь."/> : <div className="media-grid">{filtered.map(message => <ArchiveItem key={message.clientId} message={message} onOpen={onOpen}/>)}</div>}
  </section>;
}

function ArchiveItem({ message, onOpen }) {
  const { type, attachment } = message.content;
  return <button className={`archive-item ${type}`} onClick={() => onOpen(message, type === 'file')}>
    <div className="archive-preview">{attachment.preview ? <img src={attachment.preview} alt=""/> : <Icon name={type === 'file' ? 'file' : type === 'video' ? 'play' : 'image'} size={34}/>} {type === 'video' && <span className="play-badge"><Icon name="play"/></span>}</div>
    <div><strong>{attachment.name}</strong><small>{formatBytes(attachment.size)} · {dayLabel(message.createdAt)}</small></div>
  </button>;
}

function SearchView({ messages, profile, onOpen, onGoChat }) {
  const [query, setQuery] = useState('');
  const normalized = query.trim().toLocaleLowerCase('ru-RU');
  const results = useMemo(() => {
    if (!normalized) return [];
    return messages.filter(message => {
      const content = message.content;
      const haystack = content.type === 'text' ? content.text : content.attachment?.name || '';
      return haystack.toLocaleLowerCase('ru-RU').includes(normalized);
    }).reverse();
  }, [messages, normalized]);
  return <section className="page-view search-page"><PageTitle eyebrow="Локальная расшифровка" title="Поиск" text="Текст запроса не отправляется на сервер."/>
    <label className="global-search"><Icon name="search"/><input autoFocus value={query} onChange={e => setQuery(e.target.value)} placeholder="Сообщение или имя файла"/>{query && <button onClick={() => setQuery('')}><Icon name="close" size={18}/></button>}</label>
    {!query ? <EmptyState icon="search" title="Найдите что угодно" text="Поиск работает по расшифрованной истории и именам вложений."/> : !results.length ? <EmptyState icon="search" title="Ничего не найдено" text="Попробуйте изменить запрос."/> : <div className="search-results">{results.map(message => <button key={message.clientId} className="search-result" onClick={() => message.content.type === 'text' ? onGoChat() : onOpen(message, message.content.type === 'file')}><div className="result-avatar">{initials(message.senderId === profile.user.id ? profile.user.displayName : profile.peer.displayName)}</div><div><div className="result-meta"><strong>{message.senderId === profile.user.id ? 'Вы' : profile.peer.displayName}</strong><time>{dayLabel(message.createdAt)} · {timeLabel(message.createdAt)}</time></div><p>{message.content.type === 'text' ? highlight(message.content.text, normalized) : message.content.attachment.name}</p></div></button>)}</div>}
  </section>;
}

function highlight(text, query) {
  const index = text.toLocaleLowerCase('ru-RU').indexOf(query);
  if (index < 0) return text;
  return <>{text.slice(0, index)}<mark>{text.slice(index, index + query.length)}</mark>{text.slice(index + query.length)}</>;
}

function SettingsView({ profile, pushEnabled, installed, safetyNumber, onTogglePush, onInstall, onLock, onLogout }) {
  return <section className="page-view settings-page"><PageTitle eyebrow="J&Joy" title="Настройки" text="Устройства, уведомления и безопасность."/>
    <div className="profile-card"><div className="avatar large">{initials(profile.user.displayName)}</div><div><h3>{profile.user.displayName}</h3><p>@{profile.user.username}</p></div><span className="secure-badge"><Icon name="shield" size={16}/>Защищено</span></div>
    <div className="settings-group"><h3>Приложение</h3><SettingRow icon="bell" title="Push-уведомления" text={pushEnabled ? 'Включены на этом устройстве' : 'Получать уведомления о новых сообщениях'} action={<button className={`switch ${pushEnabled ? 'on' : ''}`} onClick={onTogglePush}><span/></button>}/><SettingRow icon="install" title={installed ? 'J&Joy установлен' : 'Добавить на рабочий стол'} text={installed ? 'Приложение открывается в отдельном окне' : 'Иконка, полноэкранный режим и push'} action={<button className="small-button" onClick={onInstall} disabled={installed}>{installed ? 'Готово' : 'Установить'}</button>}/></div>
    <div className="settings-group"><h3>Безопасность</h3><SettingRow icon="lock" title="Заблокировать сейчас" text="Удалить расшифрованные ключи из памяти устройства" action={<button className="small-button" onClick={onLock}>Закрыть</button>}/><SettingRow icon="shield" title="Сквозное шифрование" text="X25519, XChaCha20-Poly1305, Ed25519 и Argon2id"/><div className="safety-number"><span>Код безопасности пары</span><strong>{safetyNumber || 'Появится после соединения'}</strong><small>Сверьте этот код с собеседником лично или по другому каналу.</small></div></div>
    <div className="security-explainer"><Icon name="shield" size={28}/><div><strong>Сервер хранит только шифротекст</strong><p>История, поиск, превью и файлы расшифровываются в браузере после ввода пароля приложения.</p></div></div>
    <button className="logout-button" onClick={onLogout}><Icon name="logout"/>Выйти из учётной записи</button>
  </section>;
}

function SettingRow({ icon, title, text, action }) {
  return <div className="setting-row"><span className="setting-icon"><Icon name={icon}/></span><div><strong>{title}</strong><small>{text}</small></div>{action && <div className="setting-action">{action}</div>}</div>;
}

function PageTitle({ eyebrow, title, text }) {
  return <header className="page-title"><span>{eyebrow}</span><h1>{title}</h1><p>{text}</p></header>;
}

function EmptyState({ icon, title, text }) {
  return <div className="empty-state"><span><Icon name={icon} size={30}/></span><h3>{title}</h3><p>{text}</p></div>;
}

function MediaModal({ data, onClose, onDownload }) {
  const { message } = data;
  const attachment = message.content.attachment;
  return <div className="modal-backdrop" onMouseDown={e => e.target === e.currentTarget && onClose()}><section className="media-modal"><header><div><strong>{attachment.name}</strong><small>{formatBytes(attachment.size)}</small></div><div><button onClick={onDownload}><Icon name="download"/></button><button onClick={onClose}><Icon name="close"/></button></div></header><div className="modal-content">{data.loading ? <div className="decrypt-loader"><div className="loader"><span/><span/><span/></div><p>Расшифровываем на устройстве</p></div> : message.content.type === 'image' ? <img src={data.url} alt={attachment.name}/> : <video src={data.url} controls autoPlay playsInline/>}</div></section></div>;
}
