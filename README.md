# untdid-parser
Parse and convert UN/EDIFACT Directories to XML. UNTDID stands for "United Nations Trade Data Interchange Directory".

Example:

```php
$p = new EDMDParser('D00A/EDMD/CODECO_D.00A');
echo $p->getXML();
```

The runner.php automates data extraction for all documents in a single zipped directory on a best effort basis:

```
php runner.php 99B
```

```
for i in {97..99}; do
php runner.php ${i}A; 
php runner.php ${i}B; 
done
for i in {0..9}; do
php runner.php 0${i}A; 
php runner.php 0${i}B; 
done
php runner.php 01C; 
for i in {10..22}; do
php runner.php ${i}A; 
php runner.php ${i}B; 
done
```


Supported documents:
* Message type directory Batch (EDMD)
* Segment directory Batch (EDSD)
* Composite data element directory Batch (EDCD)
* Data element directory (EDED)
* Code list (UNCL)
* Service codes (UNSL for v3, SL for v4)

The Directories are released on the UNECE website: https://unece.org/trade/uncefact/unedifact/download

The service codes instead are released on the ISO Joint Working Group website: http://www.gefeg.com/jswg/
