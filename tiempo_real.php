<?php
// Drawsito v1.1.2 — Colaboración: PG rooms + ops + presencia + NOTIFY
@ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__.'/config.php';
if (PERSISTENCIA === 'pg') require_once __DIR__.'/db.php';

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';
function out($x){ echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }

// ——— knobs de rendimiento ———
const POLL_TIMEOUT_SEC = 25;     // long-poll máximo
const POLL_TICK_US     = 100000; // 100ms entre chequeos

// ——— aplicar operación pura sobre el documento ———
function aplicarOp(&$doc, $op){
  $t = $op['type'] ?? '';
  if ($t==='add_node'){ $doc['nodos'][]=$op['node']; return; }
  if ($t==='move_node' || $t==='resize_node' || $t==='set_title'){
    foreach($doc['nodos'] as &$n){
      if (($n['id']??null)==($op['id']??null)){
        if ($t==='move_node'){ $n['x']=$op['x']; $n['y']=$op['y']; }
        if ($t==='resize_node'){ $n['ancho']=$op['ancho']; $n['alto']=$op['alto']; }
        if ($t==='set_title'){ $n['titulo']=$op['titulo']; }
        return;
      }
    }
  }
  if ($t==='set_attr' || $t==='del_attr'){
    foreach($doc['nodos'] as &$n){
      if (($n['id']??null)==($op['id']??null)){
        $n['atributos'] = $n['atributos'] ?? [];
        if ($t==='set_attr'){ $n['atributos'][$op['idx']]=$op['text']; }
        else array_splice($n['atributos'],$op['idx'],1);
        return;
      }
    }
  }
  if ($t==='set_method' || $t==='del_method'){
    foreach($doc['nodos'] as &$n){
      if (($n['id']??null)==($op['id']??null)){
        $n['metodos'] = $n['metodos'] ?? [];
        if ($t==='set_method'){ $n['metodos'][$op['idx']]=$op['text']; }
        else array_splice($n['metodos'],$op['idx'],1);
        return;
      }
    }
  }
  if ($t==='delete_node'){
    $id=$op['id'];
    $doc['nodos']=array_values(array_filter($doc['nodos'],fn($n)=>($n['id']??null)!=$id));
    $doc['aristas']=array_values(array_filter($doc['aristas'],fn($a)=>($a['origenId']??null)!=$id && ($a['destinoId']??null)!=$id));
    return;
  }
  if ($t==='add_edge'){ $doc['aristas'][]=$op['edge']; return; }
  if ($t==='update_edge'){
    foreach($doc['aristas'] as &$a){
      if (($a['id']??null)==($op['id']??null)){
        foreach(['anc_o','anc_d','etiqueta','card_o','card_d','tam','puntos'] as $k){
          if (array_key_exists($k,$op)) $a[$k]=$op[$k];
        }
        return;
      }
    }
  }
  if ($t==='delete_edge'){
    $id=$op['id'];
    $doc['aristas']=array_values(array_filter($doc['aristas'],fn($a)=>($a['id']??null)!=$id));
    return;
  }
  if ($t==='lock_node'){
    $doc['locks'] = $doc['locks'] ?? [];
    $doc['locks']['n_'.$op['id']] = ['by'=>$op['client_id'], 'until'=>$op['until']];
    return;
  }
  if ($t==='unlock_node'){
    if (isset($doc['locks'])) unset($doc['locks']['n_'.$op['id']]);
    return;
  }
}

if (PERSISTENCIA !== 'pg'){
  out(['ok'=>false,'msg'=>'Habilita PG en config.php']);
}

$pdo = db_pg();
$uid = $_SESSION['user_id'] ?? null;

if ($accion==='join'){
  $room = $_POST['room'] ?? '';
  if ($room==='') out(['ok'=>false,'msg'=>'room requerido']);

  $pdo->beginTransaction();
  $s = $pdo->prepare('SELECT version, doc FROM rooms WHERE room=:r FOR UPDATE');
  $s->execute([':r'=>$room]);
  $row = $s->fetch();
  if (!$row){
    $doc_ini = ['nodos'=>[],'aristas'=>[],'locks'=>[]];
    $pdo->prepare('INSERT INTO rooms(room,version,doc) VALUES(:r,0,:d::jsonb)')
        ->execute([':r'=>$room, ':d'=>json_encode($doc_ini,JSON_UNESCAPED_UNICODE)]);
    $row = ['version'=>0,'doc'=>$doc_ini];
  } else {
    $row['doc'] = is_array($row['doc']) ? $row['doc'] : json_decode($row['doc'], true);
    if (!is_array($row['doc'])) $row['doc']=['nodos'=>[],'aristas'=>[],'locks'=>[]];
  }

  if ($uid){
    $pdo->prepare('INSERT INTO room_users(room,user_id) VALUES(:r,:u)
                   ON CONFLICT(room,user_id) DO UPDATE SET last_seen=now()')
        ->execute([':r'=>$room, ':u'=>$uid]);
  }

  $pdo->commit();
  $client='c_'.bin2hex(random_bytes(6));
  out(['ok'=>true,'version'=>(int)$row['version'],'client_id'=>$client,'doc'=>$row['doc']]);
}

if ($accion==='op'){
  $room   = $_POST['room'] ?? '';
  $client = $_POST['client_id'] ?? '';
  $opJson = $_POST['op'] ?? '';
  $op     = $opJson ? json_decode($opJson,true) : null;
  if (!$room || !$client || !$op) out(['ok'=>false,'msg'=>'faltan datos']);

  $pdo->beginTransaction();
  $s = $pdo->prepare('SELECT version, doc FROM rooms WHERE room=:r FOR UPDATE');
  $s->execute([':r'=>$room]);
  $row = $s->fetch();
  $doc = $row ? (is_array($row['doc']) ? $row['doc'] : json_decode($row['doc'],true)) : ['nodos'=>[],'aristas'=>[],'locks'=>[]];
  $ver = $row ? (int)$row['version'] : 0;

  // bloqueo suave para texto
  $t=$op['type']??'';
  if (in_array($t,['set_title','set_attr','set_method'],true)){
    $idNodo=$op['id']??null;
    $lk='n_'.$idNodo; $now=(int)(microtime(true)*1000);
    $locks = is_array($doc['locks']??null) ? $doc['locks'] : [];
    $hay = isset($locks[$lk]);
    $until = $hay ? (int)($locks[$lk]['until']??0) : 0;
    $by    = $hay ? (string)($locks[$lk]['by']??'') : '';
    if ($hay && $until>$now && $by!==$client){ $pdo->rollBack(); out(['ok'=>false,'reason'=>'locked']); }
  }

  // aplicar mutación pura
  aplicarOp($doc,$op);

  // persistir doc + version
  $u=$pdo->prepare('UPDATE rooms SET doc=:d::jsonb, version=version+1, actualizado_at=now() WHERE room=:r RETURNING version');
  $u->execute([':d'=>json_encode($doc,JSON_UNESCAPED_UNICODE), ':r'=>$room]);
  $nver=(int)$u->fetchColumn();

  // registrar op
  $pdo->prepare('INSERT INTO room_ops(room,ver,op,ts_ms,user_id) VALUES(:r,:v,:o::jsonb,:t,:u)')
      ->execute([':r'=>$room, ':v'=>$nver, ':o'=>json_encode($op,JSON_UNESCAPED_UNICODE), ':t'=>(int)(microtime(true)*1000), ':u'=>$uid]);

  if ($uid){
    $pdo->prepare('INSERT INTO room_users(room,user_id) VALUES(:r,:u)
                   ON CONFLICT(room,user_id) DO UPDATE SET last_seen=now()')
        ->execute([':r'=>$room, ':u'=>$uid]);
  }

  // ——— NUEVO: NOTIFICAR por PG (LISTEN/NOTIFY) ———
  // Enviamos un payload compacto con la sala, la versión confirmada y la op.
  $payload = json_encode([
    'room' => $room,
    'ver'  => $nver,
    'op'   => $op
  ], JSON_UNESCAPED_UNICODE);

  // NOTA: el nombre del canal va literal (no parametrizable); el payload sí.
  $pdo->query("SELECT pg_notify('rooms', " . $pdo->quote($payload) . ")");

  $pdo->commit();
  out(['ok'=>true,'version'=>$nver]);
}

if ($accion==='poll'){
  $room = $_GET['room'] ?? '';
  $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
  if (!$room) out(['ok'=>false,'msg'=>'room requerido']);

  $inicio=microtime(true);
  while(true){
    $s=$pdo->prepare('SELECT version FROM rooms WHERE room=:r');
    $s->execute([':r'=>$room]);
    $ver=(int)($s->fetchColumn() ?? 0);
    if ($ver>$since){
      $ops=[];
      $o=$pdo->prepare('SELECT ver, op, ts_ms FROM room_ops WHERE room=:r AND ver>:v ORDER BY ver ASC LIMIT 200');
      $o->execute([':r'=>$room, ':v'=>$since]);
      while($row=$o->fetch(PDO::FETCH_ASSOC)){
        $ops[]=['v'=>(int)$row['ver'], 'op'=>json_decode($row['op'],true), 'ts'=>(int)$row['ts_ms']];
      }
      out(['ok'=>true,'version'=>$ver,'ops'=>$ops]);
    }
    if (microtime(true)-$inicio>POLL_TIMEOUT_SEC){ out(['ok'=>true,'version'=>$ver,'ops'=>[]]); }
    usleep(POLL_TICK_US);
  }
}

out(['ok'=>false,'msg'=>'accion invalida']);
