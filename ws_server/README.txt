WS Relay para Drawsito (instantáneo):
1) cd "C:\xampp\htdocs\proyecto\drawsito_back\ws_server"
2) npm.cmd install
3) $env:PG_URL = "postgres://postgres:Malware123@127.0.0.1:5432/drawsito"
4) $env:WS_PORT = "8088"
5) npm.cmd start
- Escucha en ws://localhost:8088 por defecto
- Requiere que tiempo_real.php haga pg_notify('rooms', payload_json)