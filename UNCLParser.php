<?php

class UNCLParser
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
        } catch (Exception $e) {
            $this->errors[] = "Critical error in UNCLParser: " . $e->getMessage();
            throw $e;
        }
    }

    private function validateInput($filePath)
    {
        if (!file_exists($filePath)) {
            throw new Exception("UNCL file not found: $filePath");
        }

        if (!is_readable($filePath)) {
            throw new Exception("UNCL file is not readable: $filePath");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            throw new Exception("UNCL file is empty: $filePath");
        }

        if ($fileSize > 50 * 1024 * 1024) { // 50MB limit
            throw new Exception("UNCL file is too large: $filePath (" . round($fileSize / 1024 / 1024, 2) . " MB)");
        }
    }

    private function process ($filePath) {
        try {
            $fileLines = file_get_contents($filePath);
            if ($fileLines === false) {
                throw new Exception("Failed to read file: $filePath");
            }

            $fileLines = preg_replace('/[\xC4]/', '-', $fileLines);

            $unclArr = preg_split('/[-]{70}/', $fileLines);

            if (count($unclArr) < 2) {
                $this->warnings[] = "File may not be properly formatted - found only " . count($unclArr) . " sections";
            }

            unset($unclArr[0]);

            $processedElements = 0;

            foreach ($unclArr as $sectionIndex => $unclElm) {
                try {
                    $elmArr = preg_split('/[\r\n]+/', $unclElm);

                    $elementStatus = '';
                    $elementCode = '';
                    $elementTitle = '';
                    $elementUse = '';

                    $elementDescription = '';

                    $elementType = '';
                    $elementMaxSize = '';

                    $elementValues = [];

                    $defXML = $this->msgXML->addChild("data_element");

                    $i = 0;
                    for ($i = 0; $i < count($elmArr);) {
                        $row = $elmArr[$i];
                        if (strlen($row) < 1) {
                            $i++;
                            continue;
                        }

                        if ($elementCode === '') {
                            $result = preg_match("/^(.{5})([0-9\s]{6})(.{56})\[([A-Z]?)\]/", $row, $codeArr);
                            if (!isset($codeArr[1])) {
                                $result = preg_match("/^(.{5})([0-9\s]{6})(.*)/", $row, $codeArr);

                                if (!isset($codeArr[1])) {
                                    $this->warnings[] = "Section $sectionIndex: Could not parse element header: $row";
                                    break;
                                }

                                $elementStatus = trim($codeArr[1]);
                                $elementCode = trim($codeArr[2]);
                                $elementTitle = trim($codeArr[3]);
                                $i++;

                                if ($i >= count($elmArr)) {
                                    $this->warnings[] = "Section $sectionIndex: Unexpected end of section while parsing element header continuation";
                                    break;
                                }

                                $result = preg_match("/^[\s]{11}(.*)\[([A-Z]?)\]/", $elmArr[$i], $codeArr2);
                                if (!$result) {
                                    $this->warnings[] = "Section $sectionIndex: Could not parse element usage: " . $elmArr[$i];
                                    $elementTitle .= " " . trim($elmArr[$i]);
                                } else {
                                    $elementTitle .= " " . trim($codeArr2[1]);
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

                        if ($elementDescription === '' && preg_match("/[\s]{5}Desc: (.*)/", $row, $matches)) {
                            $elementDescription = $matches[1];
                            $i++;
                            while ($i < count($elmArr) && strlen($elmArr[$i]) > 1) {
                                if (preg_match("/^[\s]{11}(.*)/", $elmArr[$i], $matches)) {
                                    $elementDescription .= " " . $matches[1];
                                    $i++;
                                } else {
                                    break;
                                }
                            }
                            continue;
                        }

                        if ($elementType === '') {
                            $result = preg_match("/^\s{5}Repr: (a?n?)[\.]*(\d+)/", $row, $codeArr);
                            if ($result) {
                                $elementType = trim($codeArr[1]);
                                $elementMaxSize = trim($codeArr[2]);
                            } else {
                                $this->warnings[] = "Section $sectionIndex: Could not parse representation: $row";
                            }
                            $i++;
                            continue;
                        }

                        if (preg_match("/(.{5})(.{5})\s(.*)/", $row, $matches)) {
                            $valueChange = trim($matches[1]);
                            $valueValue = trim($matches[2]);
                            $valueTitle = $matches[3];
                            $valueDescription = '';
                            $i++;

                            if ($valueValue == "") {
                                continue;
                            }

                            while ($i < count($elmArr) && strlen($elmArr[$i]) > 1) {
                                if (preg_match("/^[\s]{14}(.*)/", $elmArr[$i], $matches)) {
                                    if ($valueDescription != '') {
                                        $valueDescription .= " ";
                                    }
                                    $valueDescription .= $matches[1];
                                    $i++;
                                } else if (preg_match("/^[\s]{11}(.*)/", $elmArr[$i], $matches)) {
                                    if (trim($matches[1]) == "Note:") {
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
                                'value' => $valueValue,
                                'title' => $valueTitle,
                                'descr' => $valueDescription
                            ];
                            continue;
                        }
                        $i++;
                    }

                    // Validate parsed data
                    if (empty($elementCode)) {
                        $this->warnings[] = "Section $sectionIndex: No element code found, skipping section";
                        continue;
                    }

                    if (empty($elementValues)) {
                        $this->warnings[] = "Section $sectionIndex: No code values found for element $elementCode";
                    }

                    $defXML->addAttribute('id', $elementCode);

                    foreach ($elementValues as $codes) {
                        try {
                            $cdefXML = $defXML->addChild('code');
                            $cdefXML->addAttribute('id', $this->safeEncode($codes['value']));
                            $cdefXML->addAttribute('title', $this->safeEncode($codes['title']));
                            $cdefXML->addAttribute('desc', $this->safeEncode($codes['descr']));
                        } catch (Exception $e) {
                            $this->errors[] = "Section $sectionIndex: Error adding code for element $elementCode: " . $e->getMessage();
                        }
                    }

                    $processedElements++;

                } catch (Exception $e) {
                    $this->errors[] = "Section $sectionIndex: Error processing section: " . $e->getMessage();
                }
            }

            if ($processedElements === 0) {
                throw new Exception("No valid data elements were processed from the file");
            }

            $this->logSummary($processedElements);

        } catch (Exception $e) {
            $this->errors[] = "Process error: " . $e->getMessage();
            throw $e;
        }
    }

    private function safeEncode($text)
    {
        if (empty($text)) {
            return '';
        }

        // Try multiple encoding approaches
        if (function_exists('mb_convert_encoding')) {
            $encoded = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
            if ($encoded !== false) {
                return $encoded;
            }
        }

        // Last resort: return as-is and hope for the best
        return $text;
    }

    private function logSummary($processedElements)
    {
        $summary = "Processed $processedElements data elements successfully";
        if (!empty($this->warnings)) {
            $summary .= ", with " . count($this->warnings) . " warnings";
        }
        if (!empty($this->errors)) {
            $summary .= ", with " . count($this->errors) . " errors";
        }

        echo $summary . "\n";

        if (!empty($this->warnings)) {
            echo "Warnings:\n";
            foreach ($this->warnings as $warning) {
                echo "  - $warning\n";
            }
        }

        if (!empty($this->errors)) {
            echo "Errors:\n";
            foreach ($this->errors as $error) {
                echo "  - $error\n";
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
