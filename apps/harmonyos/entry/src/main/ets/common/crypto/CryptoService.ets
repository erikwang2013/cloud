import { cryptoFramework } from '@kit.CryptoArchitectureKit';
import { util } from '@kit.ArkTS';
import { ENCRYPTION_MASTER_KEY } from '../config/AppConfig';

// AES-256-GCM transport encryption, wire-compatible with PHP
// Erikwang2013\Encryption\Encryptor\Aes256GcmEncryptor:
//   key = HMAC-SHA256(masterKey, 'dgn:derive:aes-gcm')
//   payload = 'v1' | iv(12) | tag(16) | ciphertext   (base64'd by caller)
const PREFIX = 'v1';
const IV_LEN = 12;
const DERIVE_INFO = 'dgn:derive:aes-gcm';

const B64 = new util.Base64Helper();

export class CryptoService {
  private static aesKey: cryptoFramework.SymKey;

  private static async getAesKey(): Promise<cryptoFramework.SymKey> {
    if (!CryptoService.aesKey) {
      const masterKey = B64.decodeSync(ENCRYPTION_MASTER_KEY);
      if (masterKey.length !== 32) {
        throw new Error('ENCRYPTION_MASTER_KEY must decode to 32 bytes');
      }
      const mac = cryptoFramework.createMac('SHA256');
      const macKey = await cryptoFramework.createSymKeyGenerator('HMAC').convertKey({ data: masterKey });
      await mac.init(macKey);
      await mac.update({ data: util.TextEncoder.create().encodeInto(DERIVE_INFO) });
      const derived = await mac.doFinal();
      CryptoService.aesKey =
        await cryptoFramework.createSymKeyGenerator('AES256').convertKey({ data: derived.data });
    }
    return CryptoService.aesKey;
  }

  static async encrypt(plaintext: string): Promise<string> {
    const key = await CryptoService.getAesKey();
    const iv = cryptoFramework.createRandom().generateRandomSync(IV_LEN);
    const gcm: cryptoFramework.GcmParamsSpec = {
      algName: 'GCM',
      iv: { data: iv.data },
      aad: { data: new Uint8Array(0) },
      authTag: { data: new Uint8Array(0) },
    };
    const cipher = cryptoFramework.createCipher('AES256|GCM|NoPadding');
    await cipher.init(cryptoFramework.CryptoMode.ENCRYPT_MODE, key, gcm);
    const out1 = await cipher.update({ data: util.TextEncoder.create().encodeInto(plaintext) });
    const out2 = await cipher.doFinal(null);
    const ct = new Uint8Array(out1.data.length + out2.data.length);
    ct.set(out1.data, 0);
    ct.set(out2.data, out1.data.length);
    const tag = gcm.authTag.data;
    const blob = new Uint8Array(PREFIX.length + IV_LEN + tag.length + ct.length);
    blob.set(util.TextEncoder.create().encodeInto(PREFIX), 0);
    blob.set(iv.data, PREFIX.length);
    blob.set(tag, PREFIX.length + IV_LEN);
    blob.set(ct, PREFIX.length + IV_LEN + tag.length);
    return B64.encodeToStringSync(blob);
  }

  static async decrypt(base64: string): Promise<string> {
    const key = await CryptoService.getAesKey();
    const blob = B64.decodeSync(base64);
    const prefix = util.TextEncoder.create().encodeInto(PREFIX);
    for (let i = 0; i < prefix.length; i++) {
      if (blob[i] !== prefix[i]) {
        throw new Error('Invalid ciphertext prefix');
      }
    }
    const iv = blob.slice(PREFIX.length, PREFIX.length + IV_LEN);
    const tag = blob.slice(PREFIX.length + IV_LEN, PREFIX.length + IV_LEN + 16);
    const ct = blob.slice(PREFIX.length + IV_LEN + 16);
    const gcm: cryptoFramework.GcmParamsSpec = {
      algName: 'GCM',
      iv: { data: iv },
      aad: { data: new Uint8Array(0) },
      authTag: { data: tag },
    };
    const cipher = cryptoFramework.createCipher('AES256|GCM|NoPadding');
    await cipher.init(cryptoFramework.CryptoMode.DECRYPT_MODE, key, gcm);
    const out1 = await cipher.update({ data: ct });
    const out2 = await cipher.doFinal(null);
    const plain = new Uint8Array(out1.data.length + out2.data.length);
    plain.set(out1.data, 0);
    plain.set(out2.data, out1.data.length);
    return util.TextDecoder.create().decodeToString(plain);
  }
}
