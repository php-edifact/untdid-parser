<?php

$y = '09B';

if(php_sapi_name() === "cli" && isset($argv[1])) {
    $y = $argv[1];
}

include 'EDMDParser.php';
include 'UNSLParser.php';

$folder = '';
if ($y == '99A' || (substr($y, 0, 2) < 99 && substr($y, 0, 2) > 80)) {
    $folder = 'pre99B/';
}

include $folder.'EDSDParser.php';
include $folder.'EDCDParser.php';
include $folder.'EDEDParser.php';
include $folder.'UNCLParser.php';




$edition ='D'.$y;

if(!file_exists($edition.'/simple_segments.xml')) {
    $zip = new ZipArchive;
    if ($zip->open($edition.'/EDSD.ZIP') === TRUE) {
        $zip->extractTo($edition.'/');
        $zip->close();
    } else if ($zip->open($edition.'/edsd.zip') === TRUE) {
        $zip->extractTo($edition.'/');
        $zip->close();
    } else if ($zip->open($edition.'/Edsd.zip') === TRUE) {
        $zip->extractTo($edition.'/');
        $zip->close();
    } else {
        echo 'failed edsd';
    }
    
    $p = new EDSDParser($edition."/EDSD.".$y);

    file_put_contents($edition."/simple_segments.xml", $p->getXML());
}

if(!file_exists($edition.'/composite_data_elements.xml')) {
    $zip = new ZipArchive;
    if ($zip->open($edition.'/EDCD.ZIP') === TRUE) {
        $zip->extractTo($edition.'/');
        $zip->close();
    } else if ($zip->open($edition.'/Edcd.zip') === TRUE) {
        $zip->extractTo($edition.'/');
        $zip->close();
    } else if ($zip->open($edition.'/edcd.zip') === TRUE) {
        $zip->extractTo($edition.'/');
        $zip->close();
    } else {
        echo 'failed edcd';
    }
    $p = new EDCDParser($edition."/EDCD.".$y);
    file_put_contents($edition."/composite_data_elements.xml", $p->getXML());
}

if(!file_exists($edition.'/data_elements.xml')) {
    $zip = new ZipArchive;
    if ($zip->open($edition.'/EDED.ZIP') === TRUE) {
        $zip->extractTo($edition.'/');
        $zip->close();
    } else if ($zip->open($edition.'/Eded.zip') === TRUE) {
        $zip->extractTo($edition.'/');
        $zip->close();
    } else if ($zip->open($edition.'/eded.zip') === TRUE) {
        $zip->extractTo($edition.'/');
        $zip->close();
    } else {
        echo 'failed eded';
    }
    
    $p = new EDEDParser($edition."/EDED.".$y);
    file_put_contents($edition."/data_elements.xml", $p->getXML());
}

if(!file_exists($edition.'/codes.xml')) {
    $zip = new ZipArchive;
    if ($zip->open($edition.'/UNCL.ZIP') === TRUE) {
        $zip->extractTo($edition.'/');
        $zip->close();
    } else if ($zip->open($edition.'/Uncl.zip') === TRUE) {
        $zip->extractTo($edition.'/');
        $zip->close();
    } else if ($zip->open($edition.'/uncl.zip') === TRUE) {
        $zip->extractTo($edition.'/');
        $zip->close();
    } else {
        echo 'failed uncl';
    }
    
    $p = new UNCLParser($edition."/UNCL.".$y);
    file_put_contents($edition."/codes.xml", $p->getXML());
}


if(!file_exists($edition.'/EDMD')) {
    $zip = new ZipArchive;
    if ($zip->open($edition.'/EDMD.ZIP') === TRUE) {
        mkdir($edition.'/EDMD', 0777, true);
        $zip->extractTo($edition.'/EDMD');
        $zip->close();
    } else if ($zip->open($edition.'/Edmd.zip') === TRUE) {
        mkdir($edition.'/EDMD', 0777, true);
        $zip->extractTo($edition.'/EDMD');
        $zip->close();
    } else if ($zip->open($edition.'/edmd.zip') === TRUE) {
        mkdir($edition.'/EDMD', 0777, true);
        $zip->extractTo($edition.'/EDMD');
        $zip->close();
    } else {
        echo 'failed edmd';
    }
}

$dir = scandir($edition."/EDMD");
foreach($dir as $file) {
    if($file =="." || $file =="..") {
        continue;
    }
    $name = substr($file, 0, 6);
    if ($name == "EDMDI1" || $name == "EDMDI2") {
        continue;
    }
    $p = new EDMDParser($edition."/EDMD/".$file);
    $data = $p->getXML();
    if(!file_exists($edition."/messages")) {
        mkdir($edition."/messages", 0777, true);
    }
    file_put_contents($edition."/messages/".strtolower($name).".xml", $data);
}

/* XML MERGE */

$xml=simplexml_load_file($edition."/simple_segments.xml");
$data_elm=simplexml_load_file($edition."/data_elements.xml");
$compdata_elm=simplexml_load_file($edition."/composite_data_elements.xml");

foreach ($xml->segment as $seg)
{
	foreach($seg->children() as $child)
	{
		if($child->getName()=="composite_data_element")
		{
		    $result = $compdata_elm->xpath('*[@id="'.$child["id"].'"]');
		    foreach ($result[0]->attributes() as $k => $v)
		    {
		        if($k=="id") {
		            continue;
		        }
		        $child->addAttribute($k,$v);
		    }
		    foreach ($result[0]->children() as $orphan)
		    {
		        xml_adopt($child,$orphan);
		    }
		}
	}
}

foreach ($xml->segment as $seg)
{
	foreach($seg->children() as $child)
	{
		if($child->getName()=="data_element")
		{
		    $result = $data_elm->xpath('*[@id="'.$child["id"].'"]');
		    if (count($result) == 0) {
		        continue;
		    }
		    foreach ($result[0]->attributes() as $k => $v)
		    {
		        if($k=="id") {
		            continue;
		        }
		        $child->addAttribute($k,$v);
		    }
		    foreach ($result[0]->children() as $orphan)
		    {
		        xml_adopt($child,$orphan);
		    }
		}

		if($child->getName()=="composite_data_element")
		{
		    foreach($child->children() as $child2)
		    {
		        if($child2->getName()=="data_element")
		        {
		            $result = $data_elm->xpath('*[@id="'.$child2["id"].'"]');
		            if (count($result)<1) {
		                var_dump($child2["id"]); die();
		            }
		            foreach ($result[0]->attributes() as $k => $v)
		            {
		                if($k=="id") {
		                    continue;
		                }
		                $child2->addAttribute($k,$v);
		            }
		        }
		    }
		}
	}
}

        
$msg = $xml->asXML();
$dom = new DOMDocument('1.0', 'utf-8');
$dom->xmlStandalone = true;
$dom->preserveWhiteSpace = false;
$dom->formatOutput = true;
$dom->loadXML($msg);
$result = $dom->saveXML();
file_put_contents($edition."/segments.xml",$result);

function xml_adopt($root, $new, $namespace = null) {
    // first add the new node
    $node = $root->addChild($new->getName(), (string) $new, $namespace);
    // add any attributes for the new node
    foreach($new->attributes() as $attr => $value) {
        $node->addAttribute($attr, $value);
    }
    // get all namespaces, include a blank one
    $namespaces = array_merge(array(null), $new->getNameSpaces(true));
    // add any child nodes, including optional namespace
    foreach($namespaces as $space) {
      foreach ($new->children($space) as $child) {
        xml_adopt($node, $child, $space);
      }
    }
}
