<?php

class EDCDParser
{
    private $msgXML;

    public function __construct ($filePath)
    {
        $this->msgXML = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8" standalone="yes"?><composite_data_elements></composite_data_elements>');

        $arrayXml = $this->process($filePath);

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

    private function process ($filePath) {
        $fileLines = file_get_contents($filePath);
        $edcdArr = preg_split('/[-]{70}/', $fileLines);
        
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
                    $result = preg_match("/[\s]{4}.{1}[\s]{2}([A-Z0-9]{4})\s+([A-Z\s]+)/", $row, $codeArr);
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
                if (preg_match("/[\d]{3}.{4}([\w]{4})\s([\w\s]{10,43})(?:([\w]{1})([\d\s]{5}))?(?:\s{1}([\w\d\.]{2,8}))*/", $elmArr[$i], $matches)) {
                    $dataElement=[
                        'elementId' => $matches[1],
                        'elementName' => trim($matches[2])
                    ];
                    if ($matches[1]{0} == 'C') {
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
                        preg_match("/^[\s]{13}([\w\s]{43})([\w]{1})([\d\s]{5})(?:\s{1}([\w\d\.]{2,8}))*/", $elmArr[$i], $matches);
                        
                    }

                    $dataElements[]= $dataElement;
                }
                $i++;
            }

            $defXML->addAttribute('id', $segmentCode);
            $defXML->addAttribute('name', lcfirst(str_replace(" ", "", ucwords(strtolower($segmentTitle)))));
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
}
