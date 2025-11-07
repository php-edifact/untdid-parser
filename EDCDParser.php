<?php

class EDCDParser
{
    private $msgXML;
    private $errors = [];
    private $warnings = [];

    public function __construct ($filePath)
    {
        $this->msgXML = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8" standalone="yes"?><composite_data_elements></composite_data_elements>');

        try {
            $this->validateInput($filePath);
            $this->process($filePath);
        } catch (Exception $e) {
            $this->errors[] = "Critical error in EDCDParser: " . $e->getMessage();
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
            throw new Exception("EDCD file not found: $filePath");
        }

        if (!is_readable($filePath)) {
            throw new Exception("EDCD file is not readable: $filePath");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            throw new Exception("EDCD file is empty: $filePath");
        }

        if ($fileSize > 50 * 1024 * 1024) { // 50MB limit
            throw new Exception("EDCD file is too large: $filePath (" . round($fileSize / 1024 / 1024, 2) . " MB)");
        }
    }

    private function process ($filePath) {
        $fileLines = file_get_contents($filePath);
        if ($fileLines === false) {
            throw new Exception("Failed to read file: $filePath");
        }
        $fileLines = preg_replace('/[\xC4]/', '-', $fileLines);

        $edcdArr = preg_split('/[-]{70}/', $fileLines);

        if (count($edcdArr) < 2) {
            $this->warnings[] = "File may not be properly formatted - found only " . count($edcdArr) . " sections";
        }

        unset($edcdArr[0]);

        foreach ($edcdArr as $edcdElm) {
            $elmArr = preg_split('/[\r\n]+/', $edcdElm);
            $segmentCode = '';
            $segmentTitle = '';
            $segmentFunction ='';
            $dataElements = [];

            $defXML = $this->msgXML->addChild("composite_data_element");

            $i = 0;
            for ($i=0;$i<count($elmArr);) {
                $row = $elmArr[$i];
                if (strlen($row) < 1) {
                    $i++;
                    continue;
                }

                // segment name, change indicator
                if ($segmentCode === '') {
                    $result = preg_match("/[\s]{4}.{1,3}[\s]{0,2}([A-Z0-9]{4})\s+([A-Z\s]+)/", $row, $codeArr);
                    if (!$result || !isset($codeArr[1])) {
                        $this->warnings[] = "Could not parse segment header: $row";
                        break;
                    }
                    $segmentCode = $codeArr[1];
                    $segmentTitle = $codeArr[2];
                    $i++;
                    continue;
                }

                // function
                if($segmentFunction === '' && preg_match("/[\s]{7}Desc: (.*)/", $row, $matches)) {
                    $segmentFunction = $matches[1];
                    $i++;
                    while (strlen($elmArr[$i])>1) {
                        if (preg_match("/^[\s]{13}(.*)/", $elmArr[$i], $matches)) {
                            $segmentFunction .= " ".$matches[1];
                            $i++;
                        } else {
                            break;
                        }
                    }
                }

                // element list, change indicator
                if (preg_match("/[\d]{3}.{4}([\w]{4})\s([\w\s\/]{10,43})(?:([\w]{1})([\d\s]{5}))?(?:\s{1}([\w\d\.]{2,8}))*/", $elmArr[$i], $matches)) {
                    $dataElement=[
                        'elementId' => $matches[1],
                        'elementName' => trim($matches[2])
                    ];
                    if ($matches[1][0] == 'C') {
                        $dataElement['composite'] = true;
                    } else {
                        $dataElement['composite'] = false;
                    }

                    if (isset($matches[3])) {
                        $dataElement['elementCondition'] = $matches[3];
                        $dataElement['elementRepetition'] = trim($matches[4]);
                        if (isset($matches[5])) {
                            $dataElement['elementType'] = trim($matches[5]);
                        }
                    } else { // second row
                        $i++;
                        if(strlen($elmArr[$i]) < 1) {
                            continue;
                        }
                        preg_match("/[\s]{12}([\w\s]{43})([\w]{1})([\d\s]{5})(?:\s{1}([\w\d\.]{2,8}))*/", $elmArr[$i], $matches);
                        if(!isset($matches[1])) {
                            $i++;
                            continue;
                        }
                        $dataElement['elementName'].= " ".trim($matches[1]);
                        $dataElement['elementCondition'] = $matches[2];
                        $dataElement['elementRepetition'] = trim($matches[3]);
                        if (isset($matches[4])) {
                            $dataElement['elementType'] = trim($matches[4]);
                        }
                    }

                    $dataElements[]= $dataElement;
                }
                $i++;
            }

            $defXML->addAttribute('id', $segmentCode);
            $segmentTitle = lcfirst(str_replace(" ", "", ucwords(strtolower($segmentTitle))));
            $segmentTitle = str_replace("/", "Or", $segmentTitle);
            $defXML->addAttribute('name', $segmentTitle);
            $defXML->addAttribute('desc', $segmentFunction);
            foreach ($dataElements as $childs) {
                $ctype = 'data_element';
                if ($childs['composite']) {
                    $ctype = "composite_data_element";
                }
                $cdefXML = $defXML->addChild($ctype);
                $cdefXML->addAttribute('id', $childs['elementId']);
                if ($childs['elementCondition'] == 'M') {
                    $cdefXML->addAttribute('required', "true");
                }
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
