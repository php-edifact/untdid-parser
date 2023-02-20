<?php

$y = '09B';

if(php_sapi_name() === "cli" && isset($argv[1])) {
    $y = $argv[1];
}

include '../EDMDParser.php';
include '../UNSLParser.php';

$folder = '../';
if ($y == '99A' || (substr($y, 0, 2) < 99 && substr($y, 0, 2) > 80)) {
    $folder = '../pre99B/';
}

$edition ='D'.$y;

include $folder.'EDSDParser.php';
include $folder.'EDCDParser.php';
include $folder.'EDEDParser.php';
include $folder.'UNCLParser.php';

$zip = new ZipArchive;

if(!file_exists('extracted/'.$edition)) {
    mkdir('extracted/'.$edition, 0777, true);
}

if ($zip->open(strtolower($edition).'.zip') === TRUE) {
    $zip->extractTo('extracted/'.$edition.'/');
    $zip->close();
}


if(!file_exists('generated/'.$edition)) {
    mkdir('generated/'.$edition, 0777, true);
}
if(!file_exists('generated/'.$edition."/messages")) {
    mkdir('generated/'.$edition."/messages", 0777, true);
}

$files = scandir('extracted/'.$edition);

/*
NOTES ON EDITIONS
15B, 16A, 16B: zip contains a single folder 
15A: zip contains zip archives, each one containing a single folder
03A: zips inside a Edifact/Directory/Files tree
06A: zips inside a Edifact/Directory/Files tree and one folder per zip
99B: zips inside EDIFACT/DIRECTOR
97A: zips inside EDIFACT/D97ADISK, each zip contains EDIFACT/DIRECTOR folders
96B: zips inside /EDIFACT/DIRECTOR/ARCHIVES/96B/DISK-ASC/
<=96B: FORMAT CHECK
*/

//EXTRACT FILES FROM DIRECTORY ZIP
foreach($files as $file) {
    if(preg_match('/\.zip/', strtolower($file))) {
        $zip->open('extracted/'.$edition.'/'.$file);

        //EDSD - segments (TRSD)
        //EDCD - composite data elements (TRCD)
        //EDED - data elements (TRED)
        //UNCL - codes
        //EDMD - messages (TRMD)

        if(preg_match('/(edmd|trmd)\.zip/', strtolower($file))) {
            if(!file_exists('extracted/'.$edition.'/EDMD')) {
                mkdir('extracted/'.$edition.'/EDMD', 0777, true);
            }
            $zip->extractTo('extracted/'.$edition.'/EDMD');
        } else {
            $zip->extractTo('extracted/'.$edition.'/');
        }

        $zip->close();
    }
}


//ALL IN UPPERCASE
$files = scandir('extracted/'.$edition);
foreach($files as $name){
    if ($name == '.' || $name == '..') {
       continue;
    }
    $newName = strtoupper($name);
    rename("extracted/$edition/$name", "extracted/$edition/$newName");
}

$files = scandir('extracted/'.$edition.'/EDMD');
foreach($files as $name){
    if ($name == '.' || $name == '..') {
       continue;
    }
    $newName = strtoupper($name);
    rename("extracted/$edition/EDMD/$name", "extracted/$edition/EDMD/$newName");
}

if (file_exists('extracted/'.$edition."/TRSD.".$y))
    rename ('extracted/'.$edition."/TRSD.".$y, 'extracted/'.$edition."/EDSD.".$y);
if (file_exists('extracted/'.$edition."/TRCD.".$y))
    rename ('extracted/'.$edition."/TRCD.".$y, 'extracted/'.$edition."/EDCD.".$y);
if (file_exists('extracted/'.$edition."/TRED.".$y))
    rename ('extracted/'.$edition."/TRED.".$y, 'extracted/'.$edition."/EDED.".$y);


    if(!file_exists('extracted/'.$edition."/EDSD.".$y)) {
        die("No edsd file existing in ".$edition." extraction\n");
    }

    $edsd = "EDSD";

    $p = new EDSDParser('extracted/'.$edition."/".$edsd.".".$y);

    file_put_contents('generated/'.$edition."/simple_segments.xml", $p->getXML());

    $edcd = "EDCD";

    $p = new EDCDParser('extracted/'.$edition."/".$edcd.".".$y);
    file_put_contents('generated/'.$edition."/composite_data_elements.xml", $p->getXML());

    $eded = "EDED";

    $p = new EDEDParser('extracted/'.$edition."/".$eded.".".$y);
    file_put_contents('generated/'.$edition."/data_elements.xml", $p->getXML());

    $uncl = "UNCL";

    $p = new UNCLParser('extracted/'.$edition."/".$uncl.".".$y);
    file_put_contents('generated/'.$edition."/codes.xml", $p->getXML());

    $edmd = "EDMD";

    $dir = scandir('extracted/'.$edition."/EDMD");
    foreach($dir as $file) {
        if($file =="." || $file =="..") {
            continue;
        }
        $name = substr($file, 0, 6);
        if ($name == "EDMDI1" || $name == "EDMDI2") {
            continue;
        }
        $p = new EDMDParser('extracted/'.$edition."/EDMD/".$file);
        $data = $p->getXML();

        file_put_contents('generated/'.$edition."/messages/".strtolower($name).".xml", $data);
    }

/* XML MERGE */

$xml=simplexml_load_file('generated/'.$edition."/simple_segments.xml");
$data_elm=simplexml_load_file('generated/'.$edition."/data_elements.xml");
$compdata_elm=simplexml_load_file('generated/'.$edition."/composite_data_elements.xml");

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
file_put_contents('generated/'.$edition."/segments.xml",$result);
unlink('generated/'.$edition."/simple_segments.xml");
unlink('generated/'.$edition."/data_elements.xml");
unlink('generated/'.$edition."/composite_data_elements.xml");

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
