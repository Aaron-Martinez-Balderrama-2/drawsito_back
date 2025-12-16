<?php
// proyecto/drawsito_back/generar_proyecto.php
// Generador “buitre”: Spring Boot + Flutter + SQL desde un diagrama Drawsito
@ini_set('display_errors','0');
date_default_timezone_set('UTC');
// AQUI HICE UN CAMBIO 15/12/25 para leer la configuración global (PERSISTENCIA) y saber si el diagrama se carga desde PG o desde archivos
require_once __DIR__.'/config.php';
// AQUI HICE UN CAMBIO 15/12/25 para habilitar la conexión a PostgreSQL solo cuando PERSISTENCIA sea 'pg' (así el generador puede leer desde la tabla diagramas)
if (defined('PERSISTENCIA') && PERSISTENCIA === 'pg') {
  require_once __DIR__.'/db.php';
}

if (!class_exists('ZipArchive')) {
  http_response_code(500);
  echo "Extensión ZipArchive no disponible. Habilita php_zip en php.ini y reinicia Apache.";
  exit;
}

$dir_base = __DIR__;
$dir_diag = $dir_base . DIRECTORY_SEPARATOR . 'diagramas';

function limpiar($s){ return preg_replace('/[^a-zA-Z0-9_]+/','_', trim($s)); }
function cls($s){ $s = trim($s);
return $s!==''? $s : 'Clase'; }

// FIX: reemplazo de /e por preg_replace_callback (compatibilidad PHP 7/8)
function camel($s){
  $s = strtolower($s);
return lcfirst(preg_replace_callback('/[_\s]+(.)/', function($m){
    return strtoupper($m[1]);
  }, $s));
}

function pascal($s){ $a = preg_split('/[_\s]+/', $s);
$a = array_map(fn($x)=>ucfirst(strtolower($x)), $a); return implode('',$a); }
function esMuchos($card){ return $card && (strpos($card,'*')!==false); }
function esUno($card){ return $card && (strpos($card,'1')!==false) && !esMuchos($card);
}

function tipo_sql($t){
  $t=strtolower(trim($t));
  if ($t===''||$t==='string'||$t==='varchar') return 'varchar(255)';
  if (preg_match('varchar\(\d+\)',$t)) return $t;
  if ($t==='text') return 'text';
  if ($t==='bool'||$t==='boolean') return 'boolean';
if ($t==='int'||$t==='integer') return 'integer';
  if ($t==='bigint'||$t==='long') return 'bigint';
  if ($t==='smallint') return 'smallint';
  if ($t==='decimal' || preg_match('decimal\(\d+,\d+\)',$t)) return $t==='decimal' ?
'decimal(10,2)' : $t;
  if ($t==='date') return 'date';
  if ($t==='time') return 'time';
  if ($t==='timestamp'||$t==='datetime') return 'timestamp';
  if ($t==='uuid') return 'uuid';
return 'varchar(255)';
}
function tipo_java($t){
  $t=strtolower(trim($t));
  if ($t===''||$t==='string'||strpos($t,'char')!==false||$t==='text') return 'String';
  if ($t==='bool'||$t==='boolean') return 'Boolean';
  if ($t==='int'||$t==='integer') return 'Integer';
if ($t==='bigint'||$t==='long') return 'Long';
  if ($t==='smallint') return 'Short';
  if ($t==='decimal'||preg_match('decimal\(\d+,\d+\)',$t)) return 'java.math.BigDecimal';
  if ($t==='date') return 'java.time.LocalDate';
  if ($t==='timestamp'||$t==='datetime') return 'java.time.LocalDateTime';
if ($t==='uuid') return 'java.util.UUID';
  return 'String';
}
function valor_java_default($t){
  $tj = tipo_java($t);
  if ($tj==='String') return '""';
  if ($tj==='Boolean') return 'false';
if ($tj==='Integer') return '0';
  if ($tj==='Long') return '0L';
  if ($tj==='Short') return '(short)0';
  if ($tj==='java.math.BigDecimal') return 'new java.math.BigDecimal("0")';
if ($tj==='java.time.LocalDate') return 'java.time.LocalDate.now()';
  if ($tj==='java.time.LocalDateTime') return 'java.time.LocalDateTime.now()';
  return 'null';
}

// ---- Cargar diagrama ----

// AQUI HICE UN CAMBIO 15/12/25 para sanitizar correctamente el id
$id = preg_replace('~[^a-zA-Z0-9\-_]~','', $_GET['id'] ?? '');
$json_in = $_POST['json'] ?? '';

if ($id===''){
  if ($json_in===''){ http_response_code(400); echo "Falta ?id=... o POST json"; exit;
}
  $doc = json_decode($json_in, true);
} else {

  // AQUI HICE UN CAMBIO 15/12/25 para que si PERSISTENCIA='pg' cargue el diagrama desde la tabla diagramas (json)
  if (defined('PERSISTENCIA') && PERSISTENCIA === 'pg') {
    $pdo = db_pg();
$s = $pdo->prepare('SELECT json FROM diagramas WHERE id = :id');
    $s->execute([':id' => $id]);
    $json = $s->fetchColumn();
    if (!$json){ http_response_code(404);
echo "No existe diagrama: $id"; exit; }
    $doc = json_decode($json, true);
// AQUI HICE UN CAMBIO 15/12/25 para que si PERSISTENCIA!='pg' mantenga el comportamiento original
  } else {
    $ruta = $dir_diag .
DIRECTORY_SEPARATOR . $id . '.json';
    if (!is_file($ruta)){ http_response_code(404); echo "No existe diagrama: $id"; exit;
}
    $doc = json_decode(file_get_contents($ruta), true);
  }
}

if (!is_array($doc)){ http_response_code(400); echo "JSON inválido"; exit; }

$nombre_db = limpiar($id ?: ('dibujo_'.date('Ymd_His')));
$pkg = 'com.auto.'.strtolower($nombre_db);
$nombre_app = pascal($nombre_db);

// ---- Extraer clases/atributos ----
$por_id = [];
$clases = [];
$id2idx = [];
foreach(($doc['nodos']??[]) as $n){
  if (($n['tipo']??'')!=='clase') continue;
  $name = pascal($n['titulo'] ?? 'Clase');
  $atr_lines = $n['atributos'] ?? [];
  $attrs = [];
$tieneId = false;

  foreach($atr_lines as $linea){
    $s=trim($linea);
    if ($s==='') continue;
    $vis = '';
    if ($s[0]=='+'||$s[0]=='#'||$s[0]=='-') { $vis=$s[0];
$s=trim(substr($s,1)); }
    $part = explode(':',$s,2);
    $campo = limpiar($part[0]);
    $rest = $part[1] ?? 'string';
    $trozos = preg_split('/\s+/', trim($rest));
$tipo = array_shift($trozos);
    $mods = array_map('strtolower',$trozos);
    $sql = tipo_sql($tipo);
    $java = tipo_java($tipo);
    $pk = in_array('pk',$mods);
    $ai = in_array('ai',$mods);
$unique = in_array('unique',$mods);
    $nullable = in_array('null',$mods) || in_array('nullable',$mods) || in_array('nulls',$mods);
    $fk_de = null;
foreach($mods as $m){
      if (preg_match('fk\(([^)]+)\)',$m,$mm)){ $fk_de = pascal($mm[1]);
}
      if (preg_match('len\((\d+)\)',$m,$mm)){ $sql = "varchar(".$mm[1].")"; $java='String';
}
    }
    if ($campo==='id'){ $pk=true; $tieneId=true; if ($sql==='integer') $sql='bigint'; $java='Long';
}
    $attrs[] = compact('campo','sql','java','pk','ai','unique','nullable','fk_de');
  }
  if (!$tieneId){
    $attrs = array_merge([['campo'=>'id','sql'=>'bigint','java'=>'Long','pk'=>true,'ai'=>true,'unique'=>false,'nullable'=>false,'fk_de'=>null]], $attrs);
}
  $clase = ['name'=>$name,'attrs'=>$attrs,'id'=>$n['id']];
  $id_actual = $n['id'];

  $clases[] = $clase;
  $por_id[$id_actual] = $clase;
  $id2idx[$id_actual] = count($clases)-1;
}

// helper para añadir FK evitando duplicados (renombrando si es necesario)
$add_fk = function(array &$tabla, array $ref) {
  $base = 'id_'.strtolower($ref['name']);
  $fk_name = $base;
  $c = 2;
  
  // Modificacion 15/12/25 v0.0.4 lo hice por que evito colision de nombres en FK (ej. id_clase, id_clase_2)
  while(true){
      $existe = false;
      foreach($tabla['attrs'] as $x){ 
          if($x['campo'] === $fk_name) { $existe = true; break; } 
      }
      if (!$existe) break;
      $fk_name = $base . '_' . $c;
      $c++;
  }

  $tabla['attrs'][] = [
    'campo'=>$fk_name,'sql'=>'bigint','java'=>'Long',
    'pk'=>false,'ai'=>false,'unique'=>false,'nullable'=>false,'fk_de'=>$ref['name']
  ];
};

foreach(($doc['aristas']??[]) as $a){
  $idx_o = $id2idx[$a['origenId']] ?? null;
  $idx_d = $id2idx[$a['destinoId']] ?? null;
  if ($idx_o===null || $idx_d===null) continue;
  $co = trim($a['card_o'] ?? '');
  $cd = trim($a['card_d'] ?? '');
  
  // Modificacion 15/12/25 v0.0.2 lo hice por que recupero la navegabilidad
  $nav = $a['nav'] ?? 'o2d'; 

  // Modificacion 15/12/25 v0.0.2 lo hice por que detecto si es Muchos a Muchos (M:N) por cardinalidad o por nav="both"
  $esMn = (esMuchos($co) && esMuchos($cd)) || ($nav === 'both');

  // Modificacion 15/12/25 v0.0.2 lo hice por que si es M:N creo la Tabla Detalle Virtual
  if ($esMn) {
      $claseO = $clases[$idx_o];
      $claseD = $clases[$idx_d];
      
      // Nombre compuesto: DetalleOrigenDestino
      $nombreDetalle = 'Detalle' . $claseO['name'] . $claseD['name'];
      
      // Estructura de la nueva clase virtual
      $claseDetalle = [
          'name' => $nombreDetalle,
          'id'   => 'virtual_' . $a['id'],
          'attrs' => [
              ['campo'=>'id','sql'=>'bigint','java'=>'Long','pk'=>true,'ai'=>true,'unique'=>false,'nullable'=>false,'fk_de'=>null]
          ]
      ];
      // Agrego FK a Origen
      $claseDetalle['attrs'][] = [
          'campo' => 'id_' . strtolower($claseO['name']),
          'sql' => 'bigint', 'java' => 'Long', 'pk' => false, 'ai' => false, 'unique' => false, 'nullable' => false,
          'fk_de' => $claseO['name']
      ];
      // Agrego FK a Destino
      $claseDetalle['attrs'][] = [
          'campo' => 'id_' . strtolower($claseD['name']),
          'sql' => 'bigint', 'java' => 'Long', 'pk' => false, 'ai' => false, 'unique' => false, 'nullable' => false,
          'fk_de' => $claseD['name']
      ];

      // La añado al array global para que genere codigo
      $clases[] = $claseDetalle;
      
      // Salto al siguiente ciclo, NO agrego FKs a los padres
      continue; 
  }

  $fk_asignada = false;

  // A(1) ---- (*)B  => B lleva FK a A
  if (esUno($co) && esMuchos($cd)){
    $add_fk($clases[$idx_d], $clases[$idx_o]);
    $fk_asignada = true; // Modificacion 15/12/25 v0.0.2 lo hice por que la cardinalidad resolvió la FK
  }
  // A(*) ---- (1)B  => A lleva FK a B
  else if (esMuchos($co) && esUno($cd)){ 
    $add_fk($clases[$idx_o], $clases[$idx_d]);
    $fk_asignada = true; // Modificacion 15/12/25 v0.0.2 lo hice por que la cardinalidad resolvió la FK
  }

  // FALLBACK POR NAVEGABILIDAD
  if (!$fk_asignada) { // Modificacion 15/12/25 v0.0.2 lo hice por que si no se resolvió por cardinalidad, uso flechas
      if ($nav === 'o2d') {
          // Origen -> Destino: Destino lleva la FK
          $add_fk($clases[$idx_d], $clases[$idx_o]); // Modificacion 15/12/25 v0.0.2 lo hice por que nav o2d manda FK a Destino
      } elseif ($nav === 'd2o') {
          // Destino -> Origen: Origen lleva la FK
          $add_fk($clases[$idx_o], $clases[$idx_d]); // Modificacion 15/12/25 v0.0.2 lo hice por que nav d2o manda FK a Origen
      }
      // Si es 'none' o falló todo, no se hace nada (aisladas en BD)
  }
}

// ---- Textos de salida ----------------------------------------------------
// (El resto del archivo generador de Java/Flutter/SQL sigue igual, ya que usa el array $clases que acabamos de modificar)
function pom_xml($pkg){
return <<<XML
<project xmlns="http://maven.apache.org/POM/4.0.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://maven.apache.org/POM/4.0.0 https://maven.apache.org/xsd/maven-4.0.0.xsd">
  <modelVersion>4.0.0</modelVersion>
  <groupId>{$pkg}</groupId><artifactId>backend</artifactId><version>0.0.1</version>
  <properties><java.version>17</java.version><spring.boot.version>3.3.3</spring.boot.version></properties>
  <dependencyManagement>
    <dependencies>
      <dependency>
        <groupId>org.springframework.boot</groupId><artifactId>spring-boot-dependencies</artifactId>
        <version>\${spring.boot.version}</version><type>pom</type><scope>import</scope>
      </dependency>
    </dependencies>
  </dependencyManagement>
  <dependencies>
    <dependency><groupId>org.springframework.boot</groupId><artifactId>spring-boot-starter-web</artifactId></dependency>
    <dependency><groupId>org.springframework.boot</groupId><artifactId>spring-boot-starter-data-jpa</artifactId></dependency>
    <dependency><groupId>org.springframework.boot</groupId><artifactId>spring-boot-starter-validation</artifactId></dependency>
    <dependency><groupId>org.postgresql</groupId><artifactId>postgresql</artifactId><scope>runtime</scope></dependency>
  </dependencies>
  <build>
    <plugins>
      <plugin><groupId>org.springframework.boot</groupId><artifactId>spring-boot-maven-plugin</artifactId></plugin>
    </plugins>
  </build>
</project>
XML;
}
function application_props($db){
return <<<TXT
server.port=8080
spring.datasource.url=jdbc:postgresql://localhost:5432/{$db}
spring.datasource.username=postgres
spring.datasource.password=postgres
spring.jpa.hibernate.ddl-auto=update
spring.jpa.properties.hibernate.jdbc.time_zone=UTC
spring.jackson.serialization.WRITE_DATES_AS_TIMESTAMPS=false
# CORS simple para dev:
app.cors.origins=*
TXT;
}
function main_java($pkg,$app){
$pkg2 = str_replace('.','/',$pkg);
return <<<JAVA
package {$pkg};

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.context.annotation.Bean;
import org.springframework.web.servlet.config.annotation.CorsRegistry;
import org.springframework.web.servlet.config.annotation.WebMvcConfigurer;

@SpringBootApplication
public class {$app}Application {
  public static void main(String[] args){ SpringApplication.run({$app}Application.class, args);
}

  @Bean WebMvcConfigurer corsConfigurer(){
    return new WebMvcConfigurer(){
      @Override public void addCorsMappings(CorsRegistry r){
        r.addMapping("/api/").allowedMethods("GET","POST","PUT","DELETE").allowedOrigins("*");
}
    };
  }
}
JAVA;
}
function entidad_java($pkg,$clase){
  $n = $clase['name'];
  $campos = [];
  $imps = ["jakarta.persistence.","jakarta.validation.constraints."];
foreach($clase['attrs'] as $a){
    // FIX: usar el tipo Java ya resuelto en el parser, no re-deducir desde SQL
    $tj = $a['java'];
$anot = [];
    if ($a['pk']) { $anot[]='@Id'; $anot[]=$a['ai']? '@GeneratedValue(strategy = GenerationType.IDENTITY)' : ''; }
    if (!$a['nullable']) $anot[]='@NotNull';
if (!$a['pk']) $anot[]='@Column(nullable=' . ($a['nullable']?'true':'false') . ($a['unique']?', unique=true':'') . ')';
    $anot = array_filter($anot);
$campos[] = "  ".implode(" ",$anot)."\n  private ".$tj." ".$a['campo'].";";
  }
  $campos_txt = implode("\n\n",$campos);
  $pkg2 = $pkg;
// FIX: imports con líneas completas
  $imports = array_unique($imps);
  $imports_txt = implode("\n", array_map(fn($i)=>"import ".$i.";", $imports));

  return <<<JAVA
package {$pkg2}.entidad;
{$imports_txt}

@Entity
@Table(name="{$n}")
public class {$n} {
{$campos_txt}

  public {$n}(){}

  // getters/setters
JAVA;
}
function entidad_java_cierre(){ return "\n}\n"; }

function repo_java($pkg,$n){
return <<<JAVA
package {$pkg}.repo;
import org.springframework.data.jpa.repository.JpaRepository;
import {$pkg}.entidad.{$n};
public interface {$n}Repo extends JpaRepository<{$n}, Long> {}
JAVA;
}
function ctrl_java($pkg,$n){
return <<<JAVA
package {$pkg}.controller;

import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;
import org.springframework.validation.annotation.Validated;
import java.util.*;
import {$pkg}.entidad.{$n};
import {$pkg}.repo.{$n}Repo;

@RestController
@RequestMapping("/api/{$n}")
@Validated
public class {$n}Controller {
  private final {$n}Repo repo;
  public {$n}Controller({$n}Repo r){ this.repo=r;
}

  @GetMapping public List<{$n}> listar(){ return repo.findAll(); }
  @GetMapping("{id}") public ResponseEntity<{$n}> ver(@PathVariable Long id){
    return repo.findById(id).map(ResponseEntity::ok).orElse(ResponseEntity.notFound().build());
}
  @PostMapping public {$n} crear(@RequestBody {$n} x){ return repo.save(x);
}
  @PutMapping("{id}") public ResponseEntity<{$n}> actualizar(@PathVariable Long id, @RequestBody {$n} x){
    return repo.findById(id).map(y->{ x.setId(id); return ResponseEntity.ok(repo.save(x)); })
      .orElse(ResponseEntity.notFound().build());
}
  @DeleteMapping("{id}") public ResponseEntity<Void> borrar(@PathVariable Long id){
    if(!repo.existsById(id)) return ResponseEntity.notFound().build();
    repo.deleteById(id); return ResponseEntity.noContent().build();
  }
}
JAVA;
}
function flutter_pubspec(){
return <<<YML
name: front_flutter
description: Frontend generado por Drawsito (buitre)
environment:
  sdk: ">=3.3.0 <4.0.0"
dependencies:
  flutter:
    sdk: flutter
  http: ^1.2.2
  flutter_hooks: ^0.20.5
dev_dependencies:
  flutter_test:
    sdk: flutter
flutter:
  uses-material-design: true
YML;
}
function flutter_api_dart(){
return <<<DART
import 'dart:convert';
import 'package:http/http.dart' as http;

class Api {
  static String base = const String.fromEnvironment('API_BASE', defaultValue: 'http://localhost:8080');
static Future<List<dynamic>> listar(String entidad) async {
    final r = await http.get(Uri.parse('\$base/api/\$entidad'));
    return jsonDecode(r.body) as List;
}
  static Future<Map<String,dynamic>> crear(String entidad, Map<String,dynamic> data) async {
    final r = await http.post(Uri.parse('\$base/api/\$entidad'), headers:{'Content-Type':'application/json'}, body: jsonEncode(data));
return jsonDecode(r.body);
  }
  static Future<Map<String,dynamic>?> ver(String entidad, int id) async {
    final r = await http.get(Uri.parse('\$base/api/\$entidad/\$id'));
if (r.statusCode==200) return jsonDecode(r.body);
    return null;
  }
  static Future<Map<String,dynamic>> actualizar(String entidad, int id, Map<String,dynamic> data) async {
    final r = await http.put(Uri.parse('\$base/api/\$entidad/\$id'), headers:{'Content-Type':'application/json'}, body: jsonEncode(data));
return jsonDecode(r.body);
  }
  static Future<bool> borrar(String entidad, int id) async {
    final r = await http.delete(Uri.parse('\$base/api/\$entidad/\$id'));
return r.statusCode==204;
  }
}
DART;
}

// FIX: interpolación en PHP -> Dart (sin $\{...\}) y lista con ruta visible
function flutter_main_dart($clases){
  $items = [];
foreach ($clases as $c) {
    $ent = $c['name'];
    $items[] = "MenuItem('{$ent}', '/".strtolower($ent)."', '{$ent}')";
}
  $items_txt = implode(",\n        ", $items);
  return <<<DART
import 'package:flutter/material.dart';
import 'api.dart';
void main() => runApp(const App());
class App extends StatelessWidget {
  const App({super.key});
@override Widget build(BuildContext context){
    return MaterialApp(
      title: 'Front Flutter',
      theme: ThemeData(useMaterial3: true),
      home: const Menu(),
    );
}
}
class MenuItem{ final String entidad; final String ruta; final String nombre; MenuItem(this.entidad,this.ruta,this.nombre); }
class Menu extends StatelessWidget{
  const Menu({super.key});
@override Widget build(BuildContext ctx){
    final items = <MenuItem>[
        $items_txt
    ];
return Scaffold(
      appBar: AppBar(title: const Text('Drawsito • Front')),
      body: ListView.builder(
        itemCount: items.length,
        itemBuilder: (_,i){
          final it=items[i];
          return ListTile(
            title: Text(it.nombre),
            subtitle: Text(it.ruta),
            trailing: const Icon(Icons.chevron_right),
 
           onTap: ()=> Navigator.of(ctx).push(MaterialPageRoute(
              builder: (_)=> CrudPage(entidad: it.entidad),
            )),
          );
        },
      ),
    );
}
}
class CrudPage extends StatefulWidget{
  final String entidad;
  const CrudPage({super.key, required this.entidad});
  @override State<CrudPage> createState()=> _CrudPageState();
}
class _CrudPageState extends State<CrudPage>{
  List<dynamic> data=[];
  final Map<String,dynamic> form={};
  @override void initState(){ super.initState(); _load();
}
  Future<void> _load() async{
    data = await Api.listar(widget.entidad);
    if (mounted) setState((){});
}
  Future<void> _crear() async{
    await Api.crear(widget.entidad, form);
    if (mounted) _load();
}
  Future<void> _borrar(int id) async{
    await Api.borrar(widget.entidad, id);
    if (mounted) _load();
}
  @override Widget build(BuildContext ctx){
    return Scaffold(
      appBar: AppBar(title: Text('CRUD • '+widget.entidad)),
      floatingActionButton: FloatingActionButton(
        onPressed: _crear, child: const Icon(Icons.add),
      ),
      body: ListView.builder(
        itemCount: data.length,
        itemBuilder: (_,i){
          final d = (data[i] as Map?)?.cast<String,dynamic>() ?? <String,dynamic>{};
          final id = 
(d['id'] as num?)?.toInt() ?? 0;
          return ListTile(
            title: Text(d.toString()),
            trailing: IconButton(icon: const Icon(Icons.delete), onPressed: ()=>_borrar(id)),
          );
        },
      ),
    );
}
}
DART;
}

// SQL
$create_db_sql = "CREATE DATABASE \"{$nombre_db}\" WITH ENCODING='UTF8' TEMPLATE template1;";
$schema = [];
foreach($clases as $c){
  $tn = $c['name'];
  $cols = [];
  $pks = [];
  $fks = [];
foreach($c['attrs'] as $a){
    $col = "\"{$a['campo']}\" ". ($a['pk'] && $a['ai'] ? 'bigserial' : $a['sql']) .
($a['nullable']?'':' NOT NULL');
    if ($a['unique']) $col .= ' UNIQUE';
    $cols[] = $col;
    if ($a['pk']) $pks[] = "\"{$a['campo']}\"";
if ($a['fk_de']){
      $ref = $a['fk_de'];
      $fks[] = "FOREIGN KEY (\"{$a['campo']}\") REFERENCES \"{$ref}\"(\"id\") ON DELETE RESTRICT";
}
  }
  if ($pks) $cols[] = "PRIMARY KEY (".implode(',',$pks).")";
  foreach($fks as $fk) $cols[] = $fk;
$schema[] = "CREATE TABLE \"{$tn}\" (\n  ".implode(",\n  ", $cols)."\n);";
}
$schema_sql = implode("\n\n", $schema)."\n";
// ---- Ensamblar ZIP -------------------------------------------------------
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR."proyecto_{$nombre_db}_".substr(md5(mt_rand()),0,6).".zip";
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE|ZipArchive::OVERWRITE)!==TRUE){
  http_response_code(500);
echo "No se pudo crear ZIP"; exit;
}

$zip->addFromString("README.txt", <<<TXT
PROYECTO GENERADO — Drawsito (regla buitre)

1) Base de datos (PostgreSQL)
  - Ejecutar db/create_db.sql y luego db/schema.sql (pgAdmin o psql).
- Los controladores esperan credenciales por defecto: usuario=postgres, pass=postgres, host=localhost:5432
  - Cambia src/main/resources/application.properties si usas otros datos.
2) Backend (Spring Boot)
  cd backend
  mvn spring-boot:run
  Probar: GET http://localhost:8080/api/NombreEntidad

3) Frontend (Flutter)
  cd front_flutter
  flutter create .
flutter pub get
  flutter run -d chrome
   * Si quieres Android: conecta tu teléfono con depuración USB y usa 'flutter run'.
Nota: el generador asigna varchar(255) si no especificas tipo. Para tipar:
  - nombre:string len(80)
  - monto:decimal(10,2)
  - activo:boolean
  - fecha:date
  - creado:timestamp
  - id:int pk ai
  - id_usuario:int fk(Usuario)

Relaciones:
  - Si dibujas A(1) — (*)B, se añade id_A en B (FK) automáticamente.
  - Modificacion 15/12/25 v0.0.2 lo hice por que ahora soporta tablas intermedias automáticas para M:N.
TXT);

$zip->addFromString("db/create_db.sql", $create_db_sql);
$zip->addFromString("db/schema.sql", $schema_sql);

// backend
$zip->addFromString("backend/pom.xml", pom_xml($pkg));
$zip->addFromString("backend/src/main/resources/application.properties", application_props($nombre_db));
$zip->addFromString("backend/src/main/java/".str_replace('.','/',$pkg)."/".$nombre_app."Application.java", main_java($pkg,$nombre_app));

foreach($clases as $c){
  $n = $c['name'];
// entidad
  $ent_ini = entidad_java($pkg,$c);
  // getters/setters (FIX: usar $a['java'])
  $gs = [];
foreach($c['attrs'] as $a){
    $tj = $a['java'];
    $nm = $a['campo'];
    $Nm = pascal($nm);
$gs[] = "  public {$tj} get{$Nm}(){ return this.{$nm}; }\n  public void set{$Nm}({$tj} v){ this.{$nm}=v; }";
}
  $ent = $ent_ini . "\n" . implode("\n\n",$gs) . entidad_java_cierre();
  $zip->addFromString("backend/src/main/java/".str_replace('.','/',$pkg)."/entidad/{$n}.java", $ent);

  // repo
  $zip->addFromString("backend/src/main/java/".str_replace('.','/',$pkg)."/repo/{$n}Repo.java", repo_java($pkg,$n));
// controller
  $zip->addFromString("backend/src/main/java/".str_replace('.','/',$pkg)."/controller/{$n}Controller.java", ctrl_java($pkg,$n));
}

// flutter
$zip->addFromString("front_flutter/pubspec.yaml", flutter_pubspec());
$zip->addFromString("front_flutter/lib/api.dart", flutter_api_dart());
$zip->addFromString("front_flutter/lib/main.dart", flutter_main_dart($clases));

$zip->close();

$fn = basename($id ?: $nombre_db)."_proyecto.zip";
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.$fn.'"');
header('Content-Length: '.filesize($tmp));
readfile($tmp);
@unlink($tmp);