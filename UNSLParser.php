<?php

class UNSLParser
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
            $this->errors[] = "Critical error in UNSLParser: " . $e->getMessage();
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
            throw new Exception("UNSL file not found: $filePath");
        }

        if (!is_readable($filePath)) {
            throw new Exception("UNSL file is not readable: $filePath");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            throw new Exception("UNSL file is empty: $filePath");
        }

        if ($fileSize > 50 * 1024 * 1024) { // 50MB limit
            throw new Exception("UNSL file is too large: $filePath (" . round($fileSize / 1024 / 1024, 2) . " MB)");
        }
    }

    private function process ($filePath) {
        $fileLines = file_get_contents($filePath);
        if ($fileLines === false) {
            throw new Exception("Failed to read file: $filePath");
        }
        $fileLines = preg_replace('/[\xC4]/', '-', $fileLines);

        $unslArr = preg_split('/[-]{70}/', $fileLines);

        if (count($unslArr) < 2) {
            $this->warnings[] = "File may not be properly formatted - found only " . count($unslArr) . " sections";
        }

        unset($unslArr[0]);

        foreach ($unslArr as $unslElm) {
            $elmArr = preg_split('/[\r\n]+/', $unslElm);

            $elementStatus='';
            $elementCode = '';
            $elementTitle = '';

            $elementDescription ='';

            $elementType='';
            $elementMaxSize='';

            $elementNote='';
            $elementValues=[];

            $defXML = $this->msgXML->addChild("data_element");

            $i = 0;
            for ($i=0;$i<count($elmArr);) {
                $row = $elmArr[$i];
                if (strlen($row) < 1) {
                    $i++;
                    continue;
                }

                if ($elementCode === '') {
                    $result = preg_match("/^(.{2})([0-9\s]{6})(.{0,62})/", $row, $codeArr);
                    if (!$result || !isset($codeArr[1])) {
                        $this->warnings[] = "Could not parse element header: $row";
                        break;
                    }
                    $elementStatus = trim($codeArr[1]);
                    $elementCode = trim($codeArr[2]);
                    $elementTitle = trim($codeArr[3]);
                    $i++;
                    continue;
                }

                if($elementDescription === '' && preg_match("/.{2}Desc: (.*)/", $row, $matches)) {
                    $elementDescription = $matches[1];
                    $i++;
                    while (strlen($elmArr[$i])>1) {
                        if (preg_match("/^[\s]{8}(.*)/", $elmArr[$i], $matches)) {
                            $elementDescription .= " ".$matches[1];
                            $i++;
                        } else {
                            break;
                        }
                    }
                    continue;
                }

                if ($elementType === '') {
                    $result = preg_match("/^.{2}Repr: (a?n?)[\.]*(\d+)/", $row, $codeArr);
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

                if(preg_match("/(.{3})(.{6})\s(.*)/", $row, $matches)) {
                    $valueChange = trim($matches[1]);
                    $valueValue = $matches[2];
                    $valueTitle = $matches[3];
                    $valueDescription = '';
                    $i++;
                    if (trim($valueValue) =="") {
                        continue;
                    }
                    while (strlen($elmArr[$i])>1) {
                        if (preg_match("/^[\s]{13}(.*)/", $elmArr[$i], $matches)) {
                            if ($valueDescription != '') {
                                $valueDescription .= " ";
                            }
                            $valueDescription .= $matches[1];
                            $i++;
                        } else if (preg_match("/^[\s]{10}(.*)/", $elmArr[$i], $matches)) {
                            if (trim($matches[1]) =="Note:") {
                                break;
                            }
                            if ($valueTitle != '') {
                                $valueTitle .= " ";
                            }
                            $valueTitle .= $matches[1];
                            $i++;
                        } else {
                            break;
                        }
                    }
                    $elementValues[] = [
                        'value' => trim($valueValue),
                        'title' => $valueTitle,
                        'descr' => $valueDescription
                    ];

                    continue;
                }

                $i++;
            }

            $defXML->addAttribute('id', $elementCode);
            $defXML->addAttribute('name', lcfirst(str_replace(" ", "", ucwords(strtolower($elementTitle)))));
            //$defXML->addAttribute('usage', $elementUse);
            $defXML->addAttribute('desc', $elementDescription);
            $defXML->addAttribute('type', $elementType);
            $defXML->addAttribute('maxlength', $elementMaxSize);
            foreach ($elementValues as $codes) {
                $cdefXML = $defXML->addChild('code');
                $cdefXML->addAttribute('id', utf8_encode($codes['value']));
                $cdefXML->addAttribute('title', utf8_encode($codes['title']));
                $cdefXML->addAttribute('desc', utf8_encode($codes['descr']));
            }
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
