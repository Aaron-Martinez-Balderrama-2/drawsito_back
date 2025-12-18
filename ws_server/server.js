// Drawsito WS Relay — LISTEN/NOTIFY → WebSocket broadcast (Railway Ready)
import { WebSocketServer } from 'ws';
import pg from 'pg';

const WS_PORT = Number(process.env.PORT || process.env.WS_PORT || 8088);

// Railway: usa PG_URL o DATABASE_URL (referencia al Postgres del proyecto)
const PG_URL = process.env.PG_URL || process.env.DATABASE_URL || '';

if (!PG_URL) {
  console.error('[WS] Falta PG_URL o DATABASE_URL en Variables (Railway).');
  process.exit(1);
}

const usarSSL =
  (process.env.PGSSLMODE || '').toLowerCase() === 'require' ||
  /sslmode=require/i.test(PG_URL) ||
  (process.env.FORCE_PG_SSL || '') === '1';

const pgOpts = usarSSL
  ? { connectionString: PG_URL, ssl: { rejectUnauthorized: false } }
  : { connectionString: PG_URL };

const wss = new WebSocketServer({ port: WS_PORT });
console.log(`[WS] Relay escuchando en ws://0.0.0.0:${WS_PORT}`);

const clients = new Set(); // {ws, room, id}

function send(ws, obj) {
  try { ws.send(JSON.stringify(obj)); } catch (_) {}
}

// Heartbeat para limpiar conexiones muertas
function heartbeat() {
  for (const c of clients) {
    if (c.ws.isAlive === false) {
      try { c.ws.terminate(); } catch (_) {}
      clients.delete(c);
      continue;
    }
    c.ws.isAlive = false;
    try { c.ws.ping(); } catch (_) {}
  }
}
setInterval(heartbeat, 30000);

// Conexiones WS
wss.on('connection', (ws) => {
  ws.isAlive = true;
  ws.on('pong', () => (ws.isAlive = true));

  const client = { ws, room: null, id: Math.random().toString(36).slice(2) };
  clients.add(client);

  ws.on('message', (raw) => {
    let msg = null;
    try { msg = JSON.parse(raw.toString()); } catch (_) { return; }

    if (msg && msg.type === 'hello' && typeof msg.room === 'string') {
      client.room = msg.room;
      send(ws, { type: 'hello_ok', room: client.room });
    }
  });

  ws.on('close', () => clients.delete(client));
  ws.on('error', () => clients.delete(client));
});

const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

async function conectarPgYEscuchar() {
  for (;;) {
    const pgClient = new pg.Client(pgOpts);

    try {
      await pgClient.connect();
      console.log('[WS] Conectado a PostgreSQL');

      await pgClient.query('LISTEN rooms');
      console.log('[WS] LISTEN rooms');

      pgClient.on('notification', (msg) => {
        if (msg.channel !== 'rooms') return;

        let payload = null;
        try { payload = JSON.parse(msg.payload); } catch (_) { return; }
        // payload esperado: {room, ver, op}
        for (const c of clients) {
          if (c.room && c.room === payload.room) {
            send(c.ws, { type: 'op', room: payload.room, ver: payload.ver, op: payload.op });
          }
        }
      });

      pgClient.on('error', (e) => console.error('[WS] PG error:', e?.message || e));
      pgClient.on('end', () => console.warn('[WS] PG desconectado, reintentando...'));

      return pgClient; // queda vivo escuchando
    } catch (e) {
      console.error('[WS] No se pudo conectar a PG. Reintento en 2s. Detalle:', e?.message || e);
      try { await pgClient.end(); } catch (_) {}
      await dormir(2000);
    }
  }
}

// Mantener el proceso estable
process.on('unhandledRejection', (e) => console.error('[WS] unhandledRejection:', e));
process.on('uncaughtException', (e) => console.error('[WS] uncaughtException:', e));

await conectarPgYEscuchar();
