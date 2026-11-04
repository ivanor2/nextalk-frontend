/**
 * NexTalk Messenger — клиентское шифрование конца в конец (E2EE), Web Crypto API.
 *
 * Протокол `nextalk-e2e-v1`:
 *   Identity — долговременный ECDH P-256 keypair. Приватная половина хранится только на
 *   устройстве (localStorage), публичная — base64 raw uncompressed point (65 байт) —
 *   публикуется в auth-сервер (`users.public_key`), узел её только передаёт.
 *   Message — Double Ratchet на каждый чат:
 *     chainKey(n+1) = HMAC-SHA256(chainKey(n), "nt:v1:chain")
 *     msgKey(n)     = HMAC-SHA256(chainKey(n), "nt:v1:msg")
 *     AES key       = HKDF-SHA256(msgKey(n), "nt:v1:aes") → AES-GCM-256, IV 12 байт случайно.
 *     Root chain    = HKDF-SHA256(ECDH(мой ratchet-priv, ключ собеседника), info)
 *     Ключ собеседника = его опубликованный ratchet-ключ (или identity-ключ до первого ответа).
     DM   : v=1 | tag(8) | ratchet(65) | iv(12) | n(u16BE) | ciphertext
     Group: v=2 | count(u8) | { recipient_id(u32BE) tag(8) ratchet(65) iv(12) n(u16BE) len(u32BE) ciphertext } * N
   * ключом (identity / текущий / предыдущий ratchet) был получен shared secret.
 *   Envelope — бинарный blob, base64 в колонке `messages.ciphertext`:
 *     DM   : v=1 | tag(8) | ratchet(65) | iv(12) | n(u16BE) | ciphertext
 *     Group: v=2 | count(u8) | { recipient_id(u32BE) tag(8) ratchet(65) iv(12) n(u16BE) len(u32BE) ciphertext } * N
 *   tag = первые 8 байт SHA-256(ключа собеседника) — указывает получателю, каким его
 *   ключом (identity / текущий / предыдущий ratchet) был получен shared secret.
 *   PFS: на каждый исходящий «ход» создаётся новая DH-пара; приватная половина старых
 *   ratchet-ключей хранится только пока нужно расшифровывать сообщения, отправленные в
 *   них (не более 6), затем удаляется навсегда. Plaintext на узел не попадает никогда.
 */
(function () {
  'use strict';

  const globalScope = typeof globalThis !== 'undefined' ? globalThis : (typeof window !== 'undefined' ? window : {});
  const subtle = globalScope.crypto && globalScope.crypto.subtle;

  const CURVE = { name: 'ECDH', namedCurve: 'P-256' };
  const TXT = new TextEncoder();
  const DEC = new TextDecoder();

  const IDENTITY_STORE = 'nt-e2e-identity-v1';
  const RATCHET_STORE = 'nt-e2e-ratchet-v1';
  const SENT_CACHE_STORE = 'nt-e2e-sent-cache-v1';
  const MAX_PREV_RATCHETS = 6;
  const MAX_SKIPPED_KEYS = 40;
  const MAX_SENT_CACHE = 200;

  function available() {
    return !!(subtle && typeof subtle.generateKey === 'function'
      && typeof subtle.deriveBits === 'function'
      && typeof subtle.importKey === 'function');
  }

  // ── Байтовые утилиты ────────────────────────────────────────────────
  function b64(bytes) {
    if (typeof Buffer !== 'undefined') return Buffer.from(bytes).toString('base64');
    let s = '';
    for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s);
  }

  function unb64(str) {
    if (typeof Buffer !== 'undefined') return new Uint8Array(Buffer.from(String(str), 'base64'));
    const bin = atob(String(str));
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  function concatBytes(parts) {
    let total = 0;
    for (const p of parts) total += p.length;
    const out = new Uint8Array(total);
    let off = 0;
    for (const p of parts) { out.set(p, off); off += p.length; }
    return out;
  }

  function u8(value) { return new Uint8Array([value & 255]); }

  function u16(value) { return new Uint8Array([(value >>> 8) & 255, value & 255]); }

  function u32(value) {
    return new Uint8Array([(value >>> 24) & 255, (value >>> 16) & 255, (value >>> 8) & 255, value & 255]);
  }

  function readU32(bytes, off) {
    return bytes[off] * 16777216 + bytes[off + 1] * 65536 + bytes[off + 2] * 256 + bytes[off + 3];
  }

  function readU16(bytes, off) { return bytes[off] * 256 + bytes[off + 1]; }

  function randomBytes(len) {
    const bytes = new Uint8Array(len);
    globalScope.crypto.getRandomValues(bytes);
    return bytes;
  }

  // ── HASH / HMAC / HKDF (SHA-256, никаких экзотических алгоритмов) ───
  async function sha256(bytes) {
    return new Uint8Array(await subtle.digest('SHA-256', bytes));
  }

  function hmacKey(bytes) {
    return subtle.importKey('raw', bytes, { name: 'HMAC', hash: { name: 'SHA-256' } }, false, ['sign']);
  }

  async function hmac(key, data) {
    return new Uint8Array(await subtle.sign('HMAC', key, data));
  }

  /** HKDF-SHA256 (RFC 5861, extract-then-expand) на базе HMAC — работает во всех браузерах. */
  async function hkdf(ikm, info, length = 32) {
    const salt = await hmacKey(TXT.encode('nextalk-e2e-salt-v1'));
    const prk = await hmac(salt, ikm);
    const mac = await hmacKey(prk);
    const infoBytes = TXT.encode(String(info));
    let out = new Uint8Array(0);
    let block = new Uint8Array(0);
    while (out.length < length) {
      block = await hmac(mac, concatBytes([block, infoBytes]));
      out = concatBytes([out, block]);
    }
    return out.slice(0, length);
  }

  async function chainNext(chainKey) {
    return hmac(await hmacKey(chainKey), TXT.encode('nt:v1:chain'));
  }

  async function chainMessageKey(chainKey) {
    return hmac(await hmacKey(chainKey), TXT.encode('nt:v1:msg'));
  }

  async function aesEncrypt(keyBytes, iv, plain) {
    const key = await subtle.importKey('raw', keyBytes, { name: 'AES-GCM' }, false, ['encrypt']);
    return new Uint8Array(await subtle.encrypt({ name: 'AES-GCM', iv, tagLength: 128 }, key, plain));
  }

  async function aesDecrypt(keyBytes, iv, cipher) {
    const key = await subtle.importKey('raw', keyBytes, { name: 'AES-GCM' }, false, ['decrypt']);
    return new Uint8Array(await subtle.decrypt({ name: 'AES-GCM', iv, tagLength: 128 }, key, cipher));
  }

  // ── Хранилище устройства: приватные ключи никогда не покидают браузер ──────
  function storage() { return globalScope.localStorage; }

  function readJson(key, fallback) {
    try {
      const raw = storage() ? storage().getItem(key) : null;
      if (!raw) return fallback;
      return JSON.parse(raw);
    } catch (e) { return fallback; }
  }

  function writeJson(key, value) {
    try { if (storage()) storage().setItem(key, JSON.stringify(value)); } catch (e) { /* localStorage quota */ }
  }

  // ── ECDH-ключи и кэш CryptoKey ────────────────────────────────────────────
  const pubKeyCache = new Map();
  const privKeyCache = new Map();

  async function peerPublicKey(pubB64) {
    if (pubKeyCache.has(pubB64)) return pubKeyCache.get(pubB64);
    const raw = unb64(pubB64);
    if (raw.length !== 65) throw new Error('Некорректная длина публичного ключа (ожидается 65 байт)');
    const key = await subtle.importKey('raw', raw, { name: 'ECDH', namedCurve: 'P-256' }, false, []);
    pubKeyCache.set(pubB64, key);
    return key;
  }

  async function privateKeyFromJwk(jwk) {
    const cacheKey = JSON.stringify(jwk);
    if (privKeyCache.has(cacheKey)) return privKeyCache.get(cacheKey);
    const key = await subtle.importKey('jwk', jwk, CURVE, true, ['deriveBits']);
    privKeyCache.set(cacheKey, key);
    return key;
  }

  async function deriveSecret(privKey, peerPubB64) {
    const bits = await subtle.deriveBits({ name: 'ECDH', public: await peerPublicKey(peerPubB64) }, privKey, 256);
    return new Uint8Array(bits);
  }

  async function newKeyPair() {
    const pair = await subtle.generateKey(CURVE, true, ['deriveBits']);
    const raw = new Uint8Array(await subtle.exportKey('raw', pair.publicKey));
    return { pub: b64(raw), privJwk: await subtle.exportKey('jwk', pair.privateKey) };
  }

  /** Первые 8 байт SHA-256 публичного ключа — идентификатор ключа внутри конверта. */
  async function tagOf(pubB64) {
    return b64((await sha256(unb64(pubB64))).slice(0, 8));
  }

  /** Человекочитаемый fingerprint публичного ключа (проверка собеседника). */
  async function fingerprint(pubB64) {
    const hash = await sha256(unb64(pubB64));
    const hex = Array.from(hash.slice(0, 8)).map(b => b.toString(16).padStart(2, '0')).join('');
    return hex.match(/.{1,4}/g).join(' ');
  }

  function chainInfo(chatId, participantId) { return `nt:v1:ck:${chatId}:${participantId}`; }

  // ── Долговременные identity-ключи пользователя ────────────────────────────
  function identityRecords() { return readJson(IDENTITY_STORE, {}); }

  async function loadIdentity(username) {
    const rec = identityRecords()[username];
    if (!rec || !rec.pub || !rec.priv_jwk) return null;
    return {
      username: username,
      pub: rec.pub,
      privJwk: rec.priv_jwk,
      privKey: await privateKeyFromJwk(rec.priv_jwk),
      created_at: rec.created_at || null
    };
  }

  async function createIdentity(username) {
    const keys = await newKeyPair();
    const records = identityRecords();
    records[username] = { pub: keys.pub, priv_jwk: keys.privJwk, created_at: new Date().toISOString() };
    writeJson(IDENTITY_STORE, records);
    return { username: username, pub: keys.pub, privJwk: keys.privJwk, created_at: records[username].created_at };
  }

  // ── Ratchet-сессии на каждый чат ──────────────────────────────────────────
  function ratchetRecords() { return readJson(RATCHET_STORE, {}); }

  function sessionFor(username, chatId, create) {
    const all = ratchetRecords();
    const bucket = all[username] || {};
    const key = String(chatId);
    let sess = bucket[key];
    if (!sess && create) {
      sess = { out: null, in: {} };
      bucket[key] = sess;
      all[username] = bucket;
      writeJson(RATCHET_STORE, all);
    }
    return sess;
  }

  function persistSession(username, chatId, sess) {
    const all = ratchetRecords();
    if (!all[username]) all[username] = {};
    all[username][String(chatId)] = sess;
    writeJson(RATCHET_STORE, all);
  }

  /** DH-ratchet: новый keypair на исходящий «ход», старые приваты ограничены MAX_PREV_RATCHETS. */
  async function rotateOut(session) {
    const prev = session.out ? (session.out.prev || []) : [];
    if (session.out) prev.unshift({ pub: session.out.pub, priv_jwk: session.out.priv_jwk });
    while (prev.length > MAX_PREV_RATCHETS) prev.pop();
    const keys = await newKeyPair();
    session.out = { pub: keys.pub, priv_jwk: keys.privJwk, prev: prev, peers: {}, needs_rotate: false };
  }

  async function sendChainKey(session, chatId, peerId, peerPub) {
    const priv = await privateKeyFromJwk(session.out.priv_jwk);
    return hkdf(await deriveSecret(priv, peerPub), chainInfo(chatId, peerId), 32);
  }

  /** Приватный ключ получателя, corresponding to tag inside the envelope. */
  async function resolveOwnPrivateKey(session, tag, identityRecord) {
    if (identityRecord && await tagOf(identityRecord.pub) === tag) {
      return await privateKeyFromJwk(identityRecord.priv_jwk);
    }
    if (session && session.out) {
      if (await tagOf(session.out.pub) === tag) return await privateKeyFromJwk(session.out.priv_jwk);
      for (const prev of (session.out.prev || [])) {
        if (await tagOf(prev.pub) === tag) return await privateKeyFromJwk(prev.priv_jwk);
      }
    }
    return null;
  }

  // ── Конверт сообщения (бинарный, base64 в messages.ciphertext) ────────────
  function packEnvelope(chatType, entries) {
    if (chatType === 'group') {
      const parts = [u8(2), u8(entries.length)];
      for (const e of entries) {
        parts.push(u32(e.recipient_id), unb64(e.tag), unb64(e.ratchet), e.iv, u16(e.n), u32(e.ciphertext.length), e.ciphertext);
      }
      return concatBytes(parts);
    }
    const e = entries[0];
    return concatBytes([u8(1), unb64(e.tag), unb64(e.ratchet), e.iv, u16(e.n), e.ciphertext]);
  }

  function unpackEnvelope(blob) {
    if (!blob || blob.length < 14) throw new Error('Повреждённый конверт сообщения');
    const version = blob[0];
    const entries = [];
    let off = 1;
    if (version === 1) {
      const tag = b64(blob.slice(off, off + 8)); off += 8;
      const ratchet = b64(blob.slice(off, off + 65)); off += 65;
      const iv = blob.slice(off, off + 12); off += 12;
      const n = readU16(blob, off); off += 2;
      entries.push({ recipient_id: null, tag: tag, ratchet: ratchet, iv: iv, n: n, ciphertext: blob.slice(off) });
      return { chatType: 'private', entries: entries };
    }
    if (version === 2) {
      const count = blob[off]; off += 1;
      for (let i = 0; i < count; i++) {
        const recipient = readU32(blob, off); off += 4;
        const tag = b64(blob.slice(off, off + 8)); off += 8;
        const ratchet = b64(blob.slice(off, off + 65)); off += 65;
        const iv = blob.slice(off, off + 12); off += 12;
        const n = readU16(blob, off); off += 2;
        const len = readU32(blob, off); off += 4;
        entries.push({ recipient_id: recipient, tag: tag, ratchet: ratchet, iv: iv, n: n, ciphertext: blob.slice(off, off + len) });
        off += len;
      }
      return { chatType: 'group', entries: entries };
    }
    throw new Error('Неизвестная версия конверта сообщения');
  }

  /** Последовательная очередь на чат: расшифровка/шифрование не конкурируют за ratchet-состояние. */
  const queues = new Map();

  function withQueue(key, fn) {
    const previous = queues.get(key) || Promise.resolve();
    const next = previous.then(fn, fn);
    queues.set(key, next.catch(() => {}));
    return next;
  }

  // ── Шифрование исходящего сообщения ───────────────────────────────────────
  async function encrypt(chatId, chatType, recipients, plaintext, username) {
    if (!available()) throw new Error('Web Crypto API недоступен в этом браузере');
    return withQueue(`${username}:${chatId}`, async () => {
      const sess = sessionFor(username, chatId, true);
      if (!sess.out || sess.out.needs_rotate) await rotateOut(sess);

      const entries = [];
      for (const recipient of recipients) {
        const peerId = String(recipient.id);
        const peerPub = String(recipient.pub || '');
        if (!peerPub) throw new Error(`Публичный ключ пользователя ${peerId} не найден`);

        let state = sess.out.peers[peerId];
        if (!state || state.peer_pub !== peerPub) {
          state = {
            peer_pub: peerPub,
            tag: await tagOf(peerPub),
            chain_key: b64(await sendChainKey(sess, chatId, peerId, peerPub)),
            counter: 0
          };
          sess.out.peers[peerId] = state;
        }

        const chain = unb64(state.chain_key);
        const aesKey = await hkdf(await chainMessageKey(chain), 'nt:v1:aes', 32);
        const iv = randomBytes(12);
        const cipher = await aesEncrypt(aesKey, iv, TXT.encode(String(plaintext)));
        state.chain_key = b64(await chainNext(chain));
        entries.push({
          recipient_id: chatType === 'group' ? Number(peerId) : null,
          tag: state.tag,
          ratchet: sess.out.pub,
          iv: iv,
          n: state.counter,
          ciphertext: cipher
        });
        state.counter += 1;
      }

      persistSession(username, chatId, sess);
      return {
        ciphertext: b64(packEnvelope(chatType, entries)),
        nonce: b64(entries[0].iv),
        ratchet: sess.out.pub,
        public_key: sess.out.pub
      };
    });
  }

  // ── Расшифровка входящего сообщения на этом устройстве ────────────────────
  async function decrypt(chatId, senderId, fields, username, myUserId) {
    if (!available()) throw new Error('Web Crypto API недоступен в этом браузере');
    if (!fields || !fields.ciphertext) throw new Error('Сообщение не зашифровано');
    return withQueue(`${username}:${chatId}`, async () => {
      const unpacked = unpackEnvelope(unb64(fields.ciphertext));
      let entry = null;
      if (unpacked.chatType === 'group') {
        entry = unpacked.entries.find(e => Number(e.recipient_id) === Number(myUserId));
        if (!entry) throw new Error('В групповом сообщении нет ключа для этого получателя');
      } else {
        entry = unpacked.entries[0];
      }

      const sess = sessionFor(username, chatId, true);
      const identityRecord = identityRecords()[username];
      const senderKey = String(senderId);
      const stored = sess.in[senderKey];
      const fresh = !stored || stored.ratchet !== entry.ratchet || stored.tag !== entry.tag;

      let chain;
      let counter;
      let skipped;
      if (fresh) {
        const priv = await resolveOwnPrivateKey(sess, entry.tag, identityRecord);
        if (!priv) throw new Error('Ключ рэтчета уже удалён — сообщение не расшифровывается на этом устройстве');
        chain = await hkdf(await deriveSecret(priv, entry.ratchet), chainInfo(chatId, senderKey), 32);
        counter = 0;
        skipped = {};
      } else {
        chain = unb64(stored.chain_key);
        counter = stored.counter;
        skipped = stored.skipped || {};
      }

      const skippedKey = skipped[String(entry.n)];
      if (skippedKey) {
        chain = unb64(skippedKey);
        delete skipped[String(entry.n)];
        counter = entry.n;
      } else {
        while (counter < entry.n) {
          if (Object.keys(skipped).length < MAX_SKIPPED_KEYS) skipped[String(counter)] = b64(chain);
          chain = await chainNext(chain);
          counter += 1;
        }
      }

      const aesKey = await hkdf(await chainMessageKey(chain), 'nt:v1:aes', 32);
      const plain = DEC.decode(await aesDecrypt(aesKey, entry.iv, entry.ciphertext));

      sess.in[senderKey] = {
        ratchet: entry.ratchet,
        tag: entry.tag,
        chain_key: b64(await chainNext(chain)),
        counter: entry.n + 1,
        skipped: skipped
      };
      if (sess.out) sess.out.needs_rotate = true;
      persistSession(username, chatId, sess);
      return plain;
    });
  }

  // ── Управление identity-ключами (генерация / экспорт / импорт) ────────────
  async function ensureIdentity(username) {
    return await loadIdentity(username) || await createIdentity(username);
  }

  async function identityInfo(username) {
    const rec = identityRecords()[username];
    if (!rec || !rec.pub) return null;
    return {
      username: username,
      public_key: rec.pub,
      fingerprint: await fingerprint(rec.pub),
      created_at: rec.created_at || null,
      stored_on_device: true
    };
  }

  /** Новая keypar: старые ключи и ratchet-состояния этого пользователя удаляются. */
  async function regenerateIdentity(username) {
    const fresh = await createIdentity(username);
    const ratchets = ratchetRecords();
    delete ratchets[username];
    writeJson(RATCHET_STORE, ratchets);
    const sentCache = readJson(SENT_CACHE_STORE, {});
    delete sentCache[username];
    writeJson(SENT_CACHE_STORE, sentCache);
    return { username: username, pub: fresh.pub, privJwk: fresh.privJwk, created_at: fresh.created_at };
  }

  function wipeIdentity(username) {
    const records = identityRecords();
    delete records[username];
    writeJson(IDENTITY_STORE, records);
    const ratchets = ratchetRecords();
    delete ratchets[username];
    writeJson(RATCHET_STORE, ratchets);
    const sentCache = readJson(SENT_CACHE_STORE, {});
    delete sentCache[username];
    writeJson(SENT_CACHE_STORE, sentCache);
  }

  /** Локальный кэш собственных исходящих сообщений (plaintext только на устройстве). */
  function rememberSent(chatId, messageId, plaintext, username) {
    if (!messageId) return;
    const cache = readJson(SENT_CACHE_STORE, {});
    const bucket = cache[username] || {};
    const key = String(chatId);
    bucket[key] = bucket[key] || [];
    bucket[key].push({ id: Number(messageId), text: String(plaintext) });
    if (bucket[key].length > MAX_SENT_CACHE) bucket[key] = bucket[key].slice(-MAX_SENT_CACHE);
    cache[username] = bucket;
    writeJson(SENT_CACHE_STORE, cache);
  }

  function cachedSent(chatId, messageId, username) {
    const bucket = (readJson(SENT_CACHE_STORE, {})[username] || {})[String(chatId)] || [];
    const hit = bucket.find(e => Number(e.id) === Number(messageId));
    return hit ? hit.text : null;
  }

  /** Экспорт ключей: файл с паролем (PBKDF2-SHA256 210k итераций + AES-GCM) или без пароля. */
  async function exportIdentity(username, passphrase = '') {
    const rec = identityRecords()[username];
    if (!rec || !rec.priv_jwk) throw new Error('Ключи этого пользователя не найдены на устройстве');
    const payload = {
      schema: 'nextalk-e2e-keys-v1',
      username: username,
      algorithm: 'ECDH-P256 + HKDF-SHA256 + AES-GCM-256',
      public_key: rec.pub,
      private_key: rec.priv_jwk,
      created_at: rec.created_at || null
    };
    if (!passphrase) {
      return { schema: 'nextalk-e2e-keys-v1', encrypted: false, exported_at: new Date().toISOString(), keys: payload };
    }
    const salt = randomBytes(16);
    const iv = randomBytes(12);
    const pwKey = await subtle.importKey('raw', TXT.encode(String(passphrase)), 'PBKDF2', false, ['deriveBits']);
    const bits = new Uint8Array(await subtle.deriveBits(
      { name: 'PBKDF2', salt: salt, iterations: 210000, hash: 'SHA-256' }, pwKey, 256
    ));
    const cipher = await aesEncrypt(bits, iv, TXT.encode(JSON.stringify(payload)));
    return {
      schema: 'nextalk-e2e-keys-v1',
      encrypted: true,
      kdf: 'PBKDF2-SHA256',
      iterations: 210000,
      salt: b64(salt),
      nonce: b64(iv),
      ciphertext: b64(cipher),
      exported_at: new Date().toISOString()
    };
  }

  async function importIdentity(file, passphrase = '') {
    if (!file || file.schema !== 'nextalk-e2e-keys-v1') throw new Error('Файл ключей NexTalk не распознан');
    let payload;
    if (file.encrypted) {
      if (!passphrase) throw new Error('Файл защищён паролем — введите пароль');
      const pwKey = await subtle.importKey('raw', TXT.encode(String(passphrase)), 'PBKDF2', false, ['deriveBits']);
      const bits = new Uint8Array(await subtle.deriveBits({
        name: 'PBKDF2', salt: unb64(file.salt), iterations: Number(file.iterations || 210000), hash: 'SHA-256'
      }, pwKey, 256));
      payload = JSON.parse(DEC.decode(await aesDecrypt(bits, unb64(file.nonce), unb64(file.ciphertext))));
    } else {
      payload = file.keys;
    }
    if (!payload || !payload.public_key || !payload.private_key) throw new Error('Файл ключей повреждён');
    const publicJwk = await subtle.exportKey('jwk', await peerPublicKey(payload.public_key));
    if (payload.private_key.x !== publicJwk.x || payload.private_key.y !== publicJwk.y) {
      throw new Error('Приватный ключ из файла не соответствует публичному ключу');
    }
    const username = String(payload.username || '');
    if (!username) throw new Error('В файле ключей не указан пользователь');
    const records = identityRecords();
    records[username] = {
      pub: payload.public_key,
      priv_jwk: payload.private_key,
      created_at: payload.created_at || new Date().toISOString()
    };
    writeJson(IDENTITY_STORE, records);
    const ratchets = ratchetRecords();
    delete ratchets[username];
    writeJson(RATCHET_STORE, ratchets);
    return { username: username, public_key: payload.public_key, fingerprint: await fingerprint(payload.public_key) };
  }

  function outgoingPublicKey(username, chatId) {
    const sess = sessionFor(username, chatId, false);
    return sess && sess.out ? sess.out.pub : null;
  }

  function sessionSummary(username, chatId) {
    const sess = sessionFor(username, chatId, false);
    if (!sess) return { chat_id: Number(chatId), state: 'нет сессии' };
    return {
      chat_id: Number(chatId),
      outgoing_ratchet: sess.out ? sess.out.pub : null,
      kept_previous_ratchets: sess.out ? (sess.out.prev || []).length : 0,
      outgoing_peers: sess.out ? Object.keys(sess.out.peers || {}).length : 0,
      incoming_senders: Object.keys(sess.in || {}).length
    };
  }

  const api = {
    available: available,
    fingerprint: fingerprint,
    encrypt: encrypt,
    decrypt: decrypt,
    ensureIdentity: ensureIdentity,
    identityInfo: identityInfo,
    regenerateIdentity: regenerateIdentity,
    wipeIdentity: wipeIdentity,
    exportIdentity: exportIdentity,
    importIdentity: importIdentity,
    outgoingPublicKey: outgoingPublicKey,
    sessionSummary: sessionSummary,
    rememberSent: rememberSent,
    cachedSent: cachedSent
  };

  globalScope.E2EE = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})();