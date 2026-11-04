'use strict';

/**
 * Интегративный тест клиентского E2EE (assets/js/crypto.js) в Node:
 * WebCrypto есть в Node >= 18, localStorage эмулируется — каждое «устройство»
 * имеет собственное хранилище, как в реальном браузере.
 * Запуск: node --test tests/e2e-ratchet.test.js
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const E2EE = require(path.join(__dirname, '..', 'assets', 'js', 'crypto.js'));

function makeDevice(name) {
  const data = new Map();
  const storage = {
    getItem: (key) => (data.has(String(key)) ? data.get(String(key)) : null),
    setItem: (key, value) => { data.set(String(key), String(value)); },
    removeItem: (key) => { data.delete(String(key)); },
    clear: () => data.clear()
  };
  return {
    name: name,
    data: data,
    async run(fn) {
      const previous = globalThis.localStorage;
      globalThis.localStorage = storage;
      try {
        return await fn();
      } finally {
        globalThis.localStorage = previous;
      }
    }
  };
}

const ALICE = 1;
const BOB = 2;
const CAROL = 3;

test('модуль доступен, identity-ключи стабильны и fingerprint детерминирован', async () => {
  assert.equal(E2EE.available(), true);

  const alice = makeDevice('alice');
  const first = await alice.run(() => E2EE.ensureIdentity('alice'));
  assert.equal(Buffer.from(first.pub, 'base64').length, 65, 'публичный ключ — raw uncompressed point P-256');
  assert.ok(first.privJwk && first.privJwk.d, 'приватная половина хранится локально');

  const info = await alice.run(() => E2EE.identityInfo('alice'));
  assert.equal(info.public_key, first.pub);
  assert.match(info.fingerprint, /^[0-9a-f]{4} [0-9a-f]{4} [0-9a-f]{4} [0-9a-f]{4}$/);

  const again = await alice.run(() => E2EE.ensureIdentity('alice'));
  assert.equal(again.pub, first.pub, 'повторный вызов не создаёт новую пару');
});

test('личный чат: ciphertext/nonce отправляются, plaintext не виден, PFS-рэтчет в обе стороны', async () => {
  const alice = makeDevice('alice');
  const bob = makeDevice('bob');
  const aliceIdentity = await alice.run(() => E2EE.ensureIdentity('alice'));
  const bobIdentity = await bob.run(() => E2EE.ensureIdentity('bob'));

  // «БД» auth-сервера — опубликованные публичные ключи (узел их только транслирует)
  const published = { [ALICE]: aliceIdentity.pub, [BOB]: bobIdentity.pub };
  const CHAT = 42;
  const text = 'Привет, Боб! Секретный текст';

  const msg1 = await alice.run(() => E2EE.encrypt(CHAT, 'private', [{ id: BOB, pub: published[BOB] }], text, 'alice'));
  assert.deepEqual(Object.keys(msg1).sort(), ['ciphertext', 'nonce', 'public_key', 'ratchet']);
  assert.ok(!Buffer.from(msg1.ciphertext, 'base64').includes(Buffer.from('Секретный')), 'на узел не попадает plaintext');
  assert.ok(!JSON.stringify(msg1).includes('"d"'), 'приватный ключ не покидает устройство');
  published[ALICE] = msg1.public_key;

  assert.equal(await bob.run(() => E2EE.decrypt(CHAT, ALICE, msg1, 'bob', BOB)), text);

  const msg2 = await alice.run(() => E2EE.encrypt(CHAT, 'private', [{ id: BOB, pub: published[BOB] }], 'Как дела?', 'alice'));
  assert.equal(msg2.ratchet, msg1.ratchet, 'в пределах одного «хода» ratchet не меняется');
  assert.notEqual(msg2.ciphertext, msg1.ciphertext);
  assert.equal(await bob.run(() => E2EE.decrypt(CHAT, ALICE, msg2, 'bob', BOB)), 'Как дела?');

  // Боб отвечает: received message => следующий исходящий ход использует новую DH-пару
  const reply = await bob.run(() => E2EE.encrypt(CHAT, 'private', [{ id: ALICE, pub: published[ALICE] }], 'Привет, Алиса!', 'bob'));
  assert.notEqual(reply.ratchet, bobIdentity.pub, 'боб ротировал ratchet-ключ');
  published[BOB] = reply.public_key;
  assert.equal(await alice.run(() => E2EE.decrypt(CHAT, BOB, reply, 'alice', ALICE)), 'Привет, Алиса!');

  const msg3 = await alice.run(() => E2EE.encrypt(CHAT, 'private', [{ id: BOB, pub: published[BOB] }], 'Третье сообщение', 'alice'));
  assert.notEqual(msg3.ratchet, msg1.ratchet, 'алиса ротировала ratchet после входящего сообщения');
  published[ALICE] = msg3.public_key;
  assert.equal(await bob.run(() => E2EE.decrypt(CHAT, ALICE, msg3, 'bob', BOB)), 'Третье сообщение');
});

test('старый ratchet-ключ собеседника читается из prev-списка (сообщения у устаревшего published-ключа)', async () => {
  const alice = makeDevice('alice');
  const bob = makeDevice('bob');
  const aliceIdentity = await alice.run(() => E2EE.ensureIdentity('alice'));
  const bobIdentity = await bob.run(() => E2EE.ensureIdentity('bob'));
  const published = { [ALICE]: aliceIdentity.pub, [BOB]: bobIdentity.pub };
  const CHAT = 55;

  const m1 = await alice.run(() => E2EE.encrypt(CHAT, 'private', [{ id: BOB, pub: published[BOB] }], 'первое', 'alice'));
  published[ALICE] = m1.public_key;
  await bob.run(() => E2EE.decrypt(CHAT, ALICE, m1, 'bob', BOB));

  const r1 = await bob.run(() => E2EE.encrypt(CHAT, 'private', [{ id: ALICE, pub: published[ALICE] }], 'ответ 1', 'bob'));
  const staleR1 = r1.public_key;
  published[BOB] = staleR1;
  await alice.run(() => E2EE.decrypt(CHAT, BOB, r1, 'alice', ALICE));

  const r2 = await bob.run(() => E2EE.encrypt(CHAT, 'private', [{ id: ALICE, pub: published[ALICE] }], 'ответ 2', 'bob'));
  published[BOB] = r2.public_key;
  await alice.run(() => E2EE.decrypt(CHAT, BOB, r2, 'alice', ALICE));

  // Алиса отправляет сообщение в ключ, который Боб уже опубликовал раньше (Bоб его уже ротировал)
  const toStale = await alice.run(() => E2EE.encrypt(CHAT, 'private', [{ id: BOB, pub: staleR1 }], 'в старый ключ', 'alice'));
  const summary = await bob.run(() => E2EE.sessionSummary('bob', CHAT));
  assert.ok(summary.kept_previous_ratchets >= 1, 'prev-ключи удерживаются');
  assert.equal(await bob.run(() => E2EE.decrypt(CHAT, ALICE, toStale, 'bob', BOB)), 'в старый ключ');
});

test('сообщения в произвольном порядке: пропущенные ключи цепочки восстанавливаются', async () => {
  const alice = makeDevice('alice');
  const bob = makeDevice('bob');
  const aliceIdentity = await alice.run(() => E2EE.ensureIdentity('alice'));
  const bobIdentity = await bob.run(() => E2EE.ensureIdentity('bob'));
  const published = { [ALICE]: aliceIdentity.pub, [BOB]: bobIdentity.pub };
  const CHAT = 77;

  const texts = ['первое', 'второе', 'третье'];
  const sent = [];
  for (const text of texts) {
    sent.push(await alice.run(() => E2EE.encrypt(CHAT, 'private', [{ id: BOB, pub: published[BOB] }], text, 'alice')));
  }

  assert.equal(await bob.run(() => E2EE.decrypt(CHAT, ALICE, sent[2], 'bob', BOB)), 'третье');
  assert.equal(await bob.run(() => E2EE.decrypt(CHAT, ALICE, sent[1], 'bob', BOB)), 'второе');
  assert.equal(await bob.run(() => E2EE.decrypt(CHAT, ALICE, sent[0], 'bob', BOB)), 'первое');
});

test('групповой чат: каждый участник расшифровывает свой entry, чужой — нет', async () => {
  const alice = makeDevice('alice');
  const bob = makeDevice('bob');
  const carol = makeDevice('carol');
  const identityBob = await bob.run(() => E2EE.ensureIdentity('bob'));
  const identityCarol = await carol.run(() => E2EE.ensureIdentity('carol'));
  const CHAT = 7;
  const text = 'Общий текст для группы';

  const groupMsg = await alice.run(() => E2EE.encrypt(
    CHAT,
    'group',
    [{ id: BOB, pub: identityBob.pub }, { id: CAROL, pub: identityCarol.pub }],
    text,
    'alice'
  ));
  assert.equal(await bob.run(() => E2EE.decrypt(CHAT, ALICE, groupMsg, 'bob', BOB)), text);
  assert.equal(await carol.run(() => E2EE.decrypt(CHAT, ALICE, groupMsg, 'carol', CAROL)), text);
  await assert.rejects(
    () => bob.run(() => E2EE.decrypt(CHAT, ALICE, groupMsg, 'bob', CAROL)),
    /нет ключа для этого получателя/
  );
});

test('подмена ciphertext отклоняется (AES-GCM auth tag)', async () => {
  const alice = makeDevice('alice');
  const bob = makeDevice('bob');
  const identityAlice = await alice.run(() => E2EE.ensureIdentity('alice'));
  const identityBob = await bob.run(() => E2EE.ensureIdentity('bob'));
  const CHAT = 9;
  const msg = await alice.run(() => E2EE.encrypt(CHAT, 'private', [{ id: BOB, pub: identityBob.pub }], 'Не трогать меня', 'alice'));

  const raw = Buffer.from(msg.ciphertext, 'base64');
  raw[raw.length - 3] ^= 0xff;
  const tampered = Object.assign({}, msg, { ciphertext: raw.toString('base64') });
  await assert.rejects(() => bob.run(() => E2EE.decrypt(CHAT, ALICE, tampered, 'bob', BOB)));
});

test('экспорт/импорт ключей с паролем: другой device читает сообщения, адресованные identity-ключу', async () => {
  const alice = makeDevice('alice');
  const bob = makeDevice('bob');
  const identityAlice = await alice.run(() => E2EE.ensureIdentity('alice'));
  const identityBob = await bob.run(() => E2EE.ensureIdentity('bob'));
  const CHAT = 31;

  const msg = await bob.run(() => E2EE.encrypt(CHAT, 'private', [{ id: ALICE, pub: identityAlice.pub }], 'Для Алисы', 'bob'));

  const file = await alice.run(() => E2EE.exportIdentity('alice', 'супер-пароль'));
  assert.equal(file.encrypted, true);
  assert.ok(!JSON.stringify(file).includes('private_key'), 'файл не содержит приватный ключ в открытом виде');

  const wrongDevice = makeDevice('wrong');
  await assert.rejects(() => wrongDevice.run(() => E2EE.importIdentity(file, 'неверный пароль')), /паролем|пароль/);

  const newDevice = makeDevice('new-device');
  const imported = await newDevice.run(() => E2EE.importIdentity(file, 'супер-пароль'));
  assert.equal(imported.public_key, identityAlice.pub);
  assert.equal(imported.fingerprint, (await alice.run(() => E2EE.identityInfo('alice'))).fingerprint);
  assert.equal(await newDevice.run(() => E2EE.decrypt(CHAT, BOB, msg, 'alice', ALICE)), 'Для Алисы');
});

test('экспорт без пароля и генерация новых ключей', async () => {
  const alice = makeDevice('alice');
  const identity = await alice.run(() => E2EE.ensureIdentity('alice'));
  const plainFile = await alice.run(() => E2EE.exportIdentity('alice', ''));
  assert.equal(plainFile.encrypted, false);
  assert.equal(plainFile.keys.public_key, identity.pub);

  const otherDevice = makeDevice('other');
  const imported = await otherDevice.run(() => E2EE.importIdentity(plainFile, ''));
  assert.equal(imported.public_key, identity.pub);

  const regenerated = await alice.run(() => E2EE.regenerateIdentity('alice'));
  assert.notEqual(regenerated.pub, identity.pub, 'сгенерирован новый keypair');
  assert.equal(await alice.run(() => E2EE.outgoingPublicKey('alice', 1)), null, 'ratchet-состояния очищены');
  assert.ok(regenerated.privJwk.d);
});