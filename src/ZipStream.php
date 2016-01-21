<?php
namespace brokencube\ZipStream;
use brokencube\ZipStream\Exception as Ex;

/**
 * ZipStream
 * 
 * Streamed, dynamically generated zip archives.
 *
 * @author Paul Duncan <pabs@pablotron.org>
 * @copyright Copyright (C) 2007-2009 Paul Duncan <pabs@pablotron.org>
 * 
 * @author Jonatan Männchen <jonatan@maennchen.ch>
 * @copyright Copyright (C) 2014 Jonatan Männchen <jonatan@maennchen.ch>
 * 
 * @author Jesse Donat <donatj@gmail.com>
 * @copyright Copyright (C) 2014 Jesse Donat <donatj@gmail.com>
 *
 * @author Nik Barham <github@brokencube.co.uk>
 * @copyright Copyright (C) 2016 Nik Barham <github@brokencube.co.uk>
 * 
 * @license https://raw.githubusercontent.com/brokencube/ZipStream64/master/LICENCE
 * 
 *
 * Requirements:
 *
 * * PHP version 5.6 or newer.
 * * PHP 64-bit
 *
 * Usage:
 *
 * Streaming zip archives is a simple, three-step process:
 *
 * 1.  Create the zip stream:
 *
 *     $zip = new ZipStream('example.zip');
 *
 * 2.  Add one or more files to the archive:
 *
 *      * add first file
 *     $data = file_get_contents('some_file.gif');
 *     $zip->addFile('some_file.gif', $data);
 *
 *      * add second file
 *     $data = file_get_contents('some_file.gif');
 *     $zip->addFile('another_file.png', $data);
 *
 * 3.  Finish the zip stream:
 *
 *     $zip->finish();
 *
 * You can also add an archive comment, add comments to individual files,
 * and adjust the timestamp of files.  See the API documentation for each
 * method below for additional information.
 *
 * Example:
 *
 *   // create a new zip stream object
 *   $zip = new ZipStream('some_files.zip');
 *
 *   // list of local files
 *   $files = array('foo.txt', 'bar.jpg');
 *
 *   // read and add each file to the archive
 *   foreach ($files as $path)
 *     $zip->addFile($path, file_get_contents($path));
 * 
 *   // write archive footer to stream
 *   $zip->finish();
 */
class ZipStream {
	const VERSION = '0.1.0';
	const ZIP_VERSION = 0x002D;

	const METHOD_STORE = 0x00;
	const METHOD_DEFLATE = 0x08;
	
	const FILE_HEADER_SIGNATURE = 0x04034b50;
	const CDR_FILE_SIGNATURE = 0x02014b50;
	const CDR_EOF_SIGNATURE = 0x06054b50;
	const DATA_DESCRIPTOR_SIGNATURE = 0x08074b50;
	const ZIP64_CDR_EOF_SIGNATURE = 0x06064b50;
	const ZIP64_CDR_LOCATOR_SIGNATURE = 0x07064b50;

	const OPTION_LARGE_FILE_SIZE      = 'large_file_size';
	const OPTION_LARGE_FILE_METHOD    = 'large_file_method';
	const OPTION_SEND_HTTP_HEADERS    = 'send_http_headers';
	const OPTION_HTTP_HEADER_CALLBACK = 'http_header_callback';
	const OPTION_OUTPUT_STREAM        = 'output_stream';
	const OPTION_CONTENT_TYPE         = 'content_type';
	const OPTION_CONTENT_DISPOSITION  = 'content_disposition';
	
	/**
	 * Global Options
	 *
	 * @var array
	*/
	public $opt = array();
	
	/**
	 * @var array
	 */
	public $files = array();
	
	/**
	 * @var integer
	 */
	public $cdr_ofs = 0;
	
	/**
	 * @var integer
	 */
	public $ofs = 0;

	/**
	 * @var bool
	 */
	protected $need_headers;

	/**
	 * @var null|String
	 */
	protected $output_name;
	
	/**
	 * Create a new ZipStream object.
	 *
	 * Parameters:
	 *
	 * @param String $name - Name of output file (optional).
	 * @param array $opt  - Hash of archive options (optional, see "Archive Options"
	 *           below).
	 *
	 * Archive Options:
	 *
	 *   comment             - Comment for this archive.
	 *   content_type        - HTTP Content-Type.  Defaults to 'application/x-zip'.
	 *   content_disposition - HTTP Content-Disposition.  Defaults to 
	 *                         'attachment; filename=\"FILENAME\"', where
	 *                         FILENAME is the specified filename.
	 *   large_file_size     - Size, in bytes, of the largest file to try
	 *                         and load into memory (used by
	 *                         addFileFromPath()).  Large files may also
	 *                         be compressed differently; see the
	 *                         'large_file_method' option.
	 *   large_file_method   - How to handle large files.  Legal values are
	 *                         'store' (the default), or 'deflate'.  Store
	 *                         sends the file raw and is significantly
	 *                         faster, while 'deflate' compresses the file
	 *                         and is much, much slower.  Note that deflate
	 *                         must compress the file twice and extremely
	 *                         slow.
	 *   sendHttpHeaders   - Boolean indicating whether or not to send
	 *                         the HTTP headers for this file.
	 *
	 * Note that content_type and content_disposition do nothing if you are
	 * not sending HTTP headers.
	 *
	 * Large File Support:
	 *
	 * By default, the method addFileFromPath() will send send files
	 * larger than 20 megabytes along raw rather than attempting to
	 * compress them.  You can change both the maximum size and the
	 * compression behavior using the large_file_* options above, with the
	 * following caveats:
	 *
	 * * For "small" files (e.g. files smaller than large_file_size), the
	 *   memory use can be up to twice that of the actual file.  In other
	 *   words, adding a 10 megabyte file to the archive could potentially
	 *   occupty 20 megabytes of memory.
	 *
	 * * Enabling compression on large files (e.g. files larger than
	 *   large_file_size) is extremely slow, because ZipStream has to pass
	 *   over the large file once to calculate header information, and then
	 *   again to compress and send the actual data.
	 *
	 * Examples:
	 *
	 *   // create a new zip file named 'foo.zip'
	 *   $zip = new ZipStream('foo.zip');
	 *
	 *   // create a new zip file named 'bar.zip' with a comment
	 *   $zip = new ZipStream('bar.zip', array(
	 *     'comment' => 'this is a comment for the zip file.',
	 *   ));
	 *
	 * Notes:
	 *
	 * If you do not set a filename, then this library _DOES NOT_ send HTTP
	 * headers by default.  This behavior is to allow software to send its
	 * own headers (including the filename), and still use this library.
	 */
	public function __construct($name = null, $opt = array()) {

		$defaults = array(
			// set large file defaults: size = 20 megabytes
			self::OPTION_LARGE_FILE_SIZE      => 20 * 1024 * 1024,
			self::OPTION_LARGE_FILE_METHOD    => static::METHOD_STORE,
			self::OPTION_SEND_HTTP_HEADERS    => false,
			self::OPTION_HTTP_HEADER_CALLBACK => 'header',
		);
		
		// merge and save options
		$this->opt = array_merge($defaults, $opt);
		
		if (!isset($this->opt[self::OPTION_OUTPUT_STREAM])) {
			$this->opt[self::OPTION_OUTPUT_STREAM] = fopen('php://output', 'w');
		}
		
		$this->output_name  = $name;
		$this->need_headers = $name || $this->opt[self::OPTION_SEND_HTTP_HEADERS];
	}
	
	/**
	 * addFile
	 * 
	 * add a file to the archive
	 *   
	 *  @param String $name - path of file in archive (including directory).
	 *  @param String $data - contents of file
	 *  @param array $opt  - Hash of options for file (optional, see "File Options"
	 *          below).  
	 *
	 * File Options: 
	 *  time     - Last-modified timestamp (seconds since the epoch) of
	 *             this file.  Defaults to the current time.
	 *  comment  - Comment related to this file.
	 *
	 * Examples:
	 *
	 *   // add a file named 'foo.txt'
	 *   $data = file_get_contents('foo.txt');
	 *   $zip->addFile('foo.txt', $data);
	 * 
	 *   // add a file named 'bar.jpg' with a comment and a last-modified
	 *   // time of two hours ago
	 *   $data = file_get_contents('bar.jpg');
	 *   $zip->addFile('bar.jpg', $data, array(
	 *     'time'    => time() - 2 * 3600,
	 *     'comment' => 'this is a comment about bar.jpg',
	 *   ));
	 */
	public function addFile($name, $data, $opt = array()) {
		// compress data
		$zdata = gzdeflate($data);
		
		// calculate header attributes
		$crc  = crc32($data);
		$zlen = strlen($zdata);
		$len  = strlen($data);
		$meth = static::METHOD_DEFLATE;
		
		// send file header
		$this->addFileHeader($name, $opt, $meth, $crc, $zlen, $len);
		
		// print data
		$this->send($zdata);
		
		// send file footer
		$this->addFileFooter($crc, $zlen, $len);		
	}
	
	/**
	 * addFileFromPath
	 * 
	 * add a file at path to the archive.
	 *
	 * Note that large files may be compresed differently than smaller
	 * files; see the "Large File Support" section above for more
	 * information.
	 *   
	 *  @param String $name - name of file in archive (including directory path).
	 *  @param String $path - path to file on disk (note: paths should be encoded using
	 *          UNIX-style forward slashes -- e.g '/path/to/some/file').
	 *  @param array $opt  - Hash of options for file (optional, see "File Options"
	 *          below).  
	 *
	 * File Options: 
	 *  time     - Last-modified timestamp (seconds since the epoch) of
	 *             this file.  Defaults to the current time.
	 *  comment  - Comment related to this file.
	 *
	 * Examples:
	 *
	 *   // add a file named 'foo.txt' from the local file '/tmp/foo.txt'
	 *   $zip->addFileFromPath('foo.txt', '/tmp/foo.txt');
	 * 
	 *   // add a file named 'bigfile.rar' from the local file
	 *   // '/usr/share/bigfile.rar' with a comment and a last-modified
	 *   // time of two hours ago
	 *   $path = '/usr/share/bigfile.rar';
	 *   $zip->addFileFromPath('bigfile.rar', $path, array(
	 *     'time'    => time() - 2 * 3600,
	 *     'comment' => 'this is a comment about bar.jpg',
	 *   ));
	 * 
	 * @return void
	 * @throws \ZipStream\Exception\FileNotFoundException
	 * @throws \ZipStream\Exception\FileNotReadableException
	 */
	public function addFileFromPath($name, $path, $opt = array()) {
		if(!is_readable($path)) {
			if(!file_exists($path)) {
				throw new Ex\FileNotFoundException($path);
			}
			throw new Ex\FileNotReadableException($path);
		}
		if ($this->isLargeFile($path)) {
			// file is too large to be read into memory; add progressively
			$this->addLargeFile($name, $path, $opt);
		} else {
			// file is small enough to read into memory; read file contents and
			// handle with addFile()
			$data = file_get_contents($path);
			$this->addFile($name, $data, $opt);
		}
	}
	
	/**
	 * addFile_from_stream
	 * 
	 * dds an open stream to the archive uncompressed
	 *
	 * @param String $name - path of file in archive (including directory).
	 * @param Resource $stream - contents of file as a stream resource
	 * @param array $opt - Hash of options for file (optional, see "File Options" below).
	 * 
	 * File Options:
	 *  time     - Last-modified timestamp (seconds since the epoch) of
	 *             this file.  Defaults to the current time.
	 *  comment  - Comment related to this file.
	 *
	 * Examples:
	 *
	 *   // create a temporary file stream and write text to it
	 *   $fp = tmpfile();
	 *   fwrite($fp, 'The quick brown fox jumped over the lazy dog.');
	 *
	 *   // add a file named 'streamfile.txt' from the content of the stream
	 *   $x->addFile_from_stream('streamfile.txt', $fp);
	 *
	 * @return void
	 */
	public function addFileFromStream($name, $stream, $opt = array()) {
		$block_size = 1048576; // process in 1 megabyte chunks
		$algo       = 'crc32b';
		$meth       = static::METHOD_STORE;
		
		// calculate header attributes
		fseek($stream, 0, SEEK_END);
		$zlen = $len = ftell($stream);
		
		rewind($stream);
		$hash_ctx = hash_init($algo);
		hash_update_stream($hash_ctx, $stream);

		$crc = hexdec(hash_final($hash_ctx));
		
		// send file header
		$this->addFileHeader($name, $opt, $meth, $crc, $zlen, $len);
		
		rewind($stream);
		while (!feof($stream)) {
			$data = fread($stream, $block_size);
			// send data
			$this->send($data);
		}
		
		// send file footer
		$this->addFileFooter($crc, $zlen, $len);		
	}

	/**
	 * addFile_from_Psr7Stream
	 * 
	 * dds an open stream to the archive uncompressed
	 *
	 * @param String $name - path of file in archive (including directory).
	 * @param Resource $stream - contents of file as a stream resource
	 * @param array $opt - Hash of options for file (optional, see "File Options" below).
	 * 
	 * File Options:
	 *  time     - Last-modified timestamp (seconds since the epoch) of
	 *             this file.  Defaults to the current time.
	 *  comment  - Comment related to this file.
	 *
	 * Examples:
	 *
	 *   // create a temporary file stream and write text to it
	 *   $fp = tmpfile();
	 *   fwrite($fp, 'The quick brown fox jumped over the lazy dog.');
	 *
	 *   // add a file named 'streamfile.txt' from the content of the stream
	 *   $x->addFile_from_stream('streamfile.txt', $fp);
	 *
	 * @return void
	 */
	public function addFileFromPsr7Stream($name, \Psr\Http\Message\StreamInterface $stream, $opt = array()) {
		$block_size = 1048576; // process in 1 megabyte chunks
		$algo       = 'crc32b';
		$meth       = static::METHOD_STORE;
		
		// calculate header attributes
		$stream->seek(0, SEEK_END);
		$zlen = $len = $stream->tell();
		
		$stream->rewind();
		$hash_ctx = hash_init($algo);
		while (!$stream->eof()) {
			$data = $stream->read($block_size);
            hash_update($hash_ctx, $data);
		}
		$crc = hexdec(hash_final($hash_ctx));
		
		// send file header
		$this->addFileHeader($name, $opt, $meth, $crc, $zlen, $len);
		
		$stream->rewind();
		while (!$stream->eof()) {
			$data = $stream->read($block_size);
			// send data
			$this->send($data);
		}
		
		// send file footer
		$this->addFileFooter($crc, $zlen, $len);		
	}
	
	/**
	 * finish
	 * 
	 * Write zip footer to stream.
	 * 
	 *  Example:
	 *
	 *   // add a list of files to the archive
	 *   $files = array('foo.txt', 'bar.jpg');
	 *   foreach ($files as $path)
	 *     $zip->addFile($path, file_get_contents($path));
	 * 
	 *   // write footer to stream
	 *   $zip->finish();
	 * 
	 * @return void
	 */
	public function finish() {
		// add trailing cdr file records
		foreach ($this->files as $file) $this->addCdrFile($file);
		$this->addCdr64Eof($this->opt);
		$this->addCdr64Locator($this->opt);
		// add trailing cdr eof record
		$this->addCdrEof($this->opt);
		// The End
		$this->clear();
	}

	/**
	 * Create and send zip header for this file.
	 *
	 * @param String  $name
	 * @param Array   $opt
	 * @param Integer $meth
	 * @param string  $crc
	 * @param Integer $zlen
	 * @param Integer $len
	 * @return void
	 */
	protected function addFileHeader($name, $opt, $meth, $crc, $zlen, $len) {
		// strip leading slashes from file name
		// (fixes bug in windows archive viewer)
		$name = preg_replace('/^\\/+/', '', $name);
		
		// calculate name length
		$nlen = strlen($name);
		$flen = 24; // Data Descriptor is 24 bytes long for ZIP64;
		
		// create dos timestamp
		$opt['time'] = isset($opt['time']) && !empty($opt['time']) ? $opt['time'] : time();
		$time         = $this->dostime($opt['time']);
		
		// build file header
		$fields = [
			// Header
			['V', static::FILE_HEADER_SIGNATURE],
			['v', static::ZIP_VERSION],				// Version needed to Extract
			['v', 0b00001000],						// General purpose bit flags - data descriptor flag set
			['v', $meth],							// Compression method
			['V', $time],							// Timestamp (DOS Format)
			['V', 0x0000],							// CRC32 of data (0 -> moved to data descriptor footer)
			['V', 0xFFFFFFFF],						// Length of compressed data (Forced to 0xFFFFFFFF for 64bit extension)
			['V', 0xFFFFFFFF],						// Length of original data (Forced to 0xFFFFFFFF for 64bit extension)
			['v', $nlen],							// Length of filename
			['v', 32],								// Extra data (32 bytes)
		];
		
		$fields64 = [
			['v', 0x0001],							// 64Bit Extension
			['v', 28],								// 28bytes of data follows 
			['P', 0x00000000],						// Length of original data (0 -> moved to data descriptor footer)
			['P', 0x00000000],						// Length of compressed data (0 -> moved to data descriptor footer)
			['P', 0x00000000],						// Relative Header Offset
			['V', 0x0000]							// Disk number
		];
		
		// pack fields and calculate "total" length
		$header = $this->packFields($fields);
		$footer = $this->packFields($fields64);
		$cdr_len = strlen($header) + strlen($footer) + $nlen + $zlen + $flen;
		
		// print header and filename
		$this->send($header . $name . $footer);
		
		// add to central directory record and increment offset
		$this->addToCdr($name, $opt, $meth, $crc, $zlen, $len, $cdr_len);
	}

	/**
	 * Create and send data descriptor footer for this file.
	 *
	 * @param string  $crc
	 * @param Integer $zlen
	 * @param Integer $len
	 * @return void
	 */
	
	protected function addFileFooter($crc, $zlen, $len)
	{
		$fields = [
			['V', static::DATA_DESCRIPTOR_SIGNATURE],
			['V', $crc],							// CRC32
			['P', $zlen],							// Length of compressed data
			['P', $len],							// Length of original data
		];
		
		$footer = $this->packFields($fields);
		
		$this->send($footer);
	}
	
	/**
	 * Add a large file from the given path.
	 *
	 * @param String $name
	 * @param String $path
	 * @param array $opt
	 * @return void
	 * @throws \ZipStream\Exception\InvalidOptionException
	 */
	protected function addLargeFile($name, $path, $opt = array()) {
		$st         = stat($path);
		$block_size = 1048576; // process in 1 megabyte chunks
		$algo       = 'crc32b';
		
		// calculate header attributes
		$zlen = $len = $st['size'];
		
		$meth = $this->opt[self::OPTION_LARGE_FILE_METHOD];
		if ($meth == static::METHOD_STORE) {
			// store method
			$crc = hexdec(hash_file($algo, $path));
		} elseif ($meth == static::METHOD_DEFLATE) {
			// deflate method
			// open file, calculate crc and compressed file length
			$fh       = fopen($path, 'rb');
			$hash_ctx = hash_init($algo);
			$zlen     = 0;
			
			// read each block, update crc and zlen
			while (!feof($fh)) {
				$data = fread($fh, $block_size);
				hash_update($hash_ctx, $data);
			}

			rewind($fh);
			$filter = stream_filter_append($fh, 'zlib.deflate', STREAM_FILTER_READ, 6);

			while (!feof($fh)) {
				$data = fread($fh, $block_size);
				$zlen += strlen($data);
			}

			stream_filter_remove($filter);
			
			// close file and finalize crc
			fclose($fh);

			$crc = hexdec(hash_final($hash_ctx));
		} else {
			throw new InvalidOptionException('large_file_method', array(static::METHOD_STORE, static::METHOD_DEFLATE), $meth);
		}
		
		// send file header
		$this->addFileHeader($name, $opt, $meth, $crc, $zlen, $len);
		
		// open input file
		$fh = fopen($path, 'rb');

		if ($meth == static::METHOD_DEFLATE) {
			$filter = stream_filter_append($fh, 'zlib.deflate', STREAM_FILTER_READ, 6);
		}
		
		// send file blocks
		while (!feof($fh)) {
			$data = fread($fh, $block_size);

			// send data
			$this->send($data);
		}

		if (isset($filter) && is_resource($filter)) {
			stream_filter_remove($filter);
		}
		
		// close input file
		fclose($fh);
		
		// send file footer
		$this->addFileFooter($crc, $zlen, $len);
	}
	
	/**
	 * Is this file larger than large_file_size?
	 *
	 * @param string $path
	 * @return Boolean
	 */
	protected function isLargeFile($path) {
		$st = stat($path);
		return ($this->opt[self::OPTION_LARGE_FILE_SIZE] > 0) && ($st['size'] > $this->opt[self::OPTION_LARGE_FILE_SIZE]);
	}
	
	/**
	 * Save file attributes for trailing CDR record.
	 *
	 * @param String  $name
	 * @param Array   $opt
	 * @param Integer $meth
	 * @param string  $crc
	 * @param Integer $zlen
	 * @param Integer $len
	 * @param Integer $rec_len
	 * @return void
	 * @return void
	 */
	private function addToCdr($name, $opt, $meth, $crc, $zlen, $len, $rec_len) {
		$this->files[] = array(
			$name,
			$opt,
			$meth,
			$crc,
			$zlen,
			$len,
			$this->ofs
		);
		$this->ofs += $rec_len;
	}
	
	/**
	 * Send CDR record for specified file.
	 * 
	 * @param array $args
	 * @return void
	 */
	protected function addCdrFile($args) {
		list($name, $opt, $meth, $crc, $zlen, $len, $offset) = $args;
		
		// get attributes
		$comment = isset($opt['comment']) && !empty($opt['comment']) ? $opt['comment'] : '';
		
		// get dos timestamp
		$time = $this->dostime($opt['time']);
		
		$fields = [
			['V', static::CDR_FILE_SIGNATURE],		// Central file header signature
			['v', static::ZIP_VERSION],				// Made by version
			['v', static::ZIP_VERSION],				// Extract by version
			['v', 0x00],							// General purpose bit flag
			['v', $meth],							// Compression method
			['V', $time],							// Timestamp (DOS Format)
			['V', $crc],							// CRC32
			['V', 0xFFFFFFFF],						// Compressed Data Length (Forced to 0xFFFFFFFF for 64bit Extension)
			['V', 0xFFFFFFFF],						// Original Data Length (Forced to 0xFFFFFFFF for 64bit Extension)
			['v', strlen($name)],					// Length of filename
			['v', 32],								// Extra data len (32bytes of 64bit Extension)
			['v', strlen($comment)],				// Length of comment
			['v', 0],								// Disk number
			['v', 0],								// Internal File Attributes
			['V', 32],								// External File Attributes
			['V', 0xFFFFFFFF]						// Relative offset of local header (Forced to 0xFFFFFFFF for 64bit Extension)
		];
		
		$fields64 = [
			['v', 0x0001],							// 64Bit Extension
			['v', 28],								// 28bytes of data follows 
			['P', $len],							// Length of original data (0 -> moved to data descriptor footer)
			['P', $zlen],							// Length of compressed data (0 -> moved to data descriptor footer)
			['P', $offset],							// Relative Header Offset
			['V', 0]								// Disk number
		];
		
		// pack fields, then append name and comment
		$header = $this->packFields($fields);
		$footer = $this->packFields($fields64);
		
		$ret = $header . $name . $comment . $footer;
		
		$this->send($ret);
		
		// increment cdr offset
		$this->cdr_ofs += strlen($ret);
	}
	
	/**
	 * Send ZIP64 CDR EOF (Central Directory Record End-of-File) record.
	 * 
	 * @param array $opt
	 * @return void
	 */
	protected function addCdr64Eof($opt = null) {
		$num     = count($this->files);
		$cdr_length = $this->cdr_ofs;
		$cdr_offset = $this->ofs;
		
		$fields = [
			['V', static::ZIP64_CDR_EOF_SIGNATURE], 	// ZIP64 end of central file header signature
			['P', 44],									// Length of data below this header (length of block - 12) = 44
			['v', static::ZIP_VERSION],					// Made by version
			['v', static::ZIP_VERSION],					// Extract by version
			['V', 0x00], 								// disk number
			['V', 0x00], 								// no of disks
			['P', $num],								// no of entries on disk
			['P', $num],								// no of entries in cdr
			['P', $cdr_length],							// CDR size
			['P', $cdr_offset],							// CDR offset
		];
		
		$ret = $this->packFields($fields);
		$this->send($ret);
	}

	/**
	 * Send ZIP64 CDR Locator (Central Directory Record Locator) record.
	 * 
	 * @param array $opt
	 * @return void
	 */
	protected function addCdr64Locator($opt = null) {
		$num     = count($this->files);
		$cdr_length = $this->cdr_ofs;
		$cdr_offset = $this->ofs;
		
		$fields = [
			['V', static::ZIP64_CDR_LOCATOR_SIGNATURE], // ZIP64 end of central file header signature
			['V', 0x00],								// Disc number containing CDR64EOF
			['P', $cdr_offset + $cdr_length],			// CDR offset
			['V', 1],									// Total number of disks
		];
		
		$ret = $this->packFields($fields);
		$this->send($ret);
	}

	/**
	 * Send CDR EOF (Central Directory Record End-of-File) record.
	 * 
	 * @param array $opt
	 * @return void
	 */
	protected function addCdrEof($opt = null) {
		$num     = count($this->files);
		$cdr_len = $this->cdr_ofs;
		$cdr_ofs = $this->ofs;
		
		// grab comment (if specified)
		$comment = '';
		if ($opt && isset($opt['comment'])) {
			$comment = $opt['comment'];
		}
		
		$fields = [
			['V', static::CDR_EOF_SIGNATURE], 	// end of central file header signature
			['v', 0x00], 						// disk number
			['v', 0x00], 						// no of disks
			['v', $num],						// no of entries on disk
			['v', $num],						// no of entries in cdr
			['V', $cdr_len],					// CDR size
			['V', $cdr_ofs],					// CDR offset
			['v', strlen($comment)],			// Zip Comment size
		];
		
		$ret = $this->packFields($fields) . $comment;
		$this->send($ret);
	}
	
	/**
	 * Add CDR (Central Directory Record) footer.
	 * 
	 * @param array $opt
	 * @return void
	 */
	protected function addCdr($opt = null) {
		foreach ($this->files as $file) $this->addCdrFile($file);
		$this->addCdrEof($opt);
	}
	
	/**
	 * Clear all internal variables.  Note that the stream object is not
	 * usable after this.
	 * 
	 * @return void
	 */
	protected function clear() {
		$this->files   = array();
		$this->ofs     = 0;
		$this->cdr_ofs = 0;
		$this->opt     = array();
	}
	
	/**
	 *  Send HTTP headers for this stream.
	 * 
	 * @return void
	 */
	protected function sendHttpHeaders() {
		// grab options
		$opt = $this->opt;
		
		// grab content type from options
		$content_type = 'application/x-zip';
		if (isset($opt[self::OPTION_CONTENT_TYPE])) {
			$content_type = $this->opt[self::OPTION_CONTENT_TYPE];
		}
		
		// grab content disposition 
		$disposition = 'attachment';
		if (isset($opt[self::OPTION_CONTENT_DISPOSITION])) {
			$disposition = $opt[self::OPTION_CONTENT_DISPOSITION];
		}
		
		if ($this->output_name) {
			$disposition .= "; filename=\"{$this->output_name}\"";
		}
		
		$headers = array(
			'Content-Type' => $content_type,
			'Content-Disposition' => $disposition,
			'Pragma' => 'public',
			'Cache-Control' => 'public, must-revalidate',
			'Content-Transfer-Encoding' => 'binary'
		);
		
		$call = $this->opt[self::OPTION_HTTP_HEADER_CALLBACK];
		foreach ($headers as $key => $val)
			$call("$key: $val");
	}
	
	/**
	 * Send string, sending HTTP headers if necessary.
	 * 
	 * @param String $str
	 * @return void
	 */
	protected function send($str) {
		if ($this->need_headers) {
			$this->sendHttpHeaders();
		}
		$this->need_headers = false;
		
		fwrite($this->opt[self::OPTION_OUTPUT_STREAM], $str);
	}
	
	/**
	 * Convert a UNIX timestamp to a DOS timestamp.
	 * 
	 * @param Integer $when
	 * @return Integer DOS Timestamp
	 */
	protected final function dostime($when) {
		// get date array for timestamp
		$d = getdate($when);
		
		// set lower-bound on dates
		if ($d['year'] < 1980) {
			$d = array(
				'year' => 1980,
				'mon' => 1,
				'mday' => 1,
				'hours' => 0,
				'minutes' => 0,
				'seconds' => 0
			);
		}
		
		// remove extra years from 1980
		$d['year'] -= 1980;
		
		// return date string
		return ($d['year'] << 25) | ($d['mon'] << 21) | ($d['mday'] << 16) | ($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1);
	}
	
	/**
	 * Create a format string and argument list for pack(), then call
	 * pack() and return the result.
	 * 
	 * @param array $fields
	 * @return string
	 */
	protected function packFields($fields) {
		$fmt = '';
		$args = [];
		
		// populate format string and argument list
		foreach ($fields as $field) {
			$fmt .= $field[0];
			$args[] = $field[1];
		}
		
		// prepend format string to argument list
		array_unshift($args, $fmt);
		
		// build output string from header and compressed data
		return call_user_func_array('pack', $args);
	}
}
