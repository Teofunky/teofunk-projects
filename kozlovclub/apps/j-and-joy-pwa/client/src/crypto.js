import sodium from 'libsodium-wrappers-sumo';

const B64 = () => sodium.base64_variants.ORIGINAL;
const VAULT_AAD = 'J&Joy vault v1';
export const FILE_CHUNK_SIZE = 2 * 1024 * 1024;

export async function sodiumReady() {
  await sodium.ready;
  return sodium;
}

export function b64(bytes) { return sodium.to_base64(bytes, B64()); }
export function unb64(value) { return sodium.from_base64(value, B64()); }

function deriveVaultKey(password, salt) {
  return sodium.crypto_pwhash(
    32,
    password,
    salt,
    3,
    64 * 1024 * 1024,
    sodium.crypto_pwhash_ALG_ARGON2ID13,
  );
}

export async function createVault(password) {
  await sodiumReady();
  const box = sodium.crypto_box_keypair();
  const sign = sodium.crypto_sign_keypair();
  const secret = {
    boxPrivateKey: b64(box.privateKey),
    signPrivateKey: b64(sign.privateKey),
  };
  const salt = sodium.randombytes_buf(sodium.crypto_pwhash_SALTBYTES);
  const key = deriveVaultKey(password, salt);
  const nonce = sodium.randombytes_buf(sodium.crypto_aead_xchacha20poly1305_ietf_NPUBBYTES);
  const plaintext = sodium.from_string(JSON.stringify(secret));
  const ciphertext = sodium.crypto_aead_xchacha20poly1305_ietf_encrypt(
    plaintext, sodium.from_string(VAULT_AAD), null, nonce, key,
  );
  sodium.memzero(key);
  return {
    boxPublicKey: b64(box.publicKey),
    signPublicKey: b64(sign.publicKey),
    secrets: secret,
    vault: { v: 1, salt: b64(salt), nonce: b64(nonce), ciphertext: b64(ciphertext) },
  };
}

export async function unlockVault(vault, password) {
  await sodiumReady();
  try {
    const salt = unb64(vault.salt);
    const key = deriveVaultKey(password, salt);
    const plaintext = sodium.crypto_aead_xchacha20poly1305_ietf_decrypt(
      null,
      unb64(vault.ciphertext),
      sodium.from_string(VAULT_AAD),
      unb64(vault.nonce),
      key,
    );
    sodium.memzero(key);
    return JSON.parse(sodium.to_string(plaintext));
  } catch {
    throw new Error('Неверный пароль приложения');
  }
}


export async function peerFingerprint(peer) {
  await sodiumReady();
  const digest = sodium.crypto_generichash(20, sodium.from_string(`${peer.boxPublicKey}.${peer.signPublicKey}`));
  const hex = sodium.to_hex(digest).toUpperCase();
  return hex.match(/.{1,4}/g).join(' ');
}

export async function deriveSharedKey(myBoxPrivateKey, peerBoxPublicKey) {
  await sodiumReady();
  return sodium.crypto_generichash(
    32,
    sodium.crypto_box_beforenm(unb64(peerBoxPublicKey), unb64(myBoxPrivateKey)),
    sodium.from_string('J&Joy shared key v1'),
  );
}

function signatureData(clientId, senderId, nonce, ciphertext) {
  return sodium.from_string(`${clientId}.${senderId}.${nonce}.${ciphertext}`);
}

export async function encryptMessage(payload, sharedKey, senderId, signPrivateKey, clientId = crypto.randomUUID()) {
  await sodiumReady();
  const nonceBytes = sodium.randombytes_buf(sodium.crypto_aead_xchacha20poly1305_ietf_NPUBBYTES);
  const aad = sodium.from_string(`jj:v1:${clientId}:${senderId}`);
  const ciphertextBytes = sodium.crypto_aead_xchacha20poly1305_ietf_encrypt(
    sodium.from_string(JSON.stringify({ v: 1, ...payload })), aad, null, nonceBytes, sharedKey,
  );
  const nonce = b64(nonceBytes);
  const ciphertext = b64(ciphertextBytes);
  const signature = b64(sodium.crypto_sign_detached(
    signatureData(clientId, senderId, nonce, ciphertext), unb64(signPrivateKey),
  ));
  return { clientId, nonce, ciphertext, signature };
}

export async function decryptMessage(message, sharedKey, senderSignPublicKey) {
  await sodiumReady();
  const valid = sodium.crypto_sign_verify_detached(
    unb64(message.signature),
    signatureData(message.clientId, message.senderId, message.nonce, message.ciphertext),
    unb64(senderSignPublicKey),
  );
  if (!valid) throw new Error('Подпись сообщения недействительна');
  const plaintext = sodium.crypto_aead_xchacha20poly1305_ietf_decrypt(
    null,
    unb64(message.ciphertext),
    sodium.from_string(`jj:v1:${message.clientId}:${message.senderId}`),
    unb64(message.nonce),
    sharedKey,
  );
  return JSON.parse(sodium.to_string(plaintext));
}

function deriveFileKey(sharedKey, salt) {
  return sodium.crypto_generichash(32, salt, sharedKey);
}

export async function encryptAndUploadFile(file, sharedKey, uploadChunk, onProgress) {
  await sodiumReady();
  const salt = sodium.randombytes_buf(16);
  const key = deriveFileKey(sharedKey, salt);
  const stream = sodium.crypto_secretstream_xchacha20poly1305_init_push(key);
  const chunkCount = Math.max(1, Math.ceil(file.size / FILE_CHUNK_SIZE));

  for (let index = 0; index < chunkCount; index += 1) {
    const start = index * FILE_CHUNK_SIZE;
    const end = Math.min(file.size, start + FILE_CHUNK_SIZE);
    const plain = new Uint8Array(await file.slice(start, end).arrayBuffer());
    const tag = index === chunkCount - 1
      ? sodium.crypto_secretstream_xchacha20poly1305_TAG_FINAL
      : sodium.crypto_secretstream_xchacha20poly1305_TAG_MESSAGE;
    const encrypted = sodium.crypto_secretstream_xchacha20poly1305_push(stream.state, plain, null, tag);
    await uploadChunk(index, encrypted);
    onProgress?.(Math.round(((index + 1) / chunkCount) * 100));
  }
  sodium.memzero(key);
  return {
    salt: b64(salt),
    header: b64(stream.header),
    chunkSize: FILE_CHUNK_SIZE,
    chunkCount,
    size: file.size,
  };
}

export async function decryptFile(encryptedBuffer, meta, sharedKey, mime) {
  await sodiumReady();
  const key = deriveFileKey(sharedKey, unb64(meta.salt));
  const state = sodium.crypto_secretstream_xchacha20poly1305_init_pull(unb64(meta.header), key);
  const encrypted = new Uint8Array(encryptedBuffer);
  const chunks = [];
  let offset = 0;
  let remainingPlain = meta.size;
  const overhead = sodium.crypto_secretstream_xchacha20poly1305_ABYTES;

  for (let index = 0; index < meta.chunkCount; index += 1) {
    const plainLength = Math.min(meta.chunkSize, remainingPlain);
    const cipherLength = plainLength + overhead;
    const cipherChunk = encrypted.slice(offset, offset + cipherLength);
    const result = sodium.crypto_secretstream_xchacha20poly1305_pull(state, cipherChunk, null);
    if (!result) throw new Error('Файл повреждён или подменён');
    const shouldBeFinal = index === meta.chunkCount - 1;
    if (shouldBeFinal && result.tag !== sodium.crypto_secretstream_xchacha20poly1305_TAG_FINAL) {
      throw new Error('Файл загружен не полностью');
    }
    chunks.push(result.message);
    offset += cipherLength;
    remainingPlain -= plainLength;
  }
  sodium.memzero(key);
  return new Blob(chunks, { type: mime || 'application/octet-stream' });
}
