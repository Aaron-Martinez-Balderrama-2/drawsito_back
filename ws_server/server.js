// Drawsito WS Relay — LISTEN/NOTIFY → WebSocket broadcast
import { WebSocketServer } from 'ws';
import pg from 'pg';

// >>> Ajusta según tu entorno:
const WS_PORT = process.env.WS_PORT || 8088;
// Debe apuntar a la misma BD de Drawsito:
const PG_URL  = process.env.PG_URL  || 'postgres://postgres:Malware123@localhost:5432/drawsito';

const wss = new WebSocketServer({ port: WS_PORT });
console.log(`[WS] Relay escuchando en ws://localhost:${WS_PORT}`);

const clients = new Set(); // {ws, room}
function send(ws, obj){
  try{ ws.send(JSON.stringify(obj)); }catch(_){}
}

// Heartbeat para limpiar conexiones muertas
function heartbeat(){
  for(const c of clients){
    if (c.ws.isAlive === false) { try{ c.ws.terminate(); }catch(_){} clients.delete(c); continue; }
    c.ws.isAlive = false;
    try { c.ws.ping(); } catch (_) {}
  }
}
setInterval(heartbeat, 30000);

// Conexiones WS
wss.on('connection', (ws, req) => {
  ws.isAlive = true;
  ws.on('pong', ()=> ws.isAlive = true);

  // cada cliente declara la sala con un primer mensaje {type:'hello', room, client_id}
  const client = { ws, room: null, id: Math.random().toString(36).slice(2) };
  clients.add(client);

  ws.on('message', (raw) => {
    let msg = null;
    try{ msg = JSON.parse(raw.toString()); }catch(_){ return; }
    if (msg && msg.type === 'hello' && typeof msg.room === 'string'){
      client.room = msg.room;
      send(ws, {type:'hello_ok', room:client.room});
    }
    // (Opcional) podríamos aceptar 'op' y escribir a la BD, pero
    // mantenemos a PHP como autoridad de escritura por simplicidad.
  });

  ws.on('close', ()=> clients.delete(client));
  ws.on('error', ()=> clients.delete(client));
});

// Conexión PG y LISTEN
const pgClient = new pg.Client({ connectionString: PG_URL });
await pgClient.connect();
console.log('[WS] Conectado a PostgreSQL');
await pgClient.query('LISTEN rooms');
console.log('[WS] LISTEN rooms');

pgClient.on('notification', (msg) => {
  if (msg.channel !== 'rooms') return;
  let payload=null;
  try{ payload = JSON.parse(msg.payload); }catch(_){ return; }
  // payload: {room, ver, op}
  for (const c of clients) {
    if (!c.room) continue;
    if (c.room === payload.room) {
      send(c.ws, { type:'op', room:payload.room, ver:payload.ver, op:payload.op });
    }
  }
});
