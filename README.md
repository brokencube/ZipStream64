# *DEPRECATED*

The enhancements in this fork of https://github.com/maennchen/ZipStream-PHP have now been brought back into that project as of v1.0.0 - However, if you need PHP 5.6 / 7.0 (Which you shouldn't - those PHP versions are about to die!), this repo is still available.

------

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
use brokencube\ZipStream\ZipStream;

# Autoload the dependencies
require 'vendor/autoload.php';

# create a new zipstream object
$zip = new ZipStream('example.zip');

# create a file named 'hello.txt' 
$zip->addFile('hello.txt', 'This is the contents of hello.txt');

# add a file named 'image.jpg' from a local file 'path/to/image.jpg'
$zip->addFileFromPath('some_image.jpg', 'path/to/image.jpg');

# add a file named 'goodbye.txt' from an open stream resource
$fp = tmpfile();
fwrite($fp, 'The quick brown fox jumped over the lazy dog.');
$zip->addFileFromStream('goodbye.txt', $fp);
fclose($fp);

# add a file named 'farewell.txt' from a PSR7 stream (e.g. Guzzle / AWS)
$amazonS3 = new Sdk()->createS3();
$file = $amazonS3->getObject(['Bucket' => 'bucket.name', 'Key' => 'path/to/farewell.txt']);
$zip->addFileFromPsr7Stream('farewell.txt', $file['Body']);

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

## Some Caveats

64bit Zips don't work properly with macOS's default Zip library (i.e. used by the built-in unarchive). If you need >4GB Zips on mac, your users will need to use 3rd party software to unzip them. If your zip is going to be smaller, you can turn off 64bit support by calling:
```php
$zip = new ZipStream('example.zip', [ZipStream::OPTION_USE_ZIP64 => false]);
```
