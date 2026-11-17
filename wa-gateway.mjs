// ============================================================
// wa-gateway.mjs — Gateway WhatsApp Natix Swim (Baileys, TIDAK RESMI)
//
// Alur:  WhatsApp <-> Baileys (file ini) <-> PHP (manajemen-ai-chat.php)
//   - Pesan masuk  : diteruskan ke PHP  (POST PHP_WEBHOOK_URL, header x-gateway-token)
//   - Pesan keluar : PHP memanggil API file ini (POST /kirim)
//   - Login        : pindai QR di halaman Chat Log (tombol "WhatsApp ...")
//
// Jalankan:  npm install   lalu   node wa-gateway.mjs
// Konfigurasi lewat environment variable (semua opsional):
//   PORT=3001  HOST=127.0.0.1  GATEWAY_TOKEN=<sama dengan GW_TOKEN di PHP>
//   PHP_WEBHOOK_URL=http://localhost/NatixSwim/manajemen-ai-chat.php?aksi=webhook_baileys
//   AUTH_DIR=./auth_info   MARK_READ=1   JEDA_MIN=1500  JEDA_MAX=3500
//
// PERINGATAN: Baileys meniru WhatsApp Web dan melanggar ketentuan WhatsApp. Nomor bisa diblokir.
// Pakai nomor khusus, jangan kirim massal, dan jangan membalas orang yang belum pernah menghubungi Anda.
// ============================================================
import makeWASocket, { useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, downloadMediaMessage } from '@whiskeysockets/baileys';
import pino from 'pino';
import QRCode from 'qrcode';
import http from 'node:http';
import fs from 'node:fs';
import crypto from 'node:crypto';

const PORT = parseInt(process.env.PORT || '3001', 10);
const HOST = process.env.HOST || '127.0.0.1';
const TOKEN = process.env.GATEWAY_TOKEN || 'natix-gateway-secret';
const WEBHOOK = process.env.PHP_WEBHOOK_URL || 'http://localhost/NatixSwim/manajemen-ai-chat.php?aksi=webhook_baileys';
const AUTH_DIR = process.env.AUTH_DIR || './auth_info';
const MARK_READ = (process.env.MARK_READ ?? '1') === '1';
const JEDA_MIN = parseInt(process.env.JEDA_MIN || '1500', 10);
const JEDA_MAX = parseInt(process.env.JEDA_MAX || '3500', 10);
const MAX_MEDIA = 4 * 1024 * 1024;      // file masuk lebih besar dari ini tidak diteruskan (batas post_max_size PHP)
const MAX_BODY = 12 * 1024 * 1024;

const logger = pino({ level: process.env.LOG_LEVEL || 'warn' });
const log = (...a) => console.log(new Date().toLocaleTimeString('id-ID'), ...a);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const acak = (a, b) => Math.floor(a + Math.random() * (b - a + 1));

if (TOKEN === 'natix-gateway-secret') log('PERINGATAN: GATEWAY_TOKEN masih bawaan. Ganti dengan token acak (samakan dengan GW_TOKEN di PHP).');

// ---------------- koneksi WhatsApp ----------------
let sock = null;
let status = 'closed';        // closed | connecting | qr | open
let qrData = null;            // data URL gambar QR
let akun = { nomor: '', nama: '' };
let sedangSambung = false;
let keluarManual = false;

async function sambung() {
    if (sedangSambung) return;
    sedangSambung = true;
    try {
        const { state: auth, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
        let version;
        try { ({ version } = await fetchLatestBaileysVersion()); } catch { /* pakai versi bawaan library */ }
        status = 'connecting'; qrData = null;
        const s = makeWASocket({ auth, version, logger, printQRInTerminal: false, markOnlineOnConnect: false, syncFullHistory: false, browser: ['Natix Swim', 'Chrome', '1.0'] });
        sock = s;

        s.ev.on('creds.update', saveCreds);

        s.ev.on('connection.update', async (u) => {
            if (s !== sock) return;
            const { connection, lastDisconnect, qr } = u;
            if (qr) { try { qrData = await QRCode.toDataURL(qr, { margin: 1, width: 280 }); status = 'qr'; log('QR siap dipindai (buka halaman Chat Log).'); } catch (e) { log('Gagal membuat QR:', e.message); } }
            if (connection === 'open') {
                status = 'open'; qrData = null;
                const id = s.user?.id || '';
                akun = { nomor: id.split(':')[0].split('@')[0], nama: s.user?.name || '' };
                log('Terhubung sebagai', akun.nomor, akun.nama);
            }
            if (connection === 'close') {
                status = 'closed'; qrData = null;
                const kode = lastDisconnect?.error?.output?.statusCode;
                log('Koneksi terputus, kode:', kode);
                if (keluarManual) return;
                if (kode === DisconnectReason.loggedOut) {   // perangkat dihapus dari HP -> bersihkan sesi, minta QR baru
                    bersihkanSesi();
                    setTimeout(sambung, 1500);
                } else {
                    setTimeout(sambung, 3000);
                }
            }
        });

        s.ev.on('messages.upsert', async ({ messages, type }) => {
            if (s !== sock || type !== 'notify') return;
            for (const m of messages) {
                try { await tangani(m); } catch (e) { log('Gagal memproses pesan:', e.message); }
            }
        });

        s.ev.on('messages.update', async (list) => {
            if (s !== sock) return;
            const peta = { 2: 'sent', 3: 'delivered', 4: 'read', 5: 'read' };
            for (const { key, update } of list) {
                if (!key?.fromMe || update?.status == null || !peta[update.status]) continue;
                kePhp({ jenis: 'status', id: key.id, status: peta[update.status] });
            }
        });
    } catch (e) {
        log('Gagal memulai koneksi:', e.message);
        status = 'closed';
        setTimeout(sambung, 5000);
    } finally { sedangSambung = false; }
}

function bersihkanSesi() { try { fs.rmSync(AUTH_DIR, { recursive: true, force: true }); } catch (e) { log('Gagal menghapus sesi:', e.message); } }

// ---------------- pesan masuk -> PHP ----------------
function angka(jid) { return String(jid || '').split('@')[0].split(':')[0]; }

function nomorPengirim(key) {
    // v7 memakai LID; nomor asli ada di remoteJidAlt/senderPn bila tersedia
    for (const c of [key.remoteJidAlt, key.senderPn, key.remoteJid]) {
        if (typeof c === 'string' && c.endsWith('@s.whatsapp.net')) return angka(c);
    }
    const rj = key.remoteJid || '';
    return rj.endsWith('@lid') ? 'lid:' + angka(rj) : '';
}

function buka(message) {
    let m = message;
    for (let i = 0; i < 4; i++) {
        const dalam = m.ephemeralMessage?.message || m.viewOnceMessage?.message || m.viewOnceMessageV2?.message || m.documentWithCaptionMessage?.message;
        if (!dalam) break;
        m = dalam;
    }
    return m;
}

async function tangani(m) {
    const key = m.key;
    if (!key || key.fromMe || !m.message) return;
    const rj = key.remoteJid || '';
    if (!rj || rj.endsWith('@g.us') || rj.endsWith('@broadcast') || rj.endsWith('@newsletter')) return;   // grup/status/channel diabaikan
    const from = nomorPengirim(key);
    if (!from) return;

    const msg = buka(m.message);
    let tipe = 'lainnya', teks = '', media = null;
    if (msg.conversation) { tipe = 'text'; teks = msg.conversation; }
    else if (msg.extendedTextMessage) { tipe = 'text'; teks = msg.extendedTextMessage.text || ''; }
    else if (msg.imageMessage) { tipe = 'image'; teks = msg.imageMessage.caption || ''; }
    else if (msg.documentMessage) { tipe = 'document'; teks = msg.documentMessage.caption || ''; }
    else if (msg.audioMessage) tipe = 'audio';
    else if (msg.videoMessage) { tipe = 'video'; teks = msg.videoMessage.caption || ''; }
    else if (msg.stickerMessage) tipe = 'sticker';
    else if (msg.locationMessage) tipe = 'location';
    else if (msg.buttonsResponseMessage) { tipe = 'text'; teks = msg.buttonsResponseMessage.selectedDisplayText || ''; }
    else if (msg.listResponseMessage) { tipe = 'text'; teks = msg.listResponseMessage.title || ''; }
    else if (msg.templateButtonReplyMessage) { tipe = 'text'; teks = msg.templateButtonReplyMessage.selectedDisplayText || ''; }
    else if (msg.reactionMessage || msg.protocolMessage || msg.senderKeyDistributionMessage) return;   // bukan pesan percakapan

    if (tipe === 'image' || tipe === 'document') {
        const info = msg.imageMessage || msg.documentMessage;
        if (Number(info.fileLength || 0) > MAX_MEDIA) {
            teks = (teks ? teks + ' ' : '') + '[file terlalu besar untuk diteruskan]';
        } else {
            try {
                const buf = await downloadMediaMessage(m, 'buffer', {}, { logger, reuploadRequest: sock.updateMediaMessage });
                if (buf.length <= MAX_MEDIA) media = { base64: buf.toString('base64'), mimetype: info.mimetype || '', filename: info.fileName || '' };
            } catch (e) { log('Gagal mengunduh media:', e.message); }
        }
    }

    const ok = await kePhp({
        jenis: 'pesan', id: key.id, from, nama: m.pushName || null,
        timestamp: Number(m.messageTimestamp) || Math.floor(Date.now() / 1000), tipe, teks, media,
    });
    if (ok && MARK_READ && sock) { try { await sock.readMessages([key]); } catch { /* abaikan */ } }
}

async function kePhp(payload) {
    for (let i = 0; i < 4; i++) {
        try {
            const r = await fetch(WEBHOOK, { method: 'POST', headers: { 'content-type': 'application/json', 'x-gateway-token': TOKEN }, body: JSON.stringify(payload) });
            if (r.ok) return true;
            log('Webhook PHP menjawab HTTP', r.status, (await r.text()).slice(0, 120));
            if (r.status === 403) return false;   // token salah: percuma diulang
        } catch (e) { log('Webhook PHP tidak terjangkau:', e.message); }
        await sleep(1000 * 2 ** i);
    }
    log('Pesan', payload.id, 'gagal diteruskan ke PHP setelah 4 percobaan.');
    return false;
}

// ---------------- pesan keluar ----------------
let antrean = Promise.resolve();
function jalurKirim(fn) {   // satu per satu + jeda acak agar tidak terlihat seperti spam
    const p = antrean.then(async () => { const r = await fn(); await sleep(acak(JEDA_MIN, JEDA_MAX)); return r; });
    antrean = p.catch(() => {});
    return p;
}

class GwError extends Error { constructor(kode, pesan) { super(pesan); this.kode = kode; } }

async function kirim(b) {
    if (status !== 'open' || !sock) throw new GwError(503, 'WhatsApp belum terhubung. Pindai QR di halaman Chat Log.');
    const to = String(b.to || '');
    const teks = String(b.text || '');
    if (!teks && !b.file_base64) throw new GwError(422, 'Pesan kosong.');
    let jid;
    if (to.startsWith('lid:') && /^\d{6,20}$/.test(to.slice(4))) jid = to.slice(4) + '@lid';
    else if (/^\d{8,15}$/.test(to)) {
        jid = to + '@s.whatsapp.net';
        if (b.cek) {
            const r = await sock.onWhatsApp(jid);
            if (!r?.[0]?.exists) throw new GwError(422, 'Nomor tersebut tidak terdaftar di WhatsApp.');
            jid = r[0].jid;
        }
    } else throw new GwError(422, 'Nomor tujuan tidak valid.');

    const hasil = await jalurKirim(async () => {
        try { await sock.presenceSubscribe(jid); await sock.sendPresenceUpdate('composing', jid); await sleep(acak(700, 1800)); await sock.sendPresenceUpdate('paused', jid); } catch { /* opsional */ }
        let isi;
        if (b.file_base64) {
            const buf = Buffer.from(String(b.file_base64), 'base64');
            if (buf.length > 12 * 1024 * 1024) throw new GwError(422, 'File terlalu besar.');
            isi = b.mimetype === 'application/pdf'
                ? { document: buf, mimetype: 'application/pdf', fileName: b.filename || 'dokumen.pdf', caption: teks || undefined }
                : { image: buf, caption: teks || undefined };
        } else isi = { text: teks };
        return sock.sendMessage(jid, isi);
    });
    return { id: hasil?.key?.id || null };
}

// ---------------- HTTP API ----------------
function sama(a, b) { const x = Buffer.from(String(a)), y = Buffer.from(String(b)); return x.length === y.length && crypto.timingSafeEqual(x, y); }

function bacaBody(req) {
    return new Promise((res, rej) => {
        let n = 0; const bag = [];
        req.on('data', (c) => { n += c.length; if (n > MAX_BODY) { rej(new GwError(413, 'Permintaan terlalu besar.')); req.destroy(); } else bag.push(c); });
        req.on('end', () => { try { res(bag.length ? JSON.parse(Buffer.concat(bag).toString('utf8')) : {}); } catch { rej(new GwError(400, 'JSON tidak valid.')); } });
        req.on('error', rej);
    });
}

const server = http.createServer(async (req, res) => {
    const out = (kode, o) => { res.writeHead(kode, { 'content-type': 'application/json; charset=utf-8' }); res.end(JSON.stringify(o)); };
    try {
        if (!sama(req.headers['x-gateway-token'] || '', TOKEN)) return out(401, { ok: false, pesan: 'Token gateway salah.' });
        const path = (req.url || '').split('?')[0];
        if (req.method === 'GET' && path === '/status') return out(200, { ok: true, state: status, qr: status === 'qr' ? qrData : null, nomor: akun.nomor, nama: akun.nama });
        if (req.method === 'POST' && path === '/kirim') return out(200, { ok: true, ...(await kirim(await bacaBody(req))) });
        if (req.method === 'POST' && path === '/sambung') { if (status === 'closed') sambung(); return out(200, { ok: true }); }
        if (req.method === 'POST' && path === '/logout') {
            keluarManual = true;
            try { await sock?.logout(); } catch { /* sudah putus */ }
            bersihkanSesi(); status = 'closed'; qrData = null; akun = { nomor: '', nama: '' };
            setTimeout(() => { keluarManual = false; sambung(); }, 1500);   // langsung siapkan QR baru
            return out(200, { ok: true });
        }
        out(404, { ok: false, pesan: 'Endpoint tidak ditemukan.' });
    } catch (e) {
        if (e instanceof GwError) return out(e.kode, { ok: false, pesan: e.message });
        log('Error API:', e.message);
        out(500, { ok: false, pesan: 'Kesalahan gateway: ' + e.message });
    }
});

server.listen(PORT, HOST, () => { log(`Gateway WhatsApp berjalan di http://${HOST}:${PORT}`); sambung(); });
process.on('unhandledRejection', (e) => log('unhandledRejection:', e?.message || e));
process.on('uncaughtException', (e) => log('uncaughtException:', e?.message || e));
