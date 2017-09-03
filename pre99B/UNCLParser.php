<?php

class UNCLParser
{
    private $msgXML;

    public function __construct ($filePath)
    {
        $this->msgXML = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8" standalone="yes"?><data_elements></data_elements>');

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
        $fileLines = preg_replace('/[\xC4]/', '-', $fileLines);

        $unclArr = preg_split('/[-]{70}/', $fileLines);
        
        unset($unclArr[0]);
        
        foreach ($unclArr as $unclElm) {
            $elmArr = preg_split('/[\r\n]+/', $unclElm);

            $elementStatus='';
            $elementCode = '';
            $elementTitle = '';
            $elementUse='';

            $elementDescription ='';

            $elementType='';
            $elementMaxSize='';

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
                    $result = preg_match("/^(.{2})([0-9\s]{6})(.{59})\[([A-Z]?)\]/", $row, $codeArr);
                    if(!isset($codeArr[1])) {
                        $result = preg_match("/^(.{2})([0-9\s]{6})(.*)/", $row, $codeArr);

                        $elementStatus = trim($codeArr[1]);
                        $elementCode = trim($codeArr[2]);
                        $elementTitle = trim($codeArr[3]);
                        $i++;
                        $result = preg_match("/^[\s]{8}(.*)\[([A-Z]?)\]/", $elmArr[$i], $codeArr2);
                        $elementTitle .= " ".trim($codeArr2[1]);
                        $elementUse = $codeArr2[2];
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

                if($elementDescription === '' && preg_match("/[\s]{2}Desc: (.*)/", $row, $matches)) {
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
                    $result = preg_match("/^\s{2}Repr: (a?n?)[\.]*(\d+)/", $row, $codeArr);
                    $elementType = trim($codeArr[1]);
                    $elementMaxSize = trim($codeArr[2]);
                    $i++;
                    continue;
                }

                if(preg_match("/(.{3})(.{6})\s(.*)/", $row, $matches)) {
                    $valueChange = trim($matches[1]);
                    $valueValue = trim($matches[2]);
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
}
