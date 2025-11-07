<?php

class EDMDParser
{
    private $msgXML;
    private $errors = [];
    private $warnings = [];

    public function __construct ($filePath)
    {
        $this->msgXML = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8" standalone="yes"?><message></message>');

        try {
            $this->validateInput($filePath);
            $this->process($filePath);
        } catch (Exception $e) {
            $this->errors[] = "Critical error in EDMDParser: " . $e->getMessage();
            throw $e;
        }

        $msg = $this->msgXML->asXML();
        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->xmlStandalone = true;
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom_xml = dom_import_simplexml($this->msgXML);
        $dom_xml = $dom->importNode($dom_xml, true);
        $dom_xml = $dom->appendChild($dom_xml);
        $result = $dom->saveXML();

        $this->msgXML = $result;
    }

    private function arrRecursion (&$arr, $level, $counter, $segment, $currentIndex) {
        if ($counter < $level) {
            $counter++;
            return $this->arrRecursion($arr[$currentIndex[$counter]], $level, $counter, $segment, $currentIndex);
        }
        if ($level == $counter) {
            $arr[] = $segment;
            return $arr;
        }
    }

    private function validateInput($filePath)
    {
        if (!file_exists($filePath)) {
            throw new Exception("EDMD file not found: $filePath");
        }

        if (!is_readable($filePath)) {
            throw new Exception("EDMD file is not readable: $filePath");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            throw new Exception("EDMD file is empty: $filePath");
        }

        if ($fileSize > 50 * 1024 * 1024) { // 50MB limit
            throw new Exception("EDMD file is too large: $filePath (" . round($fileSize / 1024 / 1024, 2) . " MB)");
        }
    }

    private function process ($filePath) {
        if (is_dir($filePath)) {
            $this->errors[] = "$filePath is a directory";
            return;
        }
        $fileLines = file($filePath);
        if ($fileLines === false) {
            throw new Exception("Failed to read file: $filePath");
        }

        $skip = true;
        $currentLevel = 0;
        $currentIndex = [0=>null];
        $currentIndex1=null;
        $currentIndex2=null;
        $currentIndex3=null;
        $currentIndex4=null;

        $arrayXml=[];
        $defaults = [];
        $groupsArr = [];

        $defXML = $this->msgXML->addChild("defaults");

        foreach($fileLines as $line) {

            $line = preg_replace('/[\xC4]/', '-', $line);
            $line = preg_replace('/[\xB3]/', '|', $line);
            $line = preg_replace('/[\xD9\xC1\xBF]/', '+', $line);

            if (preg_match("/[\s]{43}Message Type : ([A-Z]{6})\r\n/", $line, $matches)) {
                $defaults['0065'] = $matches[1];
                $cdefXML = $defXML->addChild("data_element");
                $cdefXML->addAttribute('id', "0065");
                $cdefXML->addAttribute('value', $matches[1]);
            }
            if (preg_match("/[\s]{43}Version      : ([A-Z]{1})\r\n/", $line, $matches)) {
                $defaults['0052'] = $matches[1];
                $cdefXML = $defXML->addChild("data_element");
                $cdefXML->addAttribute('id', "0052");
                $cdefXML->addAttribute('value', $matches[1]);
            }
            if (preg_match("/[\s]{43}Release      : ([A-Z0-9]{3})\r\n/", $line, $matches)) {
                $defaults['0054'] = $matches[1];
                $cdefXML = $defXML->addChild("data_element");
                $cdefXML->addAttribute('id', "0054");
                $cdefXML->addAttribute('value', $matches[1]);
            }
            if (preg_match("/[\s]{43}Contr. Agency: ([A-Z]{2})\r\n/", $line, $matches)) {
                $defaults['0051'] = $matches[1];
                $cdefXML = $defXML->addChild("data_element");
                $cdefXML->addAttribute('id', "0051");
                $cdefXML->addAttribute('value', $matches[1]);
            }

            if ($skip == true) {
                if (preg_match("/Pos\s+Tag Name\s+S\s+R/", $line, $matches)) {
                    $skip = false;
                }
                continue;
            }

            $line = trim($line);
            if (strlen($line)<10) continue;

            //line, code, descr, mandatory, repetition, grouping
            //$intervals = array(7, 4, 42, 4, 5, 8);

            preg_match("/(\d{4,5})[X\*\+\|\s]+([\w\s]{4})(.{41})(.{2})\s+(\d{1,5})(.*)/", $line, $parts);
            array_shift($parts);

            $parts = array_map('trim', $parts);
            if (count($parts) < 1 || !preg_match('/(\d+)/', $parts[0])) {
                continue;
            }

            if ($parts[1] == '' && (strpos($parts[2], 'Segment group') !== false)) {
                $level = str_replace('-', '', $parts[5]);
                $currentLevel++;
                preg_match( '/(\d+)/', $parts[2], $matches);
                $sgIndex = "SG".$matches[1];
                if(!in_array($sgIndex,$groupsArr)) {
                    $parts[1] = $sgIndex;
                    $groupsArr[$sgIndex]=$this->createSegment($parts, false);
                }
                $currentIndex[$currentLevel] = $sgIndex;
                continue;
            }

            $segment = $this->createSegment($parts);

            $this->arrRecursion($arrayXml, $currentLevel, 0, $segment, $currentIndex);

            if ($parts[1] != '' && (strpos($parts[2], 'Segment group') === false) && (strpos($parts[5], '-') !== false) ) {
                $level = str_replace('-', '', $parts[5]);

                $levelsToRemove = substr_count($level,'+');
                $currentLevel-=$levelsToRemove;
                $kmax = max(array_keys($currentIndex));
                for ($k = $kmax; $k>0; $k--) {
                    $currentIndex[$k] = null;
                    $levelsToRemove--;
                    if($levelsToRemove==0)
                        break;
                }
            }
        }

        // build the XML
        $this->recurse($arrayXml, $this->msgXML, $groupsArr);
        return $arrayXml;
    }

    private function recurse(&$array, $xml, $groupsArr) {
        foreach ($array as $idx => $arr) {
            if (is_int($idx)) {
                $segXML = $xml->addChild("segment");
                $segXML->addAttribute('id', $arr['id']);
                //$segXML->addAttribute('name', $arr['name']);
                $segXML->addAttribute('maxrepeat', $arr['maxrepeat']);
                if(isset($arr['required'])) {
                    $segXML->addAttribute('required', 'true');
                }

            } else {
                $tempArr = $groupsArr[$idx];
                $groupXML = $xml->addChild("group");
                $groupXML->addAttribute('id', $tempArr['id']);
                //$segXML->addAttribute('name', $tempArr['name']);
                $groupXML->addAttribute('maxrepeat', $tempArr['maxrepeat']);
                if(isset($tempArr['required'])) {
                    $groupXML->addAttribute('required', 'true');
                }
                $this->recurse($arr, $groupXML, $groupsArr);
            }
        }
    }

    private function createSegment($parts, $name = true) {

        $arr = [
            'id' => $parts[1],
            'maxrepeat'=> str_replace('-', '', $parts[4])
        ];
        if ($name) {
            $arr['name'] = $parts[2];
        }
        if($parts[3] == 'M') {
            $arr['required'] = 'true';
        }
        return $arr;
    }

    public function getXML() {
        return $this->msgXML;
    }

    public function getErrors() {
        return $this->errors;
    }

    public function getWarnings() {
        return $this->warnings;
    }

    public function hasErrors() {
        return !empty($this->errors);
    }

    public function hasWarnings() {
        return !empty($this->warnings);
    }
}
