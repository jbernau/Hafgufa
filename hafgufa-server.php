<?PHP

/**
* load silex
*/
require_once __DIR__.'/libs/silex.phar'; 

/**
* load Neo4jphp
*/
require_once __DIR__.'/libs/neo4jphp.phar';

/**
* init silex
*/
$app = new Silex\Application(); 
$app['debug'] = true;

/**
* init Neo4J: Connect to port on host
*/
$neo4jclient = new Everyman\Neo4j\Client('localhost', 7474);

/**
*/
$app->get('/page/{id}', function($id) use($app,$neo4jclient) {

  global $rootCluster,$baseURL;
  
  // ***********************************************************************
  // Attribute sammeln
  $qs = "START n=node($id) RETURN n";

  $query = new Everyman\Neo4j\Cypher\Query($neo4jclient,$qs);
  $result = $query->getResultSet();

  $attributes = array();
  $attributes[] = array( "name" => "NODE_ID", "value" => $id);

  foreach($result as $n) {
    $arr = $n['n'];
    $arr2 = $arr->getProperties();
    foreach($arr2 as $k => $v) {
      $attributes[] = array( "name" => $k, "value" => $v);
    }
  }

  $data = array();

  $counter = 0;

  // ***********************************************************************
  // Eingehende Kanten
  $qs = "
    START
      n=node($id) 
    MATCH 
      n<-[r]-x 
    RETURN
      TYPE(r)     as TYPE,
      count(x)    as C
    LIMIT 20
  ";

  $query = new Everyman\Neo4j\Cypher\Query($neo4jclient,$qs);
  $result = $query->getResultSet();

  foreach($result as $row) {

    $data[] = array("ID"     => $counter++,
                    "NODE"   => $id,
                    "TYPE"   => $row['TYPE'],
                    "DIR"    => "IN",
                    "C"      => $row['C']);
  }

  // ***********************************************************************
  // Ausgehende Kanten
  $qs = "
    START
      n=node($id) 
    MATCH 
      n-[r]->x 
    RETURN
      TYPE(r)     as TYPE,
      count(x)    as C
    LIMIT 20
  ";

  $query = new Everyman\Neo4j\Cypher\Query($neo4jclient,$qs);
  $result = $query->getResultSet();

  foreach($result as $row)  {

    $data[] = array("ID"     => $counter++,
                    "NODE"   => $id,
                    "TYPE"   => $row['TYPE'],
                    "DIR"    => "OUT",
                    "C"      => $row['C']);
  }

  $page = array( "attributes" => $attributes,
                 "relations"  => $data);

  $headers = array("Content-Type" => "application/json",
                   "Charset"      => "utf-8" );
  $retval = json_encode($page);
  return new Symfony\Component\HttpFoundation\Response($retval,200,$headers);
  

});

/**
  Fetch Node Data for nodes related to node "node_id" with 
  relationship "rel_type" and direction "rel_dir"

  @param node_id	a valid neo4j node id
  @param rel_type 	a valid neo4j relationship type ("IS_A" "HAS" ...)
  @param rel_dir  	IN or OUT
*/
$app->get('/list/{node_id}/{rel_type}/{rel_dir}/{page}/{term}/{sort_val}/{sort_dir}', 
  function($node_id, $rel_type, $rel_dir, $page, $term, $sort_val, $sort_dir) use($app,$neo4jclient) {

  if ($rel_dir == "IN") { $match = "cr <-[:$rel_type]- x"; } 
  else                  { $match = "cr -[:$rel_type]-> x"; }

  $sort="";
  if (($sort_val) && ($sort_val != "none")) {
    $sort = "ORDER BY x.$sort_val? $sort_dir";
  }

  $where="";
  if (($term) && ($term != "none")) {
    $where = "WHERE x.title? =~ /(?i).*$term.*/";
  }
  
  $skip = "";
  if ($page > 0) {
    $skip = "SKIP " . ($page * 20);
  }

  $qs = "
    START cr=node($node_id) 
    MATCH $match
    $where
    RETURN x
    $sort
    $skip LIMIT 15
  ";


  $query = new Everyman\Neo4j\Cypher\Query($neo4jclient,$qs);
  $result = $query->getResultSet();
  $data = array();
  $attributes = array();
  $attribute_names = array();

  $count = 0;
  foreach($result as $row)
  {
    $node = $row['x'];
    $props = $node->getProperties();

    foreach($props as $k => $v) {
      if (!isset($attributes[$k])) { 
	$attributes[$k] = 1; 
	$attribute_names[] = array("name" => $k);
      }
    }

    $props['ID'] = $node->getId();

    $bart = "none";
    if ($props['id'] && $props['appid'])  $bart = "beitrag";
    if ($props['id'] && $props['source']) $bart = "app";
    if ($props['offers'])                 $bart = "me_product";

    $props['BART'] = $bart;
    $data[] = $props;
  }

  $headers = array("Content-Type" => "application/json",
    	           "Charset" => "utf-8" );

  $retData = array("attributes" => $attribute_names,
                   "data"       => $data);

  $retval = json_encode($retData);

  return new Symfony\Component\HttpFoundation\Response($retval,200,$headers);
});


$app->run(); 

?>
