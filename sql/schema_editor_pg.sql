-- Drawsito v1.1.0 — Esquema PG para usuarios + colaboración
-- Ejecuta este archivo (o psql) contra la BD `drawsito`.

CREATE TABLE IF NOT EXISTS usuarios (
  id           bigserial PRIMARY KEY,
  alias        text        NOT NULL DEFAULT 'invitado',
  email        text        UNIQUE,
  pass_hash    text,
  creado_at    timestamptz NOT NULL DEFAULT now(),
  ultimo_login timestamptz
);

CREATE TABLE IF NOT EXISTS diagramas (
  id             text PRIMARY KEY,
  owner_id       bigint REFERENCES usuarios(id) ON DELETE SET NULL,
  titulo         text,
  json           jsonb NOT NULL,
  creado_at      timestamptz NOT NULL DEFAULT now(),
  actualizado_at timestamptz NOT NULL DEFAULT now()
);

-- Presencia y estado de salas
CREATE TABLE IF NOT EXISTS rooms (
  room          text PRIMARY KEY,
  version       bigint NOT NULL DEFAULT 0,
  doc           jsonb  NOT NULL DEFAULT jsonb_build_object('nodos','[]'::jsonb,'aristas','[]'::jsonb,'locks','{}'::jsonb),
  actualizado_at timestamptz NOT NULL DEFAULT now()
);

-- Usuarios presentes en una sala
CREATE TABLE IF NOT EXISTS room_users (
  room      text   NOT NULL,
  user_id   bigint NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  joined_at timestamptz NOT NULL DEFAULT now(),
  last_seen timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY(room, user_id)
);

-- Registro de operaciones
CREATE TABLE IF NOT EXISTS room_ops (
  id     bigserial PRIMARY KEY,
  room   text   NOT NULL,
  ver    bigint NOT NULL,
  op     jsonb  NOT NULL,
  ts_ms  bigint NOT NULL,
  user_id bigint REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT uq_room_ver UNIQUE(room, ver)
);
CREATE INDEX IF NOT EXISTS idx_room_ops_room_ver ON room_ops(room, ver);

-- Trigger de actualizado_at para diagramas
CREATE OR REPLACE FUNCTION touch_diagramas() RETURNS trigger AS $$
BEGIN NEW.actualizado_at = now(); RETURN NEW; END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_touch_diagramas ON diagramas;
CREATE TRIGGER trg_touch_diagramas BEFORE UPDATE ON diagramas
FOR EACH ROW EXECUTE FUNCTION touch_diagramas();

-- Compatibilidad y mejoras (idempotentes)
ALTER TABLE diagramas  ADD COLUMN IF NOT EXISTS owner_id bigint REFERENCES usuarios(id) ON DELETE SET NULL;
ALTER TABLE room_ops   ADD COLUMN IF NOT EXISTS user_id  bigint REFERENCES usuarios(id) ON DELETE SET NULL;