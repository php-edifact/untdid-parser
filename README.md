# untdid-parser
Parse and convert to XML UN/EDIFACT Directories.

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
