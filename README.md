# ZipStream64
Streaming Zips with 64bit large file support

Please see the file LICENSE.md for licensing and warranty information (Standard MIT Licence).

## Installation
Easiest installation is via Composer:

```json
  "require": {
      "brokencube/zipstream64": "0.1.*"
  }
```

## Overview
A fast and simple streaming zip file downloader for PHP.  Here's a
simple example:
```php
# Autoload the dependencies
require 'vendor/autoload.php';

# create a new zipstream object
$zip = new ZipStream\ZipStream('example.zip');

# create a file named 'hello.txt' 
$zip->addFile('hello.txt', 'This is the contents of hello.txt');

# add a file named 'image.jpg' from a local file 'path/to/image.jpg'
$zip->addFileFromPath('some_image.jpg', 'path/to/image.jpg');

# add a file named 'goodbye.txt' from an open stream resource
$fp = tmpfile();
fwrite($fp, 'The quick brown fox jumped over the lazy dog.');
$zip->addFileFromStream('goodbye.txt', $fp);
fclose($fp);

# finish the zip stream
$zip->finish();
```

## Requirements

  * 64-bit PHP version 5.6 or newer.

## Contributors
This project leans *very* heavily on previous work of the following projects:

  * https://github.com/maennchen/ZipStream-PHP
  * https://github.com/barracudanetworks/ArchiveStream-php

95% of kudos for this project goes to them!
