<?php

class EDEDParser
{
    private $msgXML;
    private $errors = [];
    private $warnings = [];

    public function __construct ($filePath)
    {
        $this->msgXML = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8" standalone="yes"?><data_elements></data_elements>');

        try {
            $this->validateInput($filePath);
            $this->process($filePath);
        } catch (Exception $e) {
            $this->errors[] = "Critical error in EDEDParser: " . $e->getMessage();
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

    private function validateInput($filePath)
    {
        if (!file_exists($filePath)) {
            throw new Exception("EDED file not found: $filePath");
        }

        if (!is_readable($filePath)) {
            throw new Exception("EDED file is not readable: $filePath");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            throw new Exception("EDED file is empty: $filePath");
        }

        if ($fileSize > 50 * 1024 * 1024) { // 50MB limit
            throw new Exception("EDED file is too large: $filePath (" . round($fileSize / 1024 / 1024, 2) . " MB)");
        }
    }

    private function process ($filePath) {
        $fileLines = file_get_contents($filePath);
        if ($fileLines === false) {
            throw new Exception("Failed to read file: $filePath");
        }
        $fileLines = preg_replace('/[\xC4]/', '-', $fileLines);

        $ededArr = preg_split('/[-]{70}/', $fileLines);

        if (count($ededArr) < 2) {
            $this->warnings[] = "File may not be properly formatted - found only " . count($ededArr) . " sections";
        }

        unset($ededArr[0]);

        foreach ($ededArr as $ededElm) {
            $elmArr = preg_split('/[\r\n]+/', $ededElm);

            $elementStatus='';
            $elementCode = '';
            $elementTitle = '';
            $elementUse='';

            $elementDescription ='';

            $elementType='';
            $elementMaxSize='';

            $elementNote='';

            $defXML = $this->msgXML->addChild("data_element");

            $i = 0;
            for ($i=0;$i<count($elmArr);) {
                $row = $elmArr[$i];
                if (strlen($row) < 1) {
                    $i++;
                    continue;
                }

                if ($elementCode === '') {
                    $result = preg_match("/^(.{5})([0-9\s]{6})(.{56})\[([A-Z]?)\]/", $row, $codeArr);
                    if(!isset($codeArr[1])) {
                        $result = preg_match("/^(.{5})([0-9\s]{6})(.*)/", $row, $codeArr);
                        if (!isset($codeArr[1])) {
                            $this->warnings[] = "Could not parse element header: $row";
                            break;
                        }
                        $elementStatus = trim($codeArr[1]);
                        $elementCode = trim($codeArr[2]);
                        $elementTitle = trim($codeArr[3]);
                        $i++;
                        if ($i >= count($elmArr)) {
                            $this->warnings[] = "Unexpected end of section while parsing element header continuation";
                            break;
                        }
                        $result = preg_match("/^[\s]{11}(.*)\[([A-Z]?)\]/", $elmArr[$i], $codeArr2);
                        if (!$result) {
                            $this->warnings[] = "Could not parse element usage: " . $elmArr[$i];
                            $elementTitle .= " " . trim($elmArr[$i]);
                        } else {
                            $elementTitle .= " ".trim($codeArr2[1]);
                            $elementUse = $codeArr2[2];
                        }
                        $i++;
                        continue;
                    }
                    $elementStatus = trim($codeArr[1]);
                    $elementCode = trim($codeArr[2]);
                    $elementTitle = trim($codeArr[3]);
                    $elementUse = $codeArr[4];
                    $i++;
                    continue;
                }

                if($elementDescription === '' && preg_match("/.{1}\s{4}Desc: (.*)/", $row, $matches)) {
                    $elementDescription = $matches[1];
                    $i++;
                    while (strlen($elmArr[$i])>1) {
                        if (preg_match("/^[\s]{11}(.*)/", $elmArr[$i], $matches)) {
                            $elementDescription .= " ".$matches[1];
                            $i++;
                        } else {
                            break;
                        }
                    }
                    continue;
                }

                if ($elementType === '') {
                    $result = preg_match("/^.{1}\s{4}Repr: (a?n?)[\.]*(\d+)/", $row, $codeArr);
                    if(!isset($codeArr[1])) {
                        $this->warnings[] = "Could not parse representation: $row";
                    } else {
                        $elementType = trim($codeArr[1]);
                        $elementMaxSize = trim($codeArr[2]);
                    }
                    $i++;
                    continue;
                }

                if($elementNote === '' && preg_match("/[\s]{5}Note:/", $row, $matches)) {
                    $elementNote = "";
                    $i++;
                    while (strlen($elmArr[$i])>1) {
                        if (preg_match("/^[\s]{11}(.*)/", $elmArr[$i], $matches)) {
                            if ($elementNote != '') {
                                $elementNote .= " ";
                            }
                            $elementNote .= $matches[1];
                            $i++;
                        } else {
                            break;
                        }
                    }
                    continue;
                }

                $i++;
            }

            $defXML->addAttribute('id', $elementCode);
            $elementTitle = lcfirst(str_replace(" ", "", ucwords(strtolower($elementTitle))));
            $elementTitle = str_replace("/", "Or", $elementTitle);
            $defXML->addAttribute('name', $elementTitle);
            $defXML->addAttribute('usage', $elementUse);
            $defXML->addAttribute('desc', $elementDescription);
            $defXML->addAttribute('type', $elementType);
            $defXML->addAttribute('maxlength', $elementMaxSize);
        }

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
