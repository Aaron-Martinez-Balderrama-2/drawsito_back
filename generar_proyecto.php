<?php
// proyecto/drawsito_back/generar_proyecto.php
// Generador v3.5 (STRICT CRUD): Base v3.0 + Edición + Dropdowns Simples + Info Completa.

@ini_set('display_errors','0');
date_default_timezone_set('UTC');

require_once __DIR__.'/config.php';

if (defined('PERSISTENCIA') && PERSISTENCIA === 'pg') { require_once __DIR__.'/db.php'; }
if (!class_exists('ZipArchive')) { http_response_code(500); echo "Error: php_zip no instalado."; exit; }

$dir_base = __DIR__;
$dir_diag = $dir_base . DIRECTORY_SEPARATOR . 'diagramas';

function limpiar($s){ return preg_replace('/[^a-zA-Z0-9_]+/','_', trim($s)); }
function pascal($s){ $a = preg_split('/[_\s]+/', $s); $a = array_map(fn($x)=>ucfirst(strtolower($x)), $a); return implode('',$a); }
function esMuchos($card){ return $card && (strpos($card,'*')!==false); }
function esUno($card){ return $card && (strpos($card,'1')!==false) && !esMuchos($card); }

function tipo_sql($t){
  $t=strtolower(trim($t));
  if ($t===''||$t==='string'||$t==='varchar') return 'varchar(255)';
  if (preg_match('varchar\(\d+\)',$t)) return $t;
  if ($t==='int'||$t==='integer') return 'integer';
  if ($t==='bigint'||$t==='long') return 'bigint';
  if ($t==='bool'||$t==='boolean') return 'boolean';
  if ($t==='date'||strpos($t,'time')!==false) return 'timestamp';
  if ($t==='decimal'||strpos($t,'decimal')!==false) return 'decimal(10,2)';
  return 'varchar(255)';
}

function tipo_java($t){
  $t=strtolower(trim($t));
  if (strpos($t,'int')!==false) return 'Integer';
  if (strpos($t,'long')!==false||strpos($t,'bigint')!==false) return 'Long';
  if (strpos($t,'bool')!==false) return 'Boolean';
  if (strpos($t,'date')!==false) return 'java.time.LocalDate';
  if (strpos($t,'time')!==false) return 'java.time.LocalDateTime';
  if (strpos($t,'dec')!==false) return 'java.math.BigDecimal';
  return 'String';
}

function tipo_dart($javaType){
  if ($javaType==='Integer' || $javaType==='Long' || $javaType==='Short') return 'int';
  if ($javaType==='Boolean') return 'bool';
  if ($javaType==='java.math.BigDecimal') return 'double';
  return 'String';
}

$id = preg_replace('~[^a-zA-Z0-9\-_]~','', $_GET['id'] ?? '');
$json_in = $_POST['json'] ?? '';

if ($id===''){ if ($json_in===''){ http_response_code(400); echo "Falta ID"; exit; } $doc = json_decode($json_in, true); } 
else {
  if (defined('PERSISTENCIA') && PERSISTENCIA === 'pg') {
    $pdo = db_pg(); $s = $pdo->prepare('SELECT json FROM diagramas WHERE id = :id'); $s->execute([':id' => $id]);
    $json = $s->fetchColumn(); if (!$json){ http_response_code(404); echo "No existe"; exit; } $doc = json_decode($json, true);
  } else {
    $ruta = $dir_diag . DIRECTORY_SEPARATOR . $id . '.json';
    if (!is_file($ruta)){ http_response_code(404); echo "No existe"; exit; } $doc = json_decode(file_get_contents($ruta), true);
  }
}
if (!is_array($doc)){ http_response_code(400); echo "JSON inválido"; exit; }

$nombre_db = limpiar($id ?: ('dibujo_'.date('Ymd_His')));
$pkg = 'com.auto.'.strtolower($nombre_db);
$nombre_app = pascal($nombre_db);

$por_id = []; $clases = []; $id2idx = [];

foreach(($doc['nodos']??[]) as $n){
  if (($n['tipo']??'')!=='clase') continue;
  $name = pascal($n['titulo'] ?? 'Clase');
  $atr_lines = $n['atributos'] ?? [];
  $attrs = []; $tieneId = false;

  foreach($atr_lines as $linea){
    $s=trim($linea); if ($s==='') continue;
    if ($s[0]=='+'||$s[0]=='#'||$s[0]=='-') { $s=trim(substr($s,1)); }
    $part = explode(':',$s,2); $campo = limpiar($part[0]);
    $rest = $part[1] ?? 'string'; $trozos = preg_split('/\s+/', trim($rest));
    $tipo = array_shift($trozos); $mods = array_map('strtolower',$trozos);
    $sql = tipo_sql($tipo); $java = tipo_java($tipo); $dart = tipo_dart($java);
    $pk = in_array('pk',$mods); $ai = in_array('ai',$mods);
    $unique = in_array('unique',$mods); $nullable = in_array('null',$mods) || in_array('nullable',$mods);
    $fk_de = null;
    foreach($mods as $m){ if (preg_match('fk\(([^)]+)\)',$m,$mm)) $fk_de = pascal($mm[1]); }
    if ($campo==='id'){ $pk=true; $tieneId=true; $ai=true; if ($sql==='integer') $sql='bigint'; $java='Long'; $dart='int'; }
    $attrs[] = compact('campo','sql','java','dart','pk','ai','unique','nullable','fk_de');
  }
  if (!$tieneId){ $attrs = array_merge([['campo'=>'id','sql'=>'bigint','java'=>'Long','dart'=>'int','pk'=>true,'ai'=>true,'unique'=>false,'nullable'=>false,'fk_de'=>null]], $attrs); }
  $clase = ['name'=>$name,'attrs'=>$attrs,'id'=>$n['id']];
  $id_actual = $n['id']; $clases[] = $clase; $por_id[$id_actual] = $clase; $id2idx[$id_actual] = count($clases)-1;
}

$add_fk = function(array &$tabla, array $ref, array $options = []) {
  $base = 'id_'.strtolower($ref['name']); $fk_name = $base; $c = 2;
  while(true){ $existe = false; foreach($tabla['attrs'] as $x){ if($x['campo'] === $fk_name) { $existe = true; break; } } if (!$existe) break; $fk_name = $base . '_' . $c; $c++; }
  $onDelete = $options['on_delete'] ?? 'RESTRICT'; $isNullable = $options['nullable'] ?? false;
  $tabla['attrs'][] = ['campo' => $fk_name, 'sql' => 'bigint', 'java' => 'Long', 'dart' => 'int', 'pk' => false, 'ai' => false, 'unique'=> false, 'nullable' => $isNullable, 'fk_de' => $ref['name'], 'on_delete' => $onDelete];
};

foreach(($doc['aristas']??[]) as $a){
  $idx_o = $id2idx[$a['origenId']] ?? null; $idx_d = $id2idx[$a['destinoId']] ?? null;
  if ($idx_o===null || $idx_d===null) continue;
  $co = trim($a['card_o'] ?? ''); $cd = trim($a['card_d'] ?? ''); $nav = $a['nav'] ?? 'o2d'; $uml = $a['uml'] ?? 'assoc';
  $optsUML = ['on_delete' => 'RESTRICT', 'nullable' => false];
  if ($uml === 'comp') { $optsUML['on_delete'] = 'CASCADE'; $optsUML['nullable'] = false; }
  elseif ($uml === 'agg') { $optsUML['on_delete'] = 'SET NULL'; $optsUML['nullable'] = true; }
  if ((esMuchos($co) && esMuchos($cd)) || ($nav === 'both')) {
      $claseO = $clases[$idx_o]; $claseD = $clases[$idx_d]; $nombreDetalle = 'Detalle' . $claseO['name'] . $claseD['name'];
      $claseDetalle = [ 'name' => $nombreDetalle, 'id' => 'virtual_'.$a['id'], 'attrs' => [['campo'=>'id','sql'=>'bigint','java'=>'Long','dart'=>'int','pk'=>true,'ai'=>true,'unique'=>false,'nullable'=>false,'fk_de'=>null]] ];
      $claseDetalle['attrs'][] = ['campo'=>'id_'.strtolower($claseO['name']), 'sql'=>'bigint','java'=>'Long','dart'=>'int','pk'=>false,'ai'=>false,'unique'=>false,'nullable'=>false,'fk_de'=>$claseO['name'],'on_delete'=>'CASCADE'];
      $fkD = 'id_'.strtolower($claseD['name']); if($fkD == 'id_'.strtolower($claseO['name'])) $fkD.='_2';
      $claseDetalle['attrs'][] = ['campo'=>$fkD, 'sql'=>'bigint','java'=>'Long','dart'=>'int','pk'=>false,'ai'=>false,'unique'=>false,'nullable'=>false,'fk_de'=>$claseD['name'],'on_delete'=>'CASCADE'];
      $clases[] = $claseDetalle; continue; 
  }
  $fk_asignada = false;
  if (esUno($co) && esMuchos($cd)){ $add_fk($clases[$idx_d], $clases[$idx_o], $optsUML); $fk_asignada = true; }
  else if (esMuchos($co) && esUno($cd)){ $add_fk($clases[$idx_o], $clases[$idx_d], $optsUML); $fk_asignada = true; }
  if (!$fk_asignada) {
      if ($nav === 'o2d') { $add_fk($clases[$idx_d], $clases[$idx_o], $optsUML); }
      elseif ($nav === 'd2o') { $add_fk($clases[$idx_o], $clases[$idx_d], $optsUML); }
  }
}

function flutter_main_dart($clases){
  $menuItems = []; foreach ($clases as $c) { $ent = $c['name']; $menuItems[] = "MenuItem('{$ent}', Icons.dataset_linked, '{$ent}')"; }
  $menuItemsTxt = implode(",\n    ", $menuItems);
  $routerCases = ""; foreach ($clases as $c) { $n = $c['name']; $routerCases .= "      case '$n': return const Page$n();\n"; }

  // Detectar campo "nombre" para mostrar en Dropdowns
  $displayFields = []; 
  foreach($clases as $c){
      $disp = 'id';
      foreach($c['attrs'] as $a){
          if($a['pk']) continue;
          if($a['dart'] === 'String'){
              $disp = $a['campo']; break; // Primer string encontrado
          }
      }
      $displayFields[$c['name']] = $disp;
  }

  $crudPages = "";
  foreach ($clases as $c) {
    $entityName = $c['name']; $urlSlug = strtolower($entityName);
    
    $varsDecl = []; $initVars = []; $formFields = []; $toJson = [];
    $fkLoaders = []; 
    
    foreach ($c['attrs'] as $attr) {
        if ($attr['pk']) continue;
        
        $campo = $attr['campo']; $tipo = $attr['dart']; $label = ucfirst($campo);

        if ($attr['fk_de']) {
            $ref = $attr['fk_de'];
            $refSlug = strtolower($ref);
            $disp = $displayFields[$ref] ?? 'id';
            
            $varsDecl[] = "List<dynamic> list_$campo = [];";
            $varsDecl[] = "int? val_$campo;";
            $fkLoaders[] = "list_$campo = await Api.listar('$refSlug');";
            $initVars[] = "val_$campo = item?['$campo'];";
            
            $formFields[] = "DropdownButtonFormField<int>(
                value: val_$campo,
                decoration: const InputDecoration(labelText: '$label', border: OutlineInputBorder()),
                items: list_$campo.map((e) => DropdownMenuItem<int>(value: e['id'], child: Text('\${e['$disp']} (ID:\${e['id']})'))).toList(),
                onChanged: (v) => setState(() => val_$campo = v),
            )";
            $toJson[] = "'$campo': val_$campo";
        } else {
            if ($tipo === 'bool') {
                $varsDecl[] = "bool val_$campo = false;";
                $initVars[] = "val_$campo = item?['$campo'] ?? false;";
                $formFields[] = "SwitchListTile(title: const Text('$label'), value: val_$campo, onChanged: (v)=> setState(()=> val_$campo=v))";
                $toJson[] = "'$campo': val_$campo";
            } else {
                $varsDecl[] = "final TextEditingController c_$campo = TextEditingController();";
                $initVars[] = "c_$campo.text = item?['$campo']?.toString() ?? '';";
                
                $ktype = ($tipo=='int'||$tipo=='double') ? 'TextInputType.number' : 'TextInputType.text';
                $formFields[] = "TextFormField(controller: c_$campo, decoration: const InputDecoration(labelText: '$label', border: OutlineInputBorder()), keyboardType: $ktype)";
                
                if($tipo=='int') $toJson[] = "'$campo': int.tryParse(c_$campo.text) ?? 0";
                elseif($tipo=='double') $toJson[] = "'$campo': double.tryParse(c_$campo.text) ?? 0.0";
                else $toJson[] = "'$campo': c_$campo.text";
            }
        }
    }
    
    $varsCode = implode("\n  ", $varsDecl);
    $initCode = implode("\n    ", $initVars);
    $fkCode = implode("\n    ", $fkLoaders);
    $fieldsCode = implode(",\n              const SizedBox(height: 12),\n              ", $formFields);
    $jsonCode = implode(", ", $toJson);
    
    // Generar vista de info completa para la Card
    $cardContent = [];
    foreach($c['attrs'] as $a){
        $f = $a['campo'];
        $cardContent[] = "Text('$f: \${d['$f']}')";
    }
    $cardInfo = implode(",\n                      ", $cardContent);

    $crudPages .= <<<DART
class Page$entityName extends StatefulWidget {
  const Page$entityName({super.key});
  @override State<Page$entityName> createState() => _State$entityName();
}
class _State$entityName extends State<Page$entityName> {
  List<dynamic> data = [];
  bool loading = true;
  $varsCode

  @override void initState() { super.initState(); _load(); }

  Future<void> _load() async {
    setState(() => loading = true);
    try {
      data = await Api.listar('$urlSlug');
      $fkCode
    } catch (e) { ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error: \$e'))); }
    setState(() => loading = false);
  }

  Future<void> _guardar(int? id) async {
    final payload = { $jsonCode };
    try {
      if(id == null) await Api.crear('$urlSlug', payload);
      else await Api.actualizar('$urlSlug', id, payload);
      if(mounted) { Navigator.pop(context); _load(); }
    } catch (e) { ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error: \$e'), backgroundColor: Colors.red)); }
  }

  Future<void> _borrar(int id) async { await Api.borrar('$urlSlug', id); _load(); }

  void _mostrarFormulario({Map<String, dynamic>? item}) {
    $initCode
    showDialog( context: context, builder: (_) => AlertDialog(
      title: Text(item==null ? 'Nuevo $entityName' : 'Editar $entityName'),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [ $fieldsCode ])),
      actions: [
        TextButton(onPressed: ()=>Navigator.pop(context), child: const Text('Cancelar')),
        FilledButton(onPressed: ()=>_guardar(item?['id']), child: const Text('Guardar')),
      ],
    ));
  }

  @override Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('$entityName'), centerTitle: true),
      floatingActionButton: FloatingActionButton(onPressed: ()=>_mostrarFormulario(), child: const Icon(Icons.add)),
      body: loading ? const Center(child: CircularProgressIndicator()) : ListView.builder(
        padding: const EdgeInsets.all(10),
        itemCount: data.length,
        itemBuilder: (_, i) {
          final d = data[i];
          return Card(
            margin: const EdgeInsets.symmetric(vertical: 5),
            child: InkWell(
              onTap: () => _mostrarFormulario(item: d),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(
                  children: [
                    Expanded(child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        $cardInfo
                      ],
                    )),
                    IconButton(icon: const Icon(Icons.delete, color: Colors.red), onPressed: ()=>_borrar(d['id'])),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}
DART;
  }

  return <<<DART
import 'package:flutter/material.dart';
import 'api.dart';
void main() => runApp(const App());
class App extends StatelessWidget {
  const App({super.key});
  @override Widget build(BuildContext context) { return MaterialApp( debugShowCheckedModeBanner: false, theme: ThemeData(useMaterial3: true, colorScheme: ColorScheme.fromSeed(seedColor: Colors.indigo)), home: const Menu()); }
}
class MenuItem { final String titulo; final IconData icon; final String page; MenuItem(this.titulo, this.icon, this.page); }
class Menu extends StatelessWidget {
  const Menu({super.key});
  @override Widget build(BuildContext context) {
    final items = [ $menuItemsTxt ];
    return Scaffold( appBar: AppBar(title: const Text('Panel Principal')), body: GridView.builder( padding: const EdgeInsets.all(16), gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount( crossAxisCount: 2, crossAxisSpacing: 16, childAspectRatio: 1.3 ), itemCount: items.length, itemBuilder: (_, i) => Card( elevation: 4, child: InkWell( onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => _getPage(items[i].titulo))), child: Column( mainAxisAlignment: MainAxisAlignment.center, children: [ Icon(items[i].icon, size: 40, color: Colors.indigo), const SizedBox(height: 10), Text(items[i].titulo, style: const TextStyle(fontWeight: FontWeight.bold)) ] ) ) ) ) );
  }
  Widget _getPage(String name) { switch (name) { $routerCases default: return const Scaffold(body: Center(child: Text('404'))); } }
}
$crudPages
DART;
}

function pom_xml($pkg){ return <<<XML
<project xmlns="http://maven.apache.org/POM/4.0.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://maven.apache.org/POM/4.0.0 https://maven.apache.org/xsd/maven-4.0.0.xsd">
  <modelVersion>4.0.0</modelVersion>
  <parent><groupId>org.springframework.boot</groupId><artifactId>spring-boot-starter-parent</artifactId><version>3.3.3</version><relativePath/></parent>
  <groupId>{$pkg}</groupId><artifactId>backend</artifactId><version>0.0.1</version>
  <properties><java.version>17</java.version></properties>
  <dependencies>
    <dependency><groupId>org.springframework.boot</groupId><artifactId>spring-boot-starter-web</artifactId></dependency>
    <dependency><groupId>org.springframework.boot</groupId><artifactId>spring-boot-starter-data-jpa</artifactId></dependency>
    <dependency><groupId>org.springframework.boot</groupId><artifactId>spring-boot-starter-validation</artifactId></dependency>
    <dependency><groupId>org.postgresql</groupId><artifactId>postgresql</artifactId><scope>runtime</scope></dependency>
    <dependency><groupId>org.springdoc</groupId><artifactId>springdoc-openapi-starter-webmvc-ui</artifactId><version>2.2.0</version></dependency>
  </dependencies>
  <build><plugins><plugin><groupId>org.springframework.boot</groupId><artifactId>spring-boot-maven-plugin</artifactId></plugin></plugins></build>
</project>
XML; }

function flutter_pubspec(){ return "name: front_flutter\ndescription: Generado por Drawsito\nenvironment:\n  sdk: \">=3.3.0 <4.0.0\"\ndependencies:\n  flutter:\n    sdk: flutter\n  http: ^1.2.2\nflutter:\n  uses-material-design: true"; }
function application_props($db){ return "server.port=8080\nspring.datasource.url=jdbc:postgresql://localhost:5432/{$db}\nspring.datasource.username=postgres\nspring.datasource.password=postgres\nspring.jpa.hibernate.ddl-auto=update\napp.cors.origins=*"; }
function main_java($pkg,$app){ return "package {$pkg};\nimport org.springframework.boot.SpringApplication;\nimport org.springframework.boot.autoconfigure.SpringBootApplication;\nimport org.springframework.context.annotation.Bean;\nimport org.springframework.web.servlet.config.annotation.CorsRegistry;\nimport org.springframework.web.servlet.config.annotation.WebMvcConfigurer;\n@SpringBootApplication\npublic class {$app}Application {\n  public static void main(String[] args){ SpringApplication.run({$app}Application.class, args); }\n  @Bean WebMvcConfigurer corsConfigurer(){ return new WebMvcConfigurer(){ @Override public void addCorsMappings(CorsRegistry r){ r.addMapping(\"/**\").allowedMethods(\"*\").allowedOrigins(\"*\"); } }; }\n}"; }

// BACKEND V3.0 EXACTO (NO TOCAR)
function entidad_java($pkg,$clase){
  $n = $clase['name']; $campos = []; $imps = ["jakarta.persistence.*","jakarta.validation.constraints.*","com.fasterxml.jackson.annotation.JsonProperty"];
  foreach($clase['attrs'] as $a){
    $tj = $a['java']; $anot = [];
    if ($a['pk']) { $anot[]='@Id'; $anot[]=$a['ai']? '@GeneratedValue(strategy = GenerationType.IDENTITY)' : ''; }
    if (!$a['nullable'] && !$a['pk']) $anot[]='@NotNull';
    if (!$a['pk']) { $props=[]; if($a['nullable'])$props[]='nullable=true'; else $props[]='nullable=false'; if($a['unique'])$props[]='unique=true'; $anot[]='@Column('.implode(', ',$props).')'; }
    $campos[] = "  @JsonProperty(\"{$a['campo']}\")\n  ".implode(" ",array_filter($anot))."\n  private ".$tj." ".$a['campo'].";";
  }
  return "package {$pkg}.entidad;\n".implode("\n", array_map(fn($i)=>"import $i;", $imps))."\n\n@Entity\n@Table(name=\"\\\"{$n}\\\"\")\npublic class {$n} {\n".implode("\n\n",$campos)."\n  public {$n}(){}\n  // getters/setters auto-gen\n";
}
function entidad_java_cierre(){ return "\n}\n"; }
function repo_java($pkg,$n){ return "package {$pkg}.repo;\nimport org.springframework.data.jpa.repository.JpaRepository;\nimport {$pkg}.entidad.{$n};\npublic interface {$n}Repo extends JpaRepository<{$n}, Long> {}"; }
function ctrl_java($pkg,$n){ $ruta_api = strtolower($n); return "package {$pkg}.controller;\nimport org.springframework.http.ResponseEntity;\nimport org.springframework.web.bind.annotation.*;\nimport java.util.*;\nimport {$pkg}.entidad.{$n};\nimport {$pkg}.repo.{$n}Repo;\n@RestController\n@RequestMapping(\"/api/{$ruta_api}\")\npublic class {$n}Controller {\n  private final {$n}Repo repo;\n  public {$n}Controller({$n}Repo r){ this.repo=r; }\n  @GetMapping public List<{$n}> listar(){ return repo.findAll(); }\n  @GetMapping(\"{id}\") public ResponseEntity<{$n}> ver(@PathVariable Long id){ return repo.findById(id).map(ResponseEntity::ok).orElse(ResponseEntity.notFound().build()); }\n  @PostMapping public {$n} crear(@RequestBody {$n} x){ return repo.save(x); }\n  @PutMapping(\"{id}\") public ResponseEntity<{$n}> actualizar(@PathVariable Long id, @RequestBody {$n} x){ return repo.findById(id).map(y->{ x.setId(id); return ResponseEntity.ok(repo.save(x)); }).orElse(ResponseEntity.notFound().build()); }\n  @DeleteMapping(\"{id}\") public ResponseEntity<Void> borrar(@PathVariable Long id){ if(!repo.existsById(id)) return ResponseEntity.notFound().build(); repo.deleteById(id); return ResponseEntity.noContent().build(); }\n}"; }

// API UPDATED with PUT
function flutter_api_dart(){ 
    $mi_ip = "192.168.0.9"; 
    return "import 'dart:convert'; import 'package:http/http.dart' as http; class Api { static String base = const String.fromEnvironment('API_BASE', defaultValue: 'http://{$mi_ip}:8080'); static Future<List> listar(String e) async { final r = await http.get(Uri.parse('\$base/api/\$e')); if(r.statusCode!=200) return []; return jsonDecode(r.body); } static Future crear(String e, Map d) async { final r = await http.post(Uri.parse('\$base/api/\$e'), headers:{'Content-Type':'application/json'}, body: jsonEncode(d)); if(r.statusCode!=200) throw Exception('Error \${r.statusCode}: \${r.body}'); } static Future actualizar(String e, int id, Map d) async { final r = await http.put(Uri.parse('\$base/api/\$e/\$id'), headers:{'Content-Type':'application/json'}, body: jsonEncode(d)); if(r.statusCode!=200) throw Exception('Error \${r.statusCode}: \${r.body}'); } static Future borrar(String e, int id) async { await http.delete(Uri.parse('\$base/api/\$e/\$id')); } }"; 
}

$create_db_sql = "CREATE DATABASE \"{$nombre_db}\" WITH ENCODING='UTF8' TEMPLATE template1;";
$tables = []; $constraints = [];
foreach($clases as $c){
  $tn = $c['name']; $cols = []; $pks = [];
  foreach($c['attrs'] as $a){
    $col = "\"{$a['campo']}\" ". ($a['pk'] && $a['ai'] ? 'bigserial' : $a['sql']);
    if (!$a['nullable']) $col .= ' NOT NULL';
    if ($a['unique']) $col .= ' UNIQUE';
    $cols[] = $col;
    if ($a['pk']) $pks[] = "\"{$a['campo']}\"";
    if ($a['fk_de']){ $ref = $a['fk_de']; $onDel = $a['on_delete'] ?? 'RESTRICT'; $constraints[] = "ALTER TABLE \"{$tn}\" ADD CONSTRAINT \"fk_{$tn}_{$a['campo']}\" FOREIGN KEY (\"{$a['campo']}\") REFERENCES \"{$ref}\"(\"id\") ON DELETE {$onDel};"; }
  }
  if ($pks) $cols[] = "PRIMARY KEY (".implode(',',$pks).")";
  $tables[] = "CREATE TABLE \"{$tn}\" (\n  ".implode(",\n  ", $cols)."\n);";
}
$schema_sql = implode("\n\n", $tables) . "\n\n" . implode("\n", $constraints) . "\n";

$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR."proyecto_{$nombre_db}_".substr(md5(mt_rand()),0,6).".zip";
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE|ZipArchive::OVERWRITE)!==TRUE){ http_response_code(500); exit; }
$zip->addFromString("README.txt", "PROYECTO WOW GENERADO\n\n- Backend con Swagger: http://localhost:8080/swagger-ui.html\n- Frontend con Formularios Dinámicos.\n");
$zip->addFromString("db/create_db.sql", $create_db_sql);
$zip->addFromString("db/schema.sql", $schema_sql);
$zip->addFromString("backend/pom.xml", pom_xml($pkg));
$zip->addFromString("backend/src/main/resources/application.properties", application_props($nombre_db));
$zip->addFromString("backend/src/main/java/".str_replace('.','/',$pkg)."/".$nombre_app."Application.java", main_java($pkg,$nombre_app));
foreach($clases as $c){
  $n = $c['name'];
  $ent = entidad_java($pkg,$c);
  $gs = [];
  foreach($c['attrs'] as $a){ $tj=$a['java']; $nm=$a['campo']; $Nm=pascal($nm); $gs[]="  public {$tj} get{$Nm}(){ return this.{$nm}; }\n  public void set{$Nm}({$tj} v){ this.{$nm}=v; }"; }
  $ent .= "\n".implode("\n",$gs).entidad_java_cierre();
  $zip->addFromString("backend/src/main/java/".str_replace('.','/',$pkg)."/entidad/{$n}.java", $ent);
  $zip->addFromString("backend/src/main/java/".str_replace('.','/',$pkg)."/repo/{$n}Repo.java", repo_java($pkg,$n));
  $zip->addFromString("backend/src/main/java/".str_replace('.','/',$pkg)."/controller/{$n}Controller.java", ctrl_java($pkg,$n));
}
$zip->addFromString("front_flutter/pubspec.yaml", flutter_pubspec());
$zip->addFromString("front_flutter/lib/api.dart", flutter_api_dart());
$zip->addFromString("front_flutter/lib/main.dart", flutter_main_dart($clases));
$zip->close();
$fn = basename($id ?: $nombre_db)."_proyecto.zip";
header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.$fn.'"'); header('Content-Length: '.filesize($tmp));
readfile($tmp); @unlink($tmp);
?>