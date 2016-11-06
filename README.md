# untdid-parser
Parse and convert UN/EDIFACT Directories to XML. UNTDID stands for "United Nations Trade Data Interchange Directory".

Example:

```php
$p = new EDMDParser('D00A/EDMD/CODECO_D.00A');
echo $p->getXML();
```

Supported documents:
* Message type directory Batch (EDMD)
* Segment directory Batch (EDSD)
* Composite data element directory Batch (EDCD)
* Data element directory (EDED)
* Code list (UNCL)
* Service codes (UNSL for v3, SL for v4)

The Directories are released on the UNECE website: https://www.unece.org/cefact/edifact/welcome.html

The service codes instead are released on the ISO Joint Working Group website: http://www.gefeg.com/jswg/
